<?php

namespace Tests\Unit;

use App\Services\Stripe\DonationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the donor-covers-fees gross-up (and its inverse fee formula).
 *
 * All amounts are integer minor units (cents). The core property: when the
 * donor elects to cover fees, the org must still NET the intended amount after
 * Stripe deducts its processing fee. See DonationService::grossUp().
 */
class DonorCoversFeesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin Stripe's standard fee so the config-default path is deterministic.
        config([
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
            'services.stripe.platform_fee_percentage' => 0,
        ]);
    }

    #[Test]
    public function grosses_up_100_dollars_to_103_30_at_2_9_percent_plus_30c(): void
    {
        // intended $100.00 → round((10000 + 30) / (1 - 0.029)) = 10330 = $103.30
        $this->assertSame(10330, DonationService::grossUp(10000));
    }

    #[Test]
    public function net_after_stripe_fee_equals_intended_for_100_dollars(): void
    {
        $intended = 10000;

        $charged = DonationService::grossUp($intended);
        $fee = DonationService::computeStripeFee($charged);
        $net = $charged - $fee;

        // The whole point of the gross-up: the org nets exactly the intended
        // amount (rounding keeps it within a cent).
        $this->assertLessThanOrEqual(1, abs($net - $intended));
        $this->assertSame($intended, $net);
    }

    #[Test]
    public function net_after_stripe_fee_equals_intended_for_a_small_gift(): void
    {
        $intended = 1000; // $10.00

        $charged = DonationService::grossUp($intended);
        $fee = DonationService::computeStripeFee($charged);

        $this->assertSame($intended, $charged - $fee);
    }

    #[Test]
    public function compute_stripe_fee_matches_the_expected_charge_breakdown(): void
    {
        // 2.9% of 10330 = 299.57 → 300, + 30 fixed = 330.
        $this->assertSame(330, DonationService::computeStripeFee(10330));
    }

    #[Test]
    public function gross_up_honours_explicit_rate_overrides_without_config(): void
    {
        // Deterministic regardless of config: 5% + 25c.
        // round((20000 + 25) / (1 - 0.05)) = round(20025 / 0.95) = 21079.
        $this->assertSame(21079, DonationService::grossUp(20000, 0.05, 25));
    }

    #[Test]
    public function application_fee_is_zero_by_default(): void
    {
        // Platform fee defaults to 0 for the spike, so no application_fee is sent.
        $this->assertSame(0, DonationService::applicationFee(10000));
    }

    #[Test]
    public function application_fee_scales_with_configured_platform_percentage(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0.01]);

        // 1% of $100.00 = 100¢.
        $this->assertSame(100, DonationService::applicationFee(10000));
    }
}
