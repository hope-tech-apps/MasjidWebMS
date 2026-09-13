<?php

namespace Tests\Unit;

use App\Services\Stripe\DonationService;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\StripeFees;
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

    /**
     * BISS Sunday School's family prices with the required card fee (2026-09-13): the
     * card fee and the charge for 1..5 children, and the school netting exactly the tier
     * price — at the configured 2.9% + 30¢ AND a platform fee of 0, which is what
     * production runs with (STRIPE_PLATFORM_FEE_PERCENTAGE unset). The platform fee is
     * taken on the charge and is NOT grossed up, so above 0 the school nets less.
     */
    #[Test]
    public function the_family_price_table_nets_the_school_its_tier_price_only_at_a_platform_fee_of_zero(): void
    {
        $table = [
            10000 => [330, 10330],
            17000 => [539, 17539],
            25000 => [778, 25778],
            30000 => [927, 30927],
            35000 => [1076, 36076],
        ];

        foreach ($table as $tier => [$fee, $charged]) {
            $this->assertSame($fee, StripeFees::coverage($tier), "tier {$tier}");
            $this->assertSame($charged, StripeFees::grossUp($tier), "tier {$tier}");
            $this->assertSame($charged, DonationService::grossUp($tier), "tier {$tier}: the donation gross-up agrees");

            $platform = FormResponseCheckoutService::applicationFee($charged);

            $this->assertSame(0, $platform);
            $this->assertSame($tier, $charged - StripeFees::on($charged) - $platform, "tier {$tier}: the school nets it");

            // The caveat, pinned so nobody promises it at another platform rate.
            $this->assertLessThan($tier, $charged - StripeFees::on($charged) - FormResponseCheckoutService::applicationFee($charged, 0.01));
        }
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
