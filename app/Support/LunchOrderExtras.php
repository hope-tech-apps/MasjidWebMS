<?php

namespace App\Support;

use App\Models\MealMenu;
use App\Models\MealOrder;

/**
 * The two optional amounts a Jummah-lunch order can carry on top of the food:
 * an extra the payer chooses, and Stripe's card fee if they choose to cover it.
 *
 * One rule for every door an order comes through — the public order page and
 * the staff board — because two hand-written copies of a money calculation are
 * how two parts of one system quietly start charging different amounts:
 *   - the extra is clamped to MealOrder::MAX_DONATION_MINOR, floored at 0, and
 *     forced to 0 when the menu does not offer it (hiding an input does not
 *     stop a crafted body from carrying one);
 *   - the fee is a yes/no. Its AMOUNT is the gross-up StripeFees derives from
 *     the published rate, so the organisation nets the food plus the extra in
 *     full — never a figure from the body. ONLINE ONLY: a pay-at-pickup order
 *     never touches Stripe, so there is nothing to cover.
 */
final class LunchOrderExtras
{
    /** @return array{donation_minor: int, fee_covered_minor: int} */
    public static function compute(
        MealMenu $menu,
        int $subtotalMinor,
        int $requestedDonationMinor,
        bool $coverFees,
        bool $online
    ): array {
        $donation = $menu->allow_donation
            ? max(0, min($requestedDonationMinor, MealOrder::MAX_DONATION_MINOR))
            : 0;

        $fee = ($menu->allow_fee_coverage && $online && $coverFees)
            ? StripeFees::coverage($subtotalMinor + $donation)
            : 0;

        return ['donation_minor' => $donation, 'fee_covered_minor' => $fee];
    }

    /**
     * The same two amounts for an order that already exists, when staff make it
     * a new payment page. A choice staff were not asked about keeps what the
     * order carries — one left out of the request, or an option the menu has
     * stopped offering since the order was placed — because the customer agreed
     * to it, and dropping it would close the page they hold for a cheaper one.
     * Unchanged choices come back as the stored amounts to the cent, so they are
     * never a new price. Online by definition: this is always for a Stripe page.
     *
     * @return array{donation_minor: int, fee_covered_minor: int}
     */
    public static function forExistingOrder(
        MealMenu $menu,
        MealOrder $order,
        ?int $requestedDonationMinor,
        ?bool $coverFees
    ): array {
        $storedDonation = (int) $order->donation_minor;
        $storedFee = (int) $order->fee_covered_minor;

        $donation = ($menu->allow_donation && $requestedDonationMinor !== null)
            ? max(0, min($requestedDonationMinor, MealOrder::MAX_DONATION_MINOR))
            : $storedDonation;

        $cover = ($menu->allow_fee_coverage && $coverFees !== null)
            ? $coverFees
            : $storedFee > 0;

        if ($donation === $storedDonation && $cover === ($storedFee > 0)) {
            return ['donation_minor' => $storedDonation, 'fee_covered_minor' => $storedFee];
        }

        return [
            'donation_minor' => $donation,
            'fee_covered_minor' => $cover ? StripeFees::coverage((int) $order->subtotal_minor + $donation) : 0,
        ];
    }
}
