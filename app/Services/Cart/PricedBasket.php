<?php

namespace App\Services\Cart;

use App\Models\CartItem;

/**
 * A basket as it stands at the moment of checkout: every line re-asked, the total
 * recomputed from what is still payable, and the ONE account the money goes to.
 *
 * `refusal` is a reason the WHOLE basket cannot be paid, as opposed to one line
 * being gone. When it is set, nothing may be charged, whatever the lines say.
 */
final readonly class PricedBasket
{
    /**
     * @param  list<array{item: CartItem, outcome: CartLineOutcome}>  $lines
     */
    public function __construct(
        public array $lines,
        public int $totalMinor,
        public string $currency,
        public ?string $destinationAccountId,
        public ?string $refusal,
    ) {}

    /** Payable only when the basket is not refused, has a payee, and owes something. */
    public function isPayable(): bool
    {
        return $this->refusal === null
            && $this->destinationAccountId !== null
            && $this->totalMinor > 0;
    }

    /**
     * Every line the shopper must be told about BEFORE the card screen: the ones that
     * went, and the ones still in the basket at a different price or quantity.
     *
     * @return list<array{label: string, status: string, reason: string}>
     */
    public function notices(): array
    {
        $notices = [];

        foreach ($this->lines as ['outcome' => $outcome]) {
            if ($outcome->reason !== null) {
                $notices[] = ['label' => $outcome->label, 'status' => $outcome->status, 'reason' => $outcome->reason];
            }
        }

        return $notices;
    }
}
