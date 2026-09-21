<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * One email, two occasions: "here is your account, set a password" and
 * "you asked to reset your password".
 *
 * They are the same mechanism — a signed, expiring token against
 * `password_reset_tokens` — and differ only in what the recipient is being
 * told. Keeping them in one Mailable means the link, the expiry copy and the
 * did-not-expect-this footer cannot drift apart between the two paths.
 */
class AccountAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public const MODE_INVITE = 'invite';

    public const MODE_RESET = 'reset';

    /** The lifetime in words for the email ("7 days", "1 hour"), never "10080 minutes". */
    public string $expiresIn;

    public function __construct(
        public User $user,
        public string $url,
        public string $mode = self::MODE_RESET,
        public ?string $orgName = null,
        public int $expiresInMinutes = 60,
    ) {
        $this->expiresIn = self::inWords($expiresInMinutes);
    }

    /**
     * Whole days, else whole hours, else minutes — the way a person says it.
     *
     * Computed here and not in the template: a Blade `@php` block compiles onto
     * one line, so a `//` comment inside it silently swallows the assignment that
     * follows and the view dies on an undefined variable.
     */
    public static function inWords(int $minutes): string
    {
        if ($minutes >= 1440 && $minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days.' '.($days === 1 ? 'day' : 'days');
        }

        if ($minutes >= 60 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
    }

    public function build(): self
    {
        $subject = $this->mode === self::MODE_INVITE
            ? 'Set up your '.($this->orgName ?: config('app.name')).' account'
            : 'Reset your '.config('app.name').' password';

        // The SENDER NAME follows the school, not a single hard-coded tenant.
        // Without this, every school's invite arrives from whatever
        // MAIL_FROM_NAME is pinned to (one masjid's name), which reads wrong on
        // another school's email — the subject and body already say the right
        // org, so the "From" line must too. The ADDRESS stays the configured,
        // domain-verified sender; only the display name is personalised.
        $fromName = $this->orgName ?: config('mail.from.name', config('app.name'));

        return $this->subject($subject)
            ->from(config('mail.from.address'), $fromName)
            ->view('emails.account-access');
    }
}
