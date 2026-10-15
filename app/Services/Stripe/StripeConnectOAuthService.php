<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\StripeConnectHistory;
use App\Models\StripeConnectOAuthState;
use App\Models\User;
use App\Support\SiteUrl;
use App\Support\TenantResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StripeConnectOAuthService
{
    public function __construct(private StripeConnectService $connect) {}

    public static function enabled(): bool
    {
        return (bool) config('services.stripe.connect_oauth_enabled', false)
            && filled(config('services.stripe.connect_client_id'));
    }

    public function authorize(Masjid $org, User $actor): string
    {
        $this->available($org);
        // Expired credentials are useless; prune them opportunistically without a job.
        StripeConnectOAuthState::withoutMasjidScope()->where('expires_at', '<=', now())->delete();
        $nonce = bin2hex(random_bytes(32));
        StripeConnectOAuthState::create([
            'masjid_id' => $org->id, 'actor_user_id' => $actor->id,
            'nonce_hash' => hash('sha256', $nonce), 'expires_at' => now()->addMinutes(10),
        ]);
        $state = Crypt::encryptString(json_encode(['nonce' => $nonce, 'org' => $org->id, 'user' => $actor->id], JSON_THROW_ON_ERROR));

        return 'https://connect.stripe.com/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('services.stripe.connect_client_id'),
            'scope' => 'read_write', 'state' => $state,
            'redirect_uri' => SiteUrl::route('connect.oauth.callback'),
        ]);
    }

    /** The browser holds no SPA token. The authenticated encrypted state is its credential. */
    public function consume(string $value, ?User $sessionUser): StripeConnectOAuthState
    {
        try {
            $payload = json_decode(Crypt::decryptString($value), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \DomainException('This Stripe link is invalid or expired. Start again from Online payments.');
        }
        if (! is_array($payload) || ! is_string($payload['nonce'] ?? null)
            || ! is_int($payload['org'] ?? null) || ! is_int($payload['user'] ?? null)
            || ($sessionUser && $sessionUser->id !== $payload['user'])) {
            throw new \DomainException('This Stripe link does not belong to the signed-in staff user. Start again from Online payments.');
        }
        // DELETE is the atomic single-use claim, committed BEFORE any Stripe request.
        $state = StripeConnectOAuthState::withoutMasjidScope()
            ->where('nonce_hash', hash('sha256', $payload['nonce']))
            ->where('masjid_id', $payload['org'])->where('actor_user_id', $payload['user'])
            ->where('expires_at', '>', now())->first();
        if (! $state || ! StripeConnectOAuthState::withoutMasjidScope()->whereKey($state->id)->delete()) {
            throw new \DomainException('This Stripe link is invalid, expired or already used. Start again from Online payments.');
        }
        $actor = User::find($state->actor_user_id);
        $org = Masjid::find($state->masjid_id);
        if (! $actor || ! $org || ! $org->crm_enabled
            || ! in_array($actor->type, ['SuperAdmin', 'MasjidAdmin'], true)
            || ! $actor->can('manage donations')
            || ($actor->type !== 'SuperAdmin' && app(TenantResolver::class)->resolve($actor, $org->id)->isDenied())) {
            throw new \DomainException('Your access to these payment settings has changed. Ask an administrator to start again.');
        }
        return $state;
    }

    public function link(StripeConnectOAuthState $state, string $code): void
    {
        $result = $this->post('token', ['grant_type' => 'authorization_code', 'code' => $code]);
        $account = $result['stripe_user_id'] ?? null;
        if (! is_string($account) || ! preg_match('/^acct_[A-Za-z0-9_]+$/D', $account)) {
            throw new \DomainException('Stripe did not return an account. Start again from Online payments.');
        }
        try {
            $details = $this->connect->readAccountStatus($account);
            if (($details['id'] ?? null) !== $account) {
                throw new \DomainException('Stripe did not confirm the account details. Start again from Online payments.');
            }
            // The oldest surviving tenant root is a shared mutex for OAuth claims.
            // New roots have larger IDs; deletion of this root makes a waiting
            // claim fail closed. No remote request runs while these locks are held.
            $mutexId = Masjid::withTrashed()->min('id');
            DB::transaction(function () use ($state, $account, $details, $mutexId) {
                // Locking reads MUST be first: the later duplicate check then gets
                // a fresh InnoDB read view after the previous claim has committed.
                if ($mutexId === null || ! Masjid::withTrashed()->whereKey($mutexId)->lockForUpdate()->first()) {
                    throw new \DomainException('The payment settings changed. Start again from Online payments.');
                }
                $org = Masjid::whereKey($state->masjid_id)->lockForUpdate()->first();
                if (! $org) {
                    throw new \DomainException('This organisation is no longer available.');
                }
                $this->available($org);
                if (Masjid::withTrashed()->where('stripe_account_id', $account)->where('id', '<>', $org->id)->exists()) {
                    throw new \DomainException('This Stripe account is already linked to another organisation. Ask the office to check its payment settings.');
                }
                $org->forceFill(['stripe_account_id' => $account, 'stripe_charges_enabled' => false,
                    'stripe_payouts_enabled' => false, 'stripe_deauthorized_at' => null])->save();
                $this->connect->syncAccountStatus($details);
                // Public callback is unbound. Explicit bypass prevents any stale request
                // context stamping a different tenant onto this server-derived history.
                StripeConnectHistory::withoutMasjidScope()->insert([
                    'masjid_id' => $org->id, 'actor_user_id' => $state->actor_user_id,
                    'account_last4' => substr($account, -4), 'action' => 'link',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // OAuth has already granted access. Release that grant ONLY when no organisation
            // holds this account: the platform has one grant per account, so deauthorizing an
            // account another (or this) organisation already uses would cut its working link.
            if (! Masjid::withTrashed()->where('stripe_account_id', $account)->exists()) $this->deauthorize($account);
            if ($e instanceof \DomainException) throw $e;
            throw new \DomainException('The Stripe account could not be saved. Start again from Online payments.');
        }
    }

    public function unlink(Masjid $org, User $actor): void
    {
        DB::transaction(function () use ($org, $actor) {
            $org = Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (! $org->stripe_account_id) {
                throw new \DomainException('There is no Stripe account to unlink.');
            }
            $account = $org->stripe_account_id;
            // Only an account this door linked may be unlinked here. Deauthorizing an account the
            // older create-new onboarding connected would revoke the platform's access to it and
            // stop that organisation's card payments.
            if (! self::linkedHere($org)) {
                throw new \DomainException('This Stripe account was connected through Stripe onboarding, not linked here, so it cannot be unlinked from this screen. Contact Manara support to change it.');
            }
            $this->deauthorize($account);
            $this->clear($org, $account, 'unlink', $actor->id);
        });
    }

    public function clear(Masjid $org, string $account, string $action, ?int $actor = null): void
    {
        $org->forceFill(['stripe_account_id' => null, 'stripe_charges_enabled' => false,
            'stripe_payouts_enabled' => false])->save();
        StripeConnectHistory::withoutMasjidScope()->insert([
            'masjid_id' => $org->id, 'actor_user_id' => $actor, 'account_last4' => substr($account, -4),
            'action' => $action, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The organisation's current account was linked through this OAuth door (its latest history row is that link). */
    public static function linkedHere(Masjid $org): bool
    {
        if (! $org->stripe_account_id) return false;
        $latest = StripeConnectHistory::withoutMasjidScope()->where('masjid_id', $org->id)->latest('id')->first();
        return $latest !== null && $latest->action === 'link' && $latest->account_last4 === substr((string) $org->stripe_account_id, -4);
    }

    private function available(Masjid $org): void
    {
        if ($org->stripe_account_id) throw new \DomainException('Unlink the existing Stripe account before linking another account.');
        if ($org->forms_card_via_masjid_id !== null) throw new \DomainException('A super admin must remove the form card account link before connecting this organisation’s own Stripe account.');
    }

    private function deauthorize(string $account): void
    {
        $result = $this->post('deauthorize', ['client_id' => config('services.stripe.connect_client_id'), 'stripe_user_id' => $account]);
        if (($result['stripe_user_id'] ?? null) !== $account) {
            throw new \DomainException('Stripe did not confirm the account was unlinked. Refresh the payment settings before trying again.');
        }
    }

    private function post(string $endpoint, array $body): array
    {
        try {
            $response = Http::asForm()->withBasicAuth((string) config('services.stripe.secret'), '')
                ->connectTimeout(5)->timeout(15)->post('https://connect.stripe.com/oauth/'.$endpoint, $body);
            if ($response->successful() && is_array($response->json())) return $response->json();
        } catch (\Throwable) {
            // Do not log the HTTP exception: its body can include codes or credentials.
        }
        Log::warning('Stripe OAuth request failed.', ['operation' => $endpoint]);
        throw new \DomainException('Stripe could not complete this change. Please try again from Online payments.');
    }
}
