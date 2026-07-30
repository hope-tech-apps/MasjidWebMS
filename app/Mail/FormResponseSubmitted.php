<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a masjid's coordinators that somebody signed up.
 *
 * Queued, because the person submitting is waiting on the HTTP response and should not
 * wait on SMTP. The form_responses row is the record; this is the nudge, so a delivery
 * failure loses a notification and nothing else.
 *
 * Primitives only — a queued payload must not drag a tenant-scoped model through
 * serialization, and it also means the mail body cannot accidentally reach for a field
 * that App\Support\FormNotifier deliberately withheld.
 *
 * Reply-to is the registrant, so answering the notification reaches the person who
 * registered rather than the no-reply sender.
 */
class FormResponseSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,array{name:string,detail:string}>  $people
     */
    public function __construct(
        public int $responseId,
        public string $formName,
        public string $masjidName,
        public ?string $registrantName,
        public ?string $registrantEmail,
        public ?string $registrantPhone,
        public int $entryCount,
        /** Pre-formatted by the caller ("$400.00"), or null when the form charges nothing. */
        public ?string $amountLine,
        public ?string $tierLabel,
        public array $people,
        public string $adminUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        $who = $this->registrantName ? ' — ' . $this->registrantName : '';

        return new Envelope(
            subject: 'New registration: ' . $this->formName . $who,
            replyTo: $this->replyToAddresses(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.form-response-submitted',
            with: [
                'responseId' => $this->responseId,
                'formName' => $this->formName,
                'masjidName' => $this->masjidName,
                'registrantName' => $this->registrantName ?: 'Someone',
                'registrantEmail' => $this->registrantEmail,
                'registrantPhone' => $this->registrantPhone,
                'entryCount' => $this->entryCount,
                'amountLine' => $this->amountLine,
                'tierLabel' => $this->tierLabel,
                'people' => $this->people,
                'adminUrl' => $this->adminUrl,
            ],
        );
    }

    /** @return array<int,string> */
    private function replyToAddresses(): array
    {
        return $this->registrantEmail && filter_var($this->registrantEmail, FILTER_VALIDATE_EMAIL)
            ? [$this->registrantEmail]
            : [];
    }
}
