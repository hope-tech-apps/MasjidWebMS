<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use Stripe\StripeClient;

/**
 * StripeConnectService — onboards a masjid as a Stripe Connect STANDARD account
 * and keeps its capability flags in sync.
 *
 * STANDARD (not Express/Custom) means the org owns a full Stripe account and is
 * the merchant of record; the platform never holds their funds. Onboarding is
 * done through a hosted Account Link. Capability state (`charges_enabled`,
 * `payouts_enabled`) is authoritative on Stripe's side and is refreshed here —
 * primarily from the `account.updated` webhook, and opportunistically when the
 * admin returns from onboarding.
 *
 * The live Stripe calls sit in protected seams that return plain arrays so the
 * DB-touching logic (syncAccountStatus) is testable without the API.
 */
class StripeConnectService
{
    public function __construct(private StripeClient $stripe)
    {
    }

    /**
     * Ensure the masjid has a connected Standard account, creating one if
     * needed, and persist its id. Idempotent: a masjid that already has an
     * account id is returned unchanged.
     */
    public function ensureConnectedAccount(Masjid $masjid): Masjid
    {
        if ($masjid->stripe_account_id) {
            return $masjid;
        }

        $account = $this->createAccount($masjid);

        $masjid->forceFill(['stripe_account_id' => $account['id']])->save();

        return $masjid;
    }

    /**
     * Create (if needed) the connected account and return a hosted onboarding
     * Account Link URL to redirect the org admin to.
     */
    public function createOnboardingLink(Masjid $masjid, string $refreshUrl, string $returnUrl): string
    {
        $this->ensureConnectedAccount($masjid);

        $link = $this->createAccountLink(
            (string) $masjid->stripe_account_id,
            $refreshUrl,
            $returnUrl
        );

        return (string) ($link['url'] ?? '');
    }

    /**
     * Update a masjid's capability flags from an `account.updated` webhook
     * payload (the Account object as a plain array). Returns the masjid, or
     * null if we don't have that connected account on file.
     */
    public function syncAccountStatus(array $account): ?Masjid
    {
        $accountId = $account['id'] ?? null;
        if (! $accountId) {
            return null;
        }

        // Unbound/system context: Masjid is the tenant root (no BelongsToMasjid),
        // so a plain lookup by the connected-account id is correct.
        $masjid = Masjid::where('stripe_account_id', $accountId)->first();
        if (! $masjid) {
            return null;
        }

        $masjid->forceFill([
            'stripe_charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
            'stripe_payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
        ])->save();

        return $masjid;
    }

    /**
     * Opportunistically refresh a masjid's flags straight from Stripe (used on
     * the onboarding return, so the admin sees fresh status without waiting for
     * the webhook). No-op-ish if the account can't be retrieved.
     */
    public function refreshFromStripe(Masjid $masjid): Masjid
    {
        if (! $masjid->stripe_account_id) {
            return $masjid;
        }

        $account = $this->retrieveAccount((string) $masjid->stripe_account_id);
        $this->syncAccountStatus($account);

        return $masjid->refresh();
    }

    // ---------------------------------------------------------------------
    // Stripe seams (thin wrappers; the only methods that hit the live API).
    // ---------------------------------------------------------------------

    /** @return array{id:string} */
    protected function createAccount(Masjid $masjid): array
    {
        $account = $this->stripe->accounts->create([
            'type' => 'standard',
            'email' => $masjid->email,
            'metadata' => [
                'masjid_id' => (string) $masjid->id,
            ],
        ]);

        return ['id' => $account->id];
    }

    /** @return array{url:string} */
    protected function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        $link = $this->stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return ['url' => $link->url];
    }

    /** @return array{id:string,charges_enabled:bool,payouts_enabled:bool} */
    protected function retrieveAccount(string $accountId): array
    {
        $account = $this->stripe->accounts->retrieve($accountId);

        return [
            'id' => $account->id,
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
        ];
    }
}
