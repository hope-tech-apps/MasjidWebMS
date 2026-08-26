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

    public function __construct(
        public User $user,
        public string $url,
        public string $mode = self::MODE_RESET,
        public ?string $orgName = null,
        public int $expiresInMinutes = 60,
    ) {
    }

    public function build(): self
    {
        $subject = $this->mode === self::MODE_INVITE
            ? 'Set up your '.($this->orgName ?: config('app.name')).' account'
            : 'Reset your '.config('app.name').' password';

        return $this->subject($subject)->view('emails.account-access');
    }
}
