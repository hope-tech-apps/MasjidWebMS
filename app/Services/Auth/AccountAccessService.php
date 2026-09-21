<?php

namespace App\Services\Auth;

use App\Mail\AccountAccessMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * The one place that mints an account-access link and sends it.
 *
 * Two occasions, two brokers (config/auth.php): somebody who forgot their
 * password gets a 60-minute token from `users`; a new staff account being
 * invited gets a 7-day one from `invites`, in its own table. They stay in one
 * class so the two paths cannot drift apart in anything BUT lifetime — same
 * fragment-only URL, same single use, same session wipe on success.
 *
 * ## Why an invite exists at all
 *
 * Staff accounts used to be created with a password typed by whoever was
 * creating them, and there was no recovery of any kind — no reset route, and
 * not even the `password_reset_tokens` table the framework was configured
 * against. That works while the only admins are the people building the
 * platform. It does not survive handing an organisation its own portal: a
 * school's staff must choose their own credential, and nobody at Manara should
 * ever know it.
 */
class AccountAccessService
{
    /**
     * Send a reset link, and say NOTHING about whether the address is real.
     *
     * The caller always reports the same thing to the browser. An endpoint that
     * answers "no such user" is an account-enumeration oracle, and this one is
     * unauthenticated by necessity.
     */
    public function sendResetLink(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return;
        }

        $this->send($user, AccountAccessMail::MODE_RESET);
    }

    /**
     * Invite a user to set their own password for the first time.
     *
     * Returns false when the account has no address to send to, so the caller
     * can say so rather than reporting a delivery that never happened.
     */
    public function invite(User $user, ?string $orgName = null): bool
    {
        if (trim((string) $user->email) === '') {
            return false;
        }

        $this->send($user, AccountAccessMail::MODE_INVITE, $orgName);

        return true;
    }

    /** The link was minted by an invite (7 days) rather than Forgot password (60 minutes). */
    public const KIND_INVITE = 'invite';

    public const KIND_RESET = 'reset';

    /**
     * Broker status string — Password::PASSWORD_RESET on success.
     *
     * `$kind` names the table the token must be found in, and nothing is tried
     * after it: an invite token is not looked for among resets or the other way
     * round. Each table only ever holds its own kind, so a reset token presented
     * as an invite simply does not exist there — it cannot borrow seven days.
     */
    public function reset(array $credentials, string $kind = self::KIND_RESET): string
    {
        return self::broker($kind)->reset($credentials, function (User $user, string $password) use ($kind) {
            $user->forceFill(['password' => $password])->save();

            // The link just used is consumed by the broker; the OTHER kind is not,
            // and a password that is now set must not leave a second working link
            // in an inbox — an unopened invite behind a completed reset, or the
            // reverse.
            self::broker($kind === self::KIND_INVITE ? self::KIND_RESET : self::KIND_INVITE)->deleteToken($user);

            // Every existing session dies with the old password. A reset is
            // often a response to a compromise, and leaving live tokens behind
            // would make it a reset in name only.
            $user->tokens()->delete();
        });
    }

    /** The broker for a kind of link. Anything unrecognised is a reset, never an invite. */
    private static function broker(string $kind): \Illuminate\Contracts\Auth\PasswordBroker
    {
        return Password::broker($kind === self::KIND_INVITE ? 'invites' : 'users');
    }

    private function send(User $user, string $mode, ?string $orgName = null): void
    {
        $kind = $mode === AccountAccessMail::MODE_INVITE ? self::KIND_INVITE : self::KIND_RESET;
        $token = self::broker($kind)->createToken($user);

        // ONE live link per person, across both tables. Minting a new token in a
        // table replaces that table's old one, but invites and resets now live
        // apart, so without this a re-sent invite would leave the earlier Forgot
        // password link working — and every invite sent before invites had their
        // own table sits in the RESET table, where only this clears it. "They
        // never got it, send it again" must not leave two working links, one of
        // them in whatever inbox lost the first.
        self::broker($kind === self::KIND_INVITE ? self::KIND_RESET : self::KIND_INVITE)->deleteToken($user);

        // THE CREDENTIAL GOES IN THE FRAGMENT, NOT THE QUERY STRING.
        //
        // A fragment is never transmitted to the server. It is not in the
        // request line, so nginx cannot log it; it is not in `Referer`, so the
        // next site the user visits cannot read it; and it does not reach any
        // proxy, CDN or WAF in between.
        //
        // As a query string this token WAS being logged — measured on this
        // production host, in the rotated nginx access logs, alongside the
        // account's email address. `combined` logs the full request line, so
        // anyone with log access (or a log shipper, or a backup of one) held a
        // working password-reset link for a staff account. The 60-minute expiry
        // limited the window; it did not make the log entry acceptable.
        //
        // The SPA reads `location.hash` and then scrubs it — see
        // resources/vue-app/views/auth/ResetPassword.vue.
        $url = rtrim((string) config('app.url'), '/').'/auth/reset-password#'.http_build_query(array_filter([
            'token' => $token,
            'email' => $user->email,
            // Which table to look in. Absent on a reset, which is what every link
            // minted before invites had their own table carries, so those links keep
            // working exactly as they did.
            'kind' => $kind === self::KIND_INVITE ? self::KIND_INVITE : null,
        ]));

        $expiry = (int) config('auth.passwords.'.($kind === self::KIND_INVITE ? 'invites' : 'users').'.expire', 60);

        Mail::to($user->email)->send(
            new AccountAccessMail($user, $url, $mode, $orgName, $expiry)
        );
    }
}
