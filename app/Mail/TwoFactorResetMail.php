<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Your two-step sign-in was switched off, and here is who did it."
 *
 * Sent to the affected administrator every time a platform operator clears
 * their second factor. It is not a receipt and it is not optional: the operator
 * door is dangerous precisely because a SuperAdmin can already reset any staff
 * password, so the thing that has to be impossible is doing it QUIETLY. The
 * ledger row makes the act reviewable after the fact; this email makes it
 * visible to the one person who can say "I never asked for that" on the day.
 *
 * It therefore names the operator, quotes the reason they typed, and carries no
 * link and no token — there is nothing here for a recipient to click, so a
 * forgery of it achieves nothing, and a phisher gains no template worth copying.
 * The recipient's next step is to sign in with their password and enrol a new
 * device, which is the flow they already know.
 *
 * Sent UNQUEUED, on purpose. This application's queue is not what carries the
 * account-access mails either, and a notice about somebody's second factor that
 * sits in a table until a worker wakes up is a notice that arrives after the
 * damage. The send is wrapped by the caller so a mail outage cannot swallow the
 * reset itself — the response says whether the notice went out.
 */
class TwoFactorResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $performedByLabel,
        public string $reason,
        public string $performedAt,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Two-step sign-in was switched off on your '.config('app.name').' account')
            ->from(config('mail.from.address'), config('mail.from.name', config('app.name')))
            ->view('emails.two-factor-reset');
    }
}
