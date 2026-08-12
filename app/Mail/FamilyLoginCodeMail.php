<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The sign-in code for a parent/guardian (T-015d).
 *
 * ---------------------------------------------------------------------------
 * THE ONE MAILABLE IN THIS APPLICATION THAT IS NOT `ShouldQueue`
 * ---------------------------------------------------------------------------
 *
 * Every other one is (`BroadcastMail` says so explicitly), and the reason is
 * sound for them: an admin composing a broadcast to three thousand contacts
 * must not wait on a relay. Do NOT make this one match.
 *
 * `QUEUE_CONNECTION` is `database` here. A queued mailable is serialized into
 * `jobs.payload` — public properties and all — so queueing this would write the
 * plaintext sign-in code into a database table and leave it there until a worker
 * picked it up, and on a delivery failure it would land in `failed_jobs` and stay
 * for as long as nobody pruned it. That is precisely the state
 * `contact_login_codes.code_hash` exists to prevent; hashing the credential in
 * one table while spooling it in cleartext in another is not a compromise, it is
 * the same leak with an extra step.
 *
 * The cost of sending inline is one SMTP round-trip on a single-recipient
 * message, on a request a human is already waiting on.
 * `FamilyLoginService::deliver()` wraps the send so a relay outage cannot turn
 * into a 500 — which would be an existence oracle — and the caller still gets
 * the same 202 either way.
 *
 * Nothing here is `SerializesModels` and no model is passed in: the org name,
 * the recipient's first name and the code are plain scalars, so there is no
 * object graph that could pull a `contacts` row (and its `notes`) into a mail
 * payload.
 */
class FamilyLoginCodeMail extends Mailable
{
    public function __construct(
        public string $orgName,
        public string $code,
        public int $expiresInMinutes,
        public ?string $recipientName = null,
        /**
         * Named orgEmail, not replyTo: Mailable already declares an untyped
         * $replyTo and redeclaring it with a type is a fatal error — the same
         * note BroadcastMail and FormSubmissionReceipt carry.
         */
        public ?string $orgEmail = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // Deliberately generic and identical for every tenant and every
            // recipient. A subject line is the part of an email that shows up
            // on a lock screen, in a notification preview and in a shared
            // household inbox list; naming the school there would disclose the
            // family's association with it to anyone glancing at the phone.
            subject: 'Your sign-in code',
            replyTo: $this->orgEmail && filter_var($this->orgEmail, FILTER_VALIDATE_EMAIL)
                ? [$this->orgEmail]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.family-login-code',
            with: [
                'orgName' => $this->orgName,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
                'greeting' => $this->recipientName
                    ? 'Assalamu alaikum ' . $this->recipientName . ','
                    : 'Assalamu alaikum,',
            ],
        );
    }
}
