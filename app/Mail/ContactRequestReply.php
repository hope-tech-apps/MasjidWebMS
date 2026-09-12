<?php

namespace App\Mail;

use App\Models\ContactUsMessage;
use App\Models\Masjid;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Staff's answer to somebody who wrote in through the contact form.
 *
 * ## This one is NOT ShouldQueue, and that is a decision (PLAN T-042d)
 *
 * Every other Mailable in this app is queued so the person waiting on the HTTP
 * response does not wait on the relay. The waiter here is different: it is a
 * member of staff who has just been shown exactly what is about to be sent and
 * to whom, and who pressed Send.
 *
 * Two things follow from queueing it, and both are worse than a slow request:
 *
 *  - "Reply sent successfully to jane@example.com" becomes a claim the app
 *    cannot support. The send would have moved to a worker, the endpoint would
 *    succeed unconditionally, and a relay failure would be invisible to the only
 *    person in a position to do anything about it.
 *  - ContactRequestsController stamps `contact_us_replies.sent_at` and the
 *    message's `answered_at` on the mailer ACCEPTING the reply. That ordering is
 *    the point — it is what stops "answered" being true for a message nobody
 *    was actually told about. A queued send has no acceptance to observe inside
 *    the request.
 *
 * So it stays synchronous. If it is ever queued, the success message must change
 * to "queued", the answered stamp has to move to a queue event, and so does the
 * release of `contact_us_replies.sending_at` — the claim the controller takes
 * BEFORE calling the mailer, which is the only thing stopping a retry or a
 * second tab from putting a second copy of this email in a stranger's inbox. A
 * claim released when the job is merely ENQUEUED protects nothing.
 *
 * `subjectFor()` is public and static because the admin screen shows the subject
 * line in the confirmation step BEFORE sending. That preview has to be the
 * string this Mailable will actually use, byte for byte — a TypeScript
 * re-implementation of the format would drift the first time anyone edited
 * either side, and the screen would then be lying about outgoing mail.
 */
class ContactRequestReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Masjid $masjid,
        public ContactUsMessage $contactMessage,
        public string $replyBody
    ) {
    }

    /**
     * The subject line, from the one place that decides it.
     *
     * @param  string|null  $reasonText  the reason the sender picked, or null
     */
    public static function subjectFor(?string $reasonText, ?string $masjidName): string
    {
        $reason = trim((string) $reasonText);

        return 'Re: ' . ($reason === '' ? 'your inquiry' : $reason) . ' - ' . trim((string) $masjidName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::subjectFor($this->contactMessage->reason?->text, $this->masjid->name),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-request-reply',
            with: [
                'masjidName' => $this->masjid->name,
                'contacterName' => $this->contactMessage->contacter?->name ?? 'there',
                'originalMessage' => $this->contactMessage->message,
                'replyBody' => $this->replyBody,
            ],
        );
    }
}
