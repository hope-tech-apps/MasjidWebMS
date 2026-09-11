<?php

namespace App\Mail;

use App\Models\Form;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
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
 *
 * On a form that takes payment (DECISIONS.md 2026-09-11) the receipt also says how the
 * registration was paid, and a settled registration's receipt carries the WhatsApp group
 * link as a real link. A card registration gets this email when the signed webhook
 * records its payment, never at submit.
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
        public ?string $paymentNote,
        /** Named masjidEmail, not replyTo: Mailable already owns a $replyTo property. */
        public ?string $masjidEmail,
        /** "Paid $30.87 by card", "Paid in cash", "Paid (recorded by staff)"; null until paid. */
        public ?string $paymentLine = null,
        /** The WhatsApp group link, for a settled registration only. Re-checked in content(). */
        public ?string $whatsappUrl = null,
        public ?string $whatsappLabel = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->masjidName ?: config('mail.from.name')),
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
                // Once paid, the amount is the price and the payment line says what was paid.
                'amountLabel' => $this->paymentLine ? 'Price' : 'Total due',
                'paymentLine' => $this->paymentLine,
                // groupLink, NOT whatsappUrl: Mailable::buildViewData() lays every public
                // property over these keys, so a key sharing a property's name would render
                // the raw, unchecked property instead of this.
                'groupLink' => $this->groupLink(),
                'groupLabel' => $this->whatsappLabel ?: 'Join the WhatsApp group',
            ],
        );
    }

    /**
     * The group link, only when it is a chat.whatsapp.com invite. FormNotifier already
     * checks it; this is the template's own guard, because a queued mail can outlive the
     * code that built it, and an href is where a bad value becomes a live link.
     */
    private function groupLink(): ?string
    {
        return is_string($this->whatsappUrl) && preg_match(Form::WHATSAPP_URL_PATTERN, $this->whatsappUrl) === 1
            ? $this->whatsappUrl
            : null;
    }
}
