<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A kitchen customer's email: "we received your order" (the office still has to
 * confirm it) or "your order is confirmed". Built only by KitchenOrderNotifier.
 *
 * Primitives only, like LunchOrderConfirmation: a queued payload must not carry a
 * tenant-scoped model through serialization, and it cannot reach for a field the
 * notifier chose not to hand it. From the organisation's name, reply-to its own
 * address, so a customer's reply reaches the office and not the sending service.
 */
class KitchenOrderForCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const RECEIVED = 'received';
    public const CONFIRMED = 'confirmed';

    /**
     * @param  list<array{name: string, quantity: int, line: string}>  $items
     */
    public function __construct(
        public string $kind,
        public string $orderNumber,
        public string $masjidName,
        public ?string $customerName,
        public ?string $menuTitle,
        public ?string $pickupLabel,
        public array $items,
        public string $totalLine,
        public string $paymentLine,
        public ?string $howToPay,
        public ?string $pickupNote,
        public ?string $orderUrl,
        public ?string $masjidEmail = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->masjidName ?: config('mail.from.name')),
            subject: $this->kind === self::CONFIRMED
                ? 'Your order #' . $this->orderNumber . ' is confirmed'
                : 'We received your order #' . $this->orderNumber,
            replyTo: $this->canReply() ? [$this->masjidEmail] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kitchen-order-customer',
            with: [
                'headline' => $this->kind === self::CONFIRMED ? 'Your order is confirmed' : 'We received your order',
                'lead' => $this->kind === self::CONFIRMED
                    ? 'The office has confirmed your order. We will have it ready for pickup at the time below.'
                    : 'The office will review your order and confirm it with you. It is not confirmed until you hear from us.',
                'greeting' => $this->customerName
                    ? 'Assalamu alaikum ' . $this->customerName . ','
                    : 'Assalamu alaikum,',
                // orderLink, NOT orderUrl: Mailable::buildViewData() lays every public
                // property over these keys (LunchOrderConfirmation has the same rule).
                'orderLink' => $this->orderLink(),
                'canReply' => $this->canReply(),
            ],
        );
    }

    private function canReply(): bool
    {
        return $this->masjidEmail !== null && filter_var($this->masjidEmail, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function orderLink(): ?string
    {
        return $this->orderUrl !== null && preg_match('#^https?://[^\s"<>]+$#i', $this->orderUrl) === 1
            ? $this->orderUrl
            : null;
    }
}
