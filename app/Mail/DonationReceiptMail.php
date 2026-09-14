<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The donor's tax receipt email. Takes only primitives (already resolved in the
 * unbound webhook context) so there are no tenant-scope surprises and nothing
 * to re-query if this is ever queued.
 *
 * The HTML body stays the readable summary; the PDF rendered by
 * DonationReceiptPdfService rides along as the printable/filable copy, exactly
 * as AnnualStatementMail carries the year-end letter. The attachment is
 * optional — a render failure must still let the HTML receipt go out.
 */
class DonationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Whether the issuer is a masjid, the only kind of organisation whose receipt
     * cites "intangible religious benefit" (Letterhead::religiousOrg). Declared
     * with a default rather than promoted: SerializesModels restores only the
     * keys a payload carries, so a mail serialized before this existed comes back
     * with the masjid wording instead of an uninitialized property.
     */
    public bool $religiousOrg = true;

    public function __construct(
        public string $masjidName,
        public string $donorName,
        public int $serial,
        public string $issueDate,
        public string $fundName,
        public string $currency,
        public string $grossAmount,
        public string $eligibleAmount,
        public string $reference,
        public bool $recurring = false,
        public ?string $pdf = null,
        public ?string $pdfName = null,
        bool $religiousOrg = true,
    ) {
        $this->religiousOrg = $religiousOrg;
    }

    /** Attach the printable receipt PDF when one was rendered. */
    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdf, $this->pdfName ?: 'donation-receipt.pdf')
                ->withMime('application/pdf'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->masjidName ?: config('mail.from.name')),
            subject: 'Your donation receipt — ' . $this->masjidName . ' (No. ' . $this->serial . ')',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.donation-receipt',
            with: [
                'masjidName' => $this->masjidName,
                'donorName' => $this->donorName,
                'serial' => $this->serial,
                'issueDate' => $this->issueDate,
                'fundName' => $this->fundName,
                'currency' => $this->currency,
                'grossAmount' => $this->grossAmount,
                'eligibleAmount' => $this->eligibleAmount,
                'reference' => $this->reference,
                'recurring' => $this->recurring,
                'religiousOrg' => $this->religiousOrg,
            ],
        );
    }
}
