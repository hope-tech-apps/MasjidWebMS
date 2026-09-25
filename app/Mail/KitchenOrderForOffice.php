<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the office a kitchen order is waiting for its confirmation (owner:
 * "office confirms"). Built only by KitchenOrderNotifier.
 *
 * Carries what the office needs to call the customer back and to decide — name,
 * phone, email, notes, pickup time, lines, money — because the answer to "can we
 * make this on Saturday?" is a phone call before it is a button. Reply-to is the
 * customer when they gave an address, so answering reaches them directly.
 */
class KitchenOrderForOffice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{name: string, quantity: int, line: string}>  $items
     */
    public function __construct(
        public string $orderNumber,
        public string $masjidName,
        public ?string $menuTitle,
        public string $customerName,
        public ?string $customerPhone,
        public ?string $customerEmail,
        public ?string $customerNotes,
        public ?string $pickupLabel,
        public array $items,
        public string $totalLine,
        public string $paymentLine,
        public string $adminUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New kitchen order #' . $this->orderNumber . ' to confirm' . ($this->pickupLabel ? ' — pickup ' . $this->pickupLabel : ''),
            replyTo: $this->customerEmail !== null && filter_var($this->customerEmail, FILTER_VALIDATE_EMAIL)
                ? [$this->customerEmail]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kitchen-order-office');
    }
}
