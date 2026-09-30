<?php

namespace App\Services\Cart;

/**
 * What re-checking one basket line against its own source found.
 *
 * A basket reserves nothing. Between adding a line and pressing pay, a form can
 * close (MEC's festival tickets close at 23:59 on 16 October), a form can fill to
 * capacity, an admin can edit a price, and a pickup date can pass. So every line
 * is asked again, at checkout, and the answer is one of these three.
 *
 * `gone` is not an error to swallow. The shopper is told which line went and why,
 * with the source's own wording, and the total is recomputed BEFORE the card
 * screen. Taking money for something that has closed is the failure this whole
 * class exists to prevent.
 */
final readonly class CartLineOutcome
{
    private function __construct(
        public string $status,
        public int $unitAmountMinor,
        public int $quantity,
        public string $label,
        public ?string $reason,
    ) {}

    /** Still on sale at the price the shopper was shown. */
    public static function available(int $unitAmountMinor, int $quantity, string $label): self
    {
        return new self('available', $unitAmountMinor, $quantity, $label, null);
    }

    /**
     * Still on sale, but not at the price the shopper was shown. It stays in the
     * basket at the CURRENT price and the shopper is told, rather than being
     * charged the old price or silently charged the new one.
     */
    public static function repriced(int $unitAmountMinor, int $quantity, string $label, string $reason): self
    {
        return new self('repriced', $unitAmountMinor, $quantity, $label, $reason);
    }

    /** No longer purchasable. Dropped from the basket, named to the shopper. */
    public static function gone(string $label, string $reason): self
    {
        return new self('gone', 0, 0, $label, $reason);
    }

    public function isPayable(): bool
    {
        return $this->status !== 'gone';
    }

    /** What this line contributes to the total, in minor units. */
    public function totalMinor(): int
    {
        return $this->isPayable() ? $this->unitAmountMinor * $this->quantity : 0;
    }
}
