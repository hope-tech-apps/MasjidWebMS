<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an organisation's office that somebody used its contact form
 * (PLAN T-042d).
 *
 * Before this, a contact-us message existed only as a row on a dashboard screen
 * nobody is obliged to open. The observed cost is not theoretical: a message
 * asking about a janazah, or a family asking for help, sat unread because
 * nothing in the world changed when it arrived.
 *
 * Queued, for the same reason FormResponseSubmitted is: the person who filled in
 * the form is waiting on the HTTP response and must not wait on SMTP. The
 * contact_us_messages row is the record; this is the nudge, so a delivery
 * failure loses a notification and nothing else.
 *
 * ## Primitives only, and the recipient is never in the payload
 *
 * A queued payload must not drag a model through serialization, and here that
 * restraint does a second job: everything in this email is resolved from the
 * SERVER'S view of the tenant before the job is created (App\Support\
 * ContactUsNotifier). Nothing an anonymous caller typed decides where it goes.
 * The sender's own details travel as text INSIDE the body — they are what the
 * office needs in order to answer — but the address list is built solely from
 * the organisation's own stored contact address.
 *
 * Reply-to is the sender, and ONLY when their address actually parses, so
 * hitting Reply reaches the person who wrote in rather than the no-reply sender
 * — and a malformed or absent address degrades to no reply-to rather than
 * handing the mailer something it will throw on.
 */
class ContactUsMessageReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $messageId,
        public string $masjidName,
        /** The reason the sender picked, already resolved to its display text. */
        public string $reason,
        public string $senderName,
        public ?string $senderEmail,
        public ?string $senderPhone,
        public string $body,
        /** Pre-formatted by the caller in the organisation's own timezone. */
        public string $receivedAt,
        public string $adminUrl,
        /** How it arrived — "the website" / "the mobile app" — for triage. */
        public string $source,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New message via ' . $this->masjidName . ': ' . $this->reason,
            replyTo: $this->replyToAddresses(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-us-message-received',
            with: [
                'messageId' => $this->messageId,
                'masjidName' => $this->masjidName,
                'reason' => $this->reason,
                'senderName' => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'senderPhone' => $this->senderPhone,
                'body' => $this->body,
                'receivedAt' => $this->receivedAt,
                'adminUrl' => $this->adminUrl,
                'source' => $this->source,
            ],
        );
    }

    /** @return array<int,string> */
    private function replyToAddresses(): array
    {
        return $this->senderEmail && filter_var($this->senderEmail, FILTER_VALIDATE_EMAIL)
            ? [$this->senderEmail]
            : [];
    }
}
