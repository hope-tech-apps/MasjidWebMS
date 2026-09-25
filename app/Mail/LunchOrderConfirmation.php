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
 * The Jummah-lunch order email: what was ordered, what it costs, what has been
 * paid, and the link back to the order page where the customer can change it
 * until the cutoff (owner, 2026-09-24).
 *
 * Modelled on FormSubmissionReceipt: queued, and built from plain values the
 * caller formatted (App\Services\Lunch\LunchOrderMailer), never from a model, so
 * a queued mail says what was true when it was sent and cannot outlive a column
 * rename. Sent only when the customer gave an address (email stays optional):
 *
 *  - a pay-at-pickup order, when it is placed;
 *  - an online order, when the webhook records its payment;
 *  - again, as "updated", when a paid order's top-up is applied;
 *  - or, when a top-up was paid but NOT applied (the order had changed, or
 *    ordering had ended), to say the payment arrived and the order was not
 *    changed (`unappliedPaymentLine`).
 *
 * The order link is the capability to change the order, exactly as it is on the
 * page the customer already saw; it goes only to the address they gave.
 */
class LunchOrderConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,array{name:string,quantity:int,line:string}>  $items
     */
    public function __construct(
        public string $orderNumber,
        public string $masjidName,
        public ?string $customerName,
        public ?string $menuTitle,
        /** "Friday, January 8" — a calendar date, never timezone-shifted. */
        public ?string $serviceDate,
        public array $items,
        /** Each pre-formatted by the caller ("$16.00"). */
        public string $totalLine,
        public ?string $paidLine,
        public ?string $dueLine,
        public ?string $dueLabel,
        public ?string $pickupNote,
        public string $orderUrl,
        /** "Friday, January 8 at 11:00 AM", in the masjid's own timezone; null when there is no cutoff ahead. */
        public ?string $changeUntil,
        public bool $updated = false,
        /** Named masjidEmail, not replyTo: Mailable already owns a $replyTo property. */
        public ?string $masjidEmail = null,
        /** The amount of a top-up that was paid but not applied ("$8.00"); null otherwise. */
        public ?string $unappliedPaymentLine = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->masjidName ?: config('mail.from.name')),
            subject: match (true) {
                $this->unappliedPaymentLine !== null => 'We received your payment for lunch order #' . $this->orderNumber,
                $this->updated => 'Your lunch order #' . $this->orderNumber . ' was updated',
                default => 'Your lunch order #' . $this->orderNumber,
            },
            replyTo: $this->canReply() ? [$this->masjidEmail] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lunch-order-confirmation',
            with: [
                'headline' => match (true) {
                    $this->unappliedPaymentLine !== null => 'We received your payment',
                    $this->updated => 'Your order was updated',
                    default => 'Your order is in',
                },
                'unappliedLine' => $this->unappliedPaymentLine !== null
                    ? 'We received your payment of ' . $this->unappliedPaymentLine . ', but your order had changed '
                        . 'in the meantime or ordering had closed, so your order was not changed. The masjid will '
                        . 'settle the difference with you.'
                    : null,
                'greeting' => $this->customerName
                    ? 'Assalamu alaikum ' . $this->customerName . ','
                    : 'Assalamu alaikum,',
                // orderLink, NOT orderUrl: Mailable::buildViewData() lays every public
                // property over these keys, so a key sharing a property's name would
                // render the raw property instead of this checked one.
                'orderLink' => $this->orderLink(),
                'changeLine' => $this->changeUntil !== null
                    ? 'You can change your order until ' . $this->changeUntil . '.'
                        // A paid order grows only by paying the difference, and that
                        // page is not offered in the last half hour.
                        . ($this->paidLine !== null ? ' Adding plates to a paid order online closes 30 minutes before that.' : '')
                    : null,
                // Only a promise the link can keep: once the cutoff has passed the
                // order can be looked at, not changed.
                'buttonLabel' => $this->changeUntil !== null ? 'View or change your order' : 'View your order',
                // Without the masjid's own address there is no Reply-To, and a reply
                // would reach the platform's sending address instead.
                'canReply' => $this->canReply(),
            ],
        );
    }

    private function canReply(): bool
    {
        return $this->masjidEmail !== null && filter_var($this->masjidEmail, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * The order page, only when it is an http(s) URL. LunchOrderLink builds it from
     * an allowlisted origin or APP_URL; this is the template's own guard, because a
     * queued mail can outlive the code that built it, and an href is where a bad
     * value becomes a live link.
     */
    private function orderLink(): ?string
    {
        return preg_match('#^https?://[^\s"<>]+$#i', $this->orderUrl) === 1 ? $this->orderUrl : null;
    }
}
