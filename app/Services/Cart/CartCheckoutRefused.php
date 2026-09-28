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
 * shopper must see them BEFORE the card screen, never discover them on a receipt.
 */
final class CartCheckoutRefused extends RuntimeException
{
    /** @var list<array{label: string, status: string, reason: string}> */
    private array $notices = [];

    private ?string $seen = null;

    /**
     * @param  list<array{label: string, status: string, reason: string}>  $notices
     * @param  string  $seen  PricedBasket::viewFingerprint() of the basket these notices
     *                        describe. The page shows the notices and hands this back to
     *                        acknowledge(), which applies them only if it still matches.
     */
    public static function basketChanged(array $notices, string $seen): self
    {
        $refusal = new self('Some things in your basket changed. Please check them before paying.');
        $refusal->notices = $notices;
        $refusal->seen = $seen;

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
}
