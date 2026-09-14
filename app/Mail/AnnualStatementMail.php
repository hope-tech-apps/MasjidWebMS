<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A donor's year-end giving statement for 501(c)(3) tax purposes. Primitives only
 * (resolved before queueing) so nothing tenant-scoped rides through serialization.
 *
 * @param array<int, array{date:string, fund:string, amount:string, serial:int}> $gifts
 * @param array<int, array{fund:string, amount:string}> $byFund
 */
class AnnualStatementMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Whether the issuer is a masjid, the only kind of organisation whose
     * statement cites "intangible religious benefits" or calls itself 501(c)(3)
     * without a tax ID on hand (Letterhead::religiousOrg). Declared with a
     * default rather than promoted: this mail is queued, and SerializesModels
     * restores only the keys a payload carries, so a statement queued before
     * this existed comes back with the masjid wording instead of an
     * uninitialized property.
     */
    public bool $religiousOrg = true;

    public function __construct(
        public string $masjidName,
        public string $donorName,
        public int $year,
        public string $currency,
        public string $totalEligible,
        public int $giftCount,
        public array $gifts,
        public array $byFund,
        public ?string $pdf = null,
        public ?string $pdfName = null,
        bool $religiousOrg = true,
    ) {
        $this->religiousOrg = $religiousOrg;
    }

    /** Attach the formal letter PDF when one was rendered. */
    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdf, $this->pdfName ?: 'giving-statement.pdf')
                ->withMime('application/pdf'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->masjidName ?: config('mail.from.name')),
            subject: $this->year . ' Annual Giving Statement — ' . $this->masjidName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.annual-statement',
            with: [
                'masjidName' => $this->masjidName,
                'donorName' => $this->donorName,
                'year' => $this->year,
                'currency' => $this->currency,
                'totalEligible' => $this->totalEligible,
                'giftCount' => $this->giftCount,
                'gifts' => $this->gifts,
                'byFund' => $this->byFund,
                'religiousOrg' => $this->religiousOrg,
            ],
        );
    }
}
