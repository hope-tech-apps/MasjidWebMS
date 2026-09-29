<?php

namespace App\Services\Cart;

use RuntimeException;

/**
 * A basket that may not be sent to the card screen, in words a shopper can act on
 * (CartCheckoutService). The sibling of FormCheckoutRefused, for the same reason: a
 * bare RuntimeException also covers a database error, whose message carries SQL, so
 * the public controller shows exactly these messages and nothing else.
 *
 * `notices` carries the lines that changed since the shopper last looked — gone, or
 * still here at a different price or quantity. They are the reason to refuse: the
 * shopper must see them BEFORE the card screen, never discover them on a receipt. The
 * refusal also carries the whole priced basket they were found in (`priced()`), because a
 * notice is a sentence and carries no amount: what the shopper is asked to accept is the
 * basket at its new prices, and the page has to be able to show it.
 */
final class CartCheckoutRefused extends RuntimeException
{
    /** @var list<array{label: string, status: string, reason: string}> */
    private array $notices = [];

    private ?string $seen = null;

    private ?PricedBasket $priced = null;

    /**
     * @param  PricedBasket  $priced  the basket as it prices now. Its notices are the reason to
     *                                refuse; its viewFingerprint() is what the page hands back to
     *                                acknowledge(), which applies the changes only while it still
     *                                matches.
     */
    public static function basketChanged(PricedBasket $priced): self
    {
        $refusal = new self('Some things in your basket changed. Please check them before paying.');
        $refusal->notices = $priced->notices();
        $refusal->seen = $priced->viewFingerprint();
        $refusal->priced = $priced;

        return $refusal;
    }

    /** What the shopper is being shown, to pass back to CartCheckoutService::acknowledge(). */
    public function seen(): ?string
    {
        return $this->seen;
    }

    /** @return list<array{label: string, status: string, reason: string}> */
    public function notices(): array
    {
        return $this->notices;
    }

    /** The basket as the shopper is asked to accept it, for a refusal that is a changed basket. */
    public function priced(): ?PricedBasket
    {
        return $this->priced;
    }
}
