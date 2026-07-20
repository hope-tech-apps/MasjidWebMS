<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The donor's tax receipt email. Takes only primitives (already resolved in the
 * unbound webhook context) so there are no tenant-scope surprises and nothing
 * to re-query if this is ever queued.
 */
class DonationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

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
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
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
            ],
        );
    }
}
