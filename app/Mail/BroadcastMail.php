<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
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
 *
 * ## The unsubscribe link (T-042c)
 *
 * This is the ONE Mailable in the application that carries one, because it is
 * the one that sends unsolicited bulk mail. It arrives as two ready-made strings
 * from EmailChannel — never built here, because this class is queued and a
 * serialized job would mint the URL from whatever host the worker thinks it is
 * on — and it goes out twice:
 *
 *  - as a visible footer link (the GET page, which asks before it acts);
 *  - as `List-Unsubscribe` + `List-Unsubscribe-Post` headers pointing at the
 *    POST, which is RFC 8058 one-click. Gmail and Yahoo have required that of
 *    bulk senders since February 2024, and a footer link alone does not satisfy
 *    it.
 *
 * Both are nullable and the headers `array_filter` themselves away when absent,
 * so a caller that has not been updated sends a mail with no unsubscribe headers
 * rather than a mail with a broken `<>` — an unconfigured integration no-ops
 * rather than throwing.
 *
 * Nothing in this class CONSULTS the suppression list, and nothing in any
 * Mailable ever may: the opt-out is honoured where the audience is resolved
 * (BroadcastAudienceResolver::emailAudience), which is what keeps receipts,
 * statements, registration confirmations and sign-in codes out of its reach.
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
        /** The GET landing that asks before it acts; rendered in the footer. */
        public ?string $unsubscribeUrl = null,
        /** The POST that acts; goes in List-Unsubscribe for one-click clients. */
        public ?string $unsubscribeOneClickUrl = null,
    ) {
    }

    /**
     * RFC 8058 one-click unsubscribe.
     *
     * The header URL is the POST route, never the GET: a mailbox provider that
     * offers a native "unsubscribe" button sends a POST with the body
     * `List-Unsubscribe=One-Click`, and pointing it at a page that only renders
     * a confirmation would leave the person's click unhonoured.
     *
     * Both entries drop out together when no URL was supplied, so the mail goes
     * out without the headers instead of with an empty `<>` a relay would reject.
     */
    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            'List-Unsubscribe' => $this->unsubscribeOneClickUrl
                ? '<' . $this->unsubscribeOneClickUrl . '>'
                : null,
            'List-Unsubscribe-Post' => $this->unsubscribeOneClickUrl
                ? 'List-Unsubscribe=One-Click'
                : null,
        ]));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->orgName ?: config('mail.from.name')),
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
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'greeting' => $this->recipientName
                    ? 'Assalamu alaikum ' . $this->recipientName . ','
                    : 'Assalamu alaikum,',
            ],
        );
    }
}
