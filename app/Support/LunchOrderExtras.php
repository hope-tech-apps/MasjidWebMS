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
}
