<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
    ) {
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
            ],
        );
    }
}
