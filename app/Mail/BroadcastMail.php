<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email copy of a composed broadcast (T-008).
 *
 * Carries the same title and body the announcement, the push and the signage
 * board carry — that identity is the entire point of the composer, so this class
 * adds no editorial wording of its own beyond a greeting and the organisation's
 * name.
 *
 * `ShouldQueue`, like every other Mailable in this app: a broadcast can address
 * a few thousand contacts, and an admin's request must not wait on the relay.
 * One consequence worth naming — because it changes what a failure MEANS — is
 * that `Mail::to(...)->send()` on a queued mailable only enqueues. A failure
 * caught inside EmailChannel is therefore an addressing or queueing failure; a
 * transport failure surfaces later on the queue, exactly as it does for
 * registration receipts today.
 *
 * The organisation's own address becomes reply-to (never From — the platform
 * owns the sending domain), so a congregant hitting "reply" reaches their
 * masjid rather than a black hole. Nothing here hardcodes "Masjid": the org name
 * is passed in, so a school or community tenant reads correctly
 * (.claude/rules/verticals.md).
 */
class BroadcastMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $orgName,
        public string $title,
        public string $body,
        public ?string $link = null,
        public ?string $imageUrl = null,
        public ?string $recipientName = null,
        /**
         * Named orgEmail, not replyTo: Mailable already declares an untyped
         * $replyTo, and redeclaring it with a type is a fatal error. Same
         * reason FormSubmissionReceipt calls its copy $masjidEmail.
         */
        public ?string $orgEmail = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->title,
            replyTo: $this->orgEmail && filter_var($this->orgEmail, FILTER_VALIDATE_EMAIL)
                ? [$this->orgEmail]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.broadcast',
            with: [
                'orgName' => $this->orgName,
                'title' => $this->title,
                'body' => $this->body,
                'link' => $this->link,
                'imageUrl' => $this->imageUrl,
                'greeting' => $this->recipientName
                    ? 'Assalamu alaikum ' . $this->recipientName . ','
                    : 'Assalamu alaikum,',
            ],
        );
    }
}
