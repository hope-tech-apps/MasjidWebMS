<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The copy that goes back to whoever filled the form in.
 *
 * Two jobs. It answers "did that go through?", which otherwise produces duplicate
 * registrations from people who were not sure. And where a form charges money it restates
 * the amount the submitter just agreed to owe, at the price tier in force when they
 * submitted — the same figure stored on the response, so a later price step never changes
 * what somebody was told.
 *
 * The wording is the form's own success copy, not text written here, so a masjid editing
 * its confirmation screen edits this email too, and nothing Burlington-specific is baked
 * into the codebase.
 */
class FormSubmissionReceipt extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,array{name:string,detail:string}>  $people
     * @param  array<int,string>  $nextSteps
     */
    public function __construct(
        public int $responseId,
        public string $formName,
        public string $masjidName,
        public ?string $registrantName,
        public int $entryCount,
        /** Pre-formatted by the caller ("$400.00"), or null when the form charges nothing. */
        public ?string $amountLine,
        public ?string $tierLabel,
        public array $people,
        public ?string $title,
        public ?string $body,
        public array $nextSteps,
        /** Named masjidEmail, not replyTo: Mailable already owns a $replyTo property. */
        public ?string $masjidEmail,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->formName . ' — registration received',
            replyTo: $this->masjidEmail && filter_var($this->masjidEmail, FILTER_VALIDATE_EMAIL)
                ? [$this->masjidEmail]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.form-submission-receipt',
            with: [
                'responseId' => $this->responseId,
                'formName' => $this->formName,
                'masjidName' => $this->masjidName,
                'greeting' => $this->registrantName
                    ? 'Assalamu alaikum ' . $this->registrantName . ','
                    : 'Assalamu alaikum,',
                'title' => $this->title ?: 'Your registration has been received',
                'body' => $this->body,
                'entryCount' => $this->entryCount,
                'amountLine' => $this->amountLine,
                'tierLabel' => $this->tierLabel,
                'people' => $this->people,
                'nextSteps' => $this->nextSteps,
                'paymentNote' => $this->paymentNote,
            ],
        );
    }
}
