<?php

namespace App\Services\Cart;

use App\Models\CartItem;

/**
 * A basket as it stands at the moment of checkout: every line re-asked, the total
 * recomputed from what is still payable, and the ONE account the money goes to.
 *
 * `refusal` is a reason the WHOLE basket cannot be paid, as opposed to one line
 * being gone. When it is set, nothing may be charged, whatever the lines say.
 *
 * `destinationIsLinked` is true when that account is a HOLDER's — the organisation
 * is linked to its parent for form card payments (FormChargeAccount). The page is
 * then opened on another organisation's account, whose Stripe users read its
 * metadata, so CartCheckoutService puts only an opaque reference there.
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
        public bool $destinationIsLinked = false,
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

    /**
     * WHAT A PAGE WOULD CHARGE FOR: the payable lines' type, id, quantity, unit price
     * and answers, plus the currency and the payee. An open page is reused only when
     * this matches, because the total alone does not identify a basket — a $50 gift
     * to one fund and a $50 gift to another price the same, and handing the second
     * basket the first basket's page books the gift to the wrong fund.
     */
    public function chargeFingerprint(): string
    {
        $lines = [];

        foreach ($this->lines as ['item' => $item, 'outcome' => $outcome]) {
            if (! $outcome->isPayable()) {
                continue;
            }

            $lines[] = [
                (string) $item->buyable_type,
                (int) $item->buyable_id,
                (string) $item->recorded_as,
                $outcome->quantity,
                $outcome->unitAmountMinor,
                self::canonical($item->payload),
            ];
        }

        return hash('sha256', json_encode([$this->currency, (string) $this->destinationAccountId, $lines]));
    }

    /**
     * WHAT THE SHOPPER WAS SHOWN: every line, gone ones included, with its outcome.
     * acknowledge() takes this back and applies the changes only if the basket still
     * prices exactly this way — otherwise a price edited between the notice and the
     * shopper's "OK" would be adopted without their ever seeing it.
     */
    public function viewFingerprint(): string
    {
        $lines = [];

        foreach ($this->lines as ['item' => $item, 'outcome' => $outcome]) {
            $lines[] = [(int) $item->id, $outcome->status, $outcome->quantity, $outcome->unitAmountMinor];
        }

        return hash('sha256', json_encode([$this->totalMinor, $this->currency, (string) $this->refusal, $lines]));
    }

    /**
     * The hash of one basket line's payload, keys sorted at every level. Checkout stamps it
     * on the order line (`cart_payload_hash`); settlement compares it with the basket's own
     * lines to drop exactly the ones this order paid for.
     */
    public static function payloadHash(mixed $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload)));
    }

    /** A payload with its keys sorted at every level, so key order never changes a hash. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
