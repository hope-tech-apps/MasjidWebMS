<?php

namespace App\Services\Auth;

use App\Mail\AccountAccessMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * The one place that mints an account-access link and sends it.
 *
 * Both occasions — a new staff account being invited, and somebody who forgot
 * their password — are the same expiring single-use token against the `users`
 * broker (config/auth.php:210-215, 60 minutes). Keeping them together is what
 * stops the invite path quietly growing a weaker token than the reset path.
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

    /** Broker status string — Password::PASSWORD_RESET on success. */
    public function reset(array $credentials): string
    {
        return Password::broker()->reset($credentials, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();

            // Every existing session dies with the old password. A reset is
            // often a response to a compromise, and leaving live tokens behind
            // would make it a reset in name only.
            $user->tokens()->delete();
        });
    }

    private function send(User $user, string $mode, ?string $orgName = null): void
    {
        $token = Password::broker()->createToken($user);

        $url = rtrim((string) config('app.url'), '/').'/auth/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);

        $expiry = (int) config('auth.passwords.users.expire', 60);

        Mail::to($user->email)->send(
            new AccountAccessMail($user, $url, $mode, $orgName, $expiry)
        );
    }
}
