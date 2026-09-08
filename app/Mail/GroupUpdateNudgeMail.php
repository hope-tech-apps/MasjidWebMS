<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "You have something to read" — never what it says.
 *
 * A class post or a message about a child is content this email must NOT carry:
 * the school's disclosure rules live in the portal, where consent and identity
 * are checked, not in an inbox that forwards, previews on a lock screen and sits
 * in a shared household account. So the body is a NUDGE — org, class, a sign-in
 * link, nothing else — and the subject is generic and identical for every tenant
 * and recipient (mirroring FamilyLoginCodeMail), so a glance at a phone never
 * discloses the family's association with the school, let alone the message.
 *
 * Deliberately NOT ShouldQueue: it is always sent from inside the already-queued
 * SendGroupNotificationJob, which owns the async boundary and the per-recipient
 * failure isolation. Queueing it again would be a redundant second hop. Only
 * scalars cross the wire — no models, no child data.
 */
class GroupUpdateNudgeMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $orgName,
        public string $groupLabel,
        /** 'update' (a class-story post) or 'message' (a thread). */
        public string $kind,
        public string $signInUrl,
        public ?string $recipientName = null,
        /**
         * Named orgEmail, not replyTo: Mailable already declares an untyped
         * $replyTo and redeclaring it with a type is fatal — the same note
         * FamilyLoginCodeMail and BroadcastMail carry.
         */
        public ?string $orgEmail = null,
    ) {
    }

    public function build(): self
    {
        // Generic and identical for every tenant/recipient — a subject shows up
        // on a lock screen and in a shared inbox list.
        $subject = $this->kind === 'message'
            ? 'You have a new message'
            : 'You have a new update';

        $fromAddress = config('mail.from.address');

        $mail = $this->subject($subject)
            // The SENDER NAME follows the school (matching the invite email), so
            // the "From" line and the body agree; the ADDRESS stays the verified
            // sender.
            ->from($fromAddress, $this->orgName ?: config('mail.from.name', config('app.name')))
            ->view('emails.group-update-nudge');

        if ($this->orgEmail && filter_var($this->orgEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($this->orgEmail);
        }

        return $mail;
    }
}
