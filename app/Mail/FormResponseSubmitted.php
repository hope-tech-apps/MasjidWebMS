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
     * "$17.00 × 4": the unit price and how many, when more than one unit was charged
     * (FormResponse::priceBreakdown(); Ramadan giving, 2026-09-25), or null.
     *
     * Declared with a default, NOT promoted: a mail queued before this existed is
     * unserialized without running the constructor, and a promoted property would be
     * left uninitialised.
     */
    public ?string $breakdownLine = null;

    /** "Wednesday, February 10, 2027": the date this registration holds, or null. As above. */
    public ?string $reservedDate = null;

    /**
     * The date this registration asked for when it went to another payer before this
     * payment arrived (App\Support\FormReservations), or null. The email then says the
     * date could not be kept, instead of silently leaving it out. As above.
     */
    public ?string $lostDate = null;

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
        /** How it was paid ("Paid $30.87 by card"); null for a registration that has paid nothing. */
        public ?string $paymentLine = null,
        /**
         * True when $paymentLine says the family still OWES the office ("Owed — paying the
         * office"; BISS, 2026-09-14): the amount reads "Amount owed" and the line is not
         * drawn in the paid green. Defaulted, so a mail queued before it existed still builds.
         */
        public bool $paymentOwed = false,
        ?string $breakdownLine = null,
        ?string $reservedDate = null,
        ?string $lostDate = null,
    ) {
        $this->breakdownLine = $breakdownLine;
        $this->reservedDate = $reservedDate;
        $this->lostDate = $lostDate;
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
                'breakdownLine' => $this->breakdownLine,
                'reservedDate' => $this->reservedDate,
                'lostDate' => $this->lostDate,
                'people' => $this->people,
                'adminUrl' => $this->adminUrl,
                'amountLabel' => match (true) {
                    $this->paymentOwed => 'Amount owed',
                    $this->paymentLine !== null && $this->paymentLine !== '' => 'Price',
                    default => 'Amount due',
                },
                'paymentLine' => $this->paymentLine,
                // `owed`, NOT paymentOwed: Mailable::buildViewData() lays every public property
                // over these keys, so a key sharing a property's name would render the raw one.
                'owed' => $this->paymentOwed,
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
