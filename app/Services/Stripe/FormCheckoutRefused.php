<?php

namespace App\Services\Stripe;

use RuntimeException;

/**
 * A card payment for a form response that may not be opened — or a payment page that
 * could not be closed — in words a payer or an admin can act on
 * (FormResponseCheckoutService).
 *
 * Its own type so the public controllers show exactly these messages and nothing else:
 * a bare RuntimeException also covers a database error (QueryException is one), and
 * that message carries SQL. Still a RuntimeException, so a caller written against the
 * MealOrderCheckoutService sibling's contract catches it unchanged.
 *
 * One refusal means the payer has already paid: paidOnStripe(), a page Stripe says was
 * paid while the signed webhook has not recorded it yet. answer() marks it on the public
 * 422, so the page waits for the webhook instead of offering to pay again.
 */
final class FormCheckoutRefused extends RuntimeException
{
    private bool $confirming = false;

    /**
     * Stripe says the page was paid, and only the webhook records it
     * (FormResponseCheckoutService::PAID_ON_STRIPE). The row is still unpaid here.
     */
    public static function paidOnStripe(): self
    {
        $refusal = new self(FormResponseCheckoutService::PAID_ON_STRIPE);
        $refusal->confirming = true;

        return $refusal;
    }

    public function isConfirming(): bool
    {
        return $this->confirming;
    }

    /**
     * The row's answer as a public 422 carries it. A payment Stripe holds and the webhook
     * has not recorded adds `confirming: true` and `can_pay: false`: the page shows
     * "confirming" and reads the status until the webhook lands, never a button to pay
     * again. Every other refusal is answered unchanged. Both public doors answer through
     * this (the submit's replay and "Return to payment"), so they cannot disagree.
     *
     * @param  array<string,mixed>  $data  the row as the page is told about it
     * @return array<string,mixed>
     */
    public function answer(array $data): array
    {
        return $this->confirming ? array_merge($data, ['confirming' => true, 'can_pay' => false]) : $data;
    }
}
