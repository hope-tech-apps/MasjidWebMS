<?php

namespace App\Support;

/**
 * Stripe's processing fee, and the gross-up that lets a payer absorb it.
 *
 * The formula lived only in DonationService until the Jummah-lunch order form
 * needed the same offer. A second hand-written copy of a money calculation is
 * how two parts of one system quietly start charging different amounts, so the
 * arithmetic lives here and DonationService delegates to it — its public
 * signatures are unchanged.
 *
 * IMPORTANT: this is STRIPE's fee, which on a Connect DIRECT charge is deducted
 * from the connected organisation's own balance. The platform never bills it and
 * never receives it — `platform_fee_percentage` is a separate thing entirely.
 */
final class StripeFees
{
    public static function percentage(?float $override = null): float
    {
        return $override ?? (float) config('services.stripe.fee_percentage', 0.029);
    }

    public static function fixed(?int $override = null): int
    {
        return $override ?? (int) config('services.stripe.fee_fixed', 30);
    }

    /**
     * The charge needed for the organisation to NET `$intended`.
     *
     * Stripe deducts `rate * charged + fixed` from the charge, so
     *
     *     charged - (rate * charged + fixed) = intended
     *   ⇒ charged * (1 - rate) = intended + fixed
     *   ⇒ charged = (intended + fixed) / (1 - rate)
     *
     * e.g. $8.00 (800¢) @ 2.9% + 30¢ ⇒ round(830 / 0.971) = 855¢ = $8.55, whose
     * fee is 55¢ and whose net lands back on exactly $8.00.
     */
    public static function grossUp(int $intended, ?float $feePercentage = null, ?int $feeFixed = null): int
    {
        if ($intended <= 0) {
            return 0;
        }

        return (int) round(($intended + self::fixed($feeFixed)) / (1 - self::percentage($feePercentage)));
    }

    /**
     * What the payer adds so the organisation nets `$intended` — the gross-up
     * expressed as the surcharge alone, which is what a form has to show.
     */
    public static function coverage(int $intended, ?float $feePercentage = null, ?int $feeFixed = null): int
    {
        return max(0, self::grossUp($intended, $feePercentage, $feeFixed) - $intended);
    }

    /**
     * Stripe's fee ON a charge: `rate * charged + fixed`, whole minor units —
     * the same shape grossUp() inverts. A deterministic stand-in for when the
     * real balance-transaction fee is not on the payload yet; the balance
     * transaction remains the source of truth.
     */
    public static function on(int $charged, ?float $feePercentage = null, ?int $feeFixed = null): int
    {
        return (int) round($charged * self::percentage($feePercentage)) + self::fixed($feeFixed);
    }
}
