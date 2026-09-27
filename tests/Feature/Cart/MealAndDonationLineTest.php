<?php

namespace Tests\Feature\Cart;

use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Services\Cart\Sources\DonationLineSource;
use App\Services\Cart\Sources\MealLineSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Re-checking the food and donation lines of a basket (universal cart,
 * DECISIONS 2026-09-26).
 *
 * Food is one source for both of the owner's "kitchen" and "Friday lunch",
 * because MealMenu is one model with two kinds: KIND_DATED is the Jummah lunch
 * with a cutoff, KIND_CATALOGUE is the standing catalogue with a pickup lead
 * time. A donation has no price to re-read — what it has is a fund that can stop
 * collecting between filling the basket and paying.
 */
class MealAndDonationLineTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => 'acct_test_cart',
            'stripe_charges_enabled' => true,
            'timezone' => 'America/New_York',
        ]);
    }

    private function menu(array $overrides = []): MealMenu
    {
        return MealMenu::create(array_merge([
            'masjid_id' => $this->makeOrg()->id,
            'title' => 'Halal Kitchen',
            'kind' => MealMenu::KIND_CATALOGUE,
            'status' => MealMenu::STATUS_OPEN,
            'ordering_closes_at' => null,
            'allow_online_payment' => true,
        ], $overrides));
    }

    private function dish(MealMenu $menu, array $overrides = []): MealMenuItem
    {
        return MealMenuItem::create(array_merge([
            'masjid_id' => $menu->masjid_id,
            'meal_menu_id' => $menu->id,
            'name' => 'Baked Lamb with Potatoes',
            'price_minor' => 1200,
            'is_available' => true,
        ], $overrides));
    }

    #[Test]
    public function an_open_catalogue_dish_at_the_expected_price_is_payable(): void
    {
        $item = $this->dish($this->menu());

        $outcome = (new MealLineSource)->reprice($item, 2, 1200, now()->addDays(3));

        $this->assertSame('available', $outcome->status);
        $this->assertSame(2400, $outcome->totalMinor());
    }

    #[Test]
    public function a_menu_that_stopped_taking_orders_is_dropped(): void
    {
        $item = $this->dish($this->menu(['ordering_closes_at' => now()->subMinute()]));

        $outcome = (new MealLineSource)->reprice($item, 1, 1200, now()->addDays(3));

        $this->assertSame('gone', $outcome->status);
        $this->assertSame(0, $outcome->totalMinor());
        $this->assertNotEmpty($outcome->reason);
    }

    #[Test]
    public function a_closed_friday_lunch_menu_is_dropped(): void
    {
        $menu = $this->menu([
            'kind' => MealMenu::KIND_DATED,
            'status' => MealMenu::STATUS_CLOSED,
            'service_date' => now()->addDays(2)->toDateString(),
        ]);

        $outcome = (new MealLineSource)->reprice($this->dish($menu), 1, 1200);

        $this->assertSame('gone', $outcome->status);
    }

    #[Test]
    public function a_dish_that_sold_out_is_dropped(): void
    {
        $item = $this->dish($this->menu(), ['is_available' => false]);

        $outcome = (new MealLineSource)->reprice($item, 1, 1200, now()->addDays(3));

        $this->assertSame('gone', $outcome->status);
    }

    #[Test]
    public function a_pay_at_pickup_only_menu_cannot_be_paid_for_in_a_basket(): void
    {
        // Every other online path refuses this. A basket is an online payment, so
        // it must not become the one way around the setting.
        $item = $this->dish($this->menu(['allow_online_payment' => false]));

        $outcome = (new MealLineSource)->reprice($item, 1, 1200, now()->addDays(3));

        $this->assertSame('gone', $outcome->status);
        $this->assertSame(0, $outcome->totalMinor());
    }

    #[Test]
    public function a_pickup_that_is_now_inside_the_kitchens_lead_time_is_dropped(): void
    {
        // Filled the basket for a pickup two days out, paid late: the lead time
        // (48h by default) now bites, and the office cannot cook it.
        $item = $this->dish($this->menu(['pickup_lead_hours' => 48]));

        $outcome = (new MealLineSource)->reprice($item, 1, 1200, now()->addHours(3));

        $this->assertSame('gone', $outcome->status);
        $this->assertNotEmpty($outcome->reason);
    }

    #[Test]
    public function a_dated_menu_ignores_a_pickup_time_because_it_has_one_service_date(): void
    {
        $menu = $this->menu([
            'kind' => MealMenu::KIND_DATED,
            'service_date' => now()->addDays(2)->toDateString(),
        ]);

        // A pickup inside the lead time would drop a CATALOGUE line; a dated menu
        // has nothing for the customer to choose, so it must not be refused for it.
        $outcome = (new MealLineSource)->reprice($this->dish($menu), 1, 1200, now()->addHours(1));

        $this->assertSame('available', $outcome->status);
    }

    #[Test]
    public function a_dish_whose_price_was_edited_is_charged_at_the_new_price_and_flagged(): void
    {
        $item = $this->dish($this->menu(), ['price_minor' => 1500]);

        $outcome = (new MealLineSource)->reprice($item, 2, 1200, now()->addDays(3));

        $this->assertSame('repriced', $outcome->status);
        $this->assertSame(1500, $outcome->unitAmountMinor);
        $this->assertSame(3000, $outcome->totalMinor());
    }

    #[Test]
    public function over_the_kitchens_cap_a_catalogue_line_is_refused_like_the_kitchen_door(): void
    {
        // KitchenOrdersController refuses an order over max_quantity; the basket must too.
        $item = $this->dish($this->menu(), ['max_quantity' => 3]);

        $outcome = (new MealLineSource)->reprice($item, 5, 1200, now()->addDays(3));

        $this->assertSame('gone', $outcome->status);
        $this->assertSame(0, $outcome->totalMinor());
        $this->assertStringContainsString('Only 3', $outcome->reason);
    }

    #[Test]
    public function over_the_cap_a_friday_lunch_line_is_clamped_and_the_shopper_is_told(): void
    {
        // JummahLunchOrdersController clamps to max_quantity. The basket clamps too,
        // but never silently: five chosen, three charged, and the reason says so.
        $menu = $this->menu([
            'kind' => MealMenu::KIND_DATED,
            'service_date' => now()->addDays(2)->toDateString(),
        ]);
        $item = $this->dish($menu, ['max_quantity' => 3]);

        $outcome = (new MealLineSource)->reprice($item, 5, 1200);

        $this->assertSame('repriced', $outcome->status, 'a clamp must be flagged, not passed off as "available"');
        $this->assertSame(3, $outcome->quantity);
        $this->assertSame(3600, $outcome->totalMinor(), 'charged for the three allowed, never the five chosen');
        $this->assertStringContainsString('reduced to 3', $outcome->reason);
    }

    #[Test]
    public function within_the_cap_nothing_changes(): void
    {
        $item = $this->dish($this->menu(), ['max_quantity' => 3]);

        $outcome = (new MealLineSource)->reprice($item, 3, 1200, now()->addDays(3));

        $this->assertSame('available', $outcome->status);
        $this->assertSame(3600, $outcome->totalMinor());
    }

    #[Test]
    public function a_donation_to_an_open_fund_is_payable_at_the_amount_the_donor_set(): void
    {
        $fund = Fund::create([
            'masjid_id' => $this->makeOrg()->id,
            'name' => 'Zakat-ul-Fitr',
            'type' => 'fitra',
            'is_active' => true,
        ]);

        $outcome = (new DonationLineSource)->reprice($fund, 5000);

        $this->assertSame('available', $outcome->status);
        $this->assertSame(5000, $outcome->totalMinor());
        $this->assertSame(1, $outcome->quantity, 'a $50 gift is one line of $50, never 50 lines of $1');
    }

    #[Test]
    public function a_donation_to_a_fund_that_stopped_collecting_is_dropped(): void
    {
        $fund = Fund::create([
            'masjid_id' => $this->makeOrg()->id,
            'name' => 'Retired Appeal',
            'type' => 'sadaqah',
            'is_active' => false,
        ]);

        $outcome = (new DonationLineSource)->reprice($fund, 5000);

        $this->assertSame('gone', $outcome->status);
        $this->assertSame(0, $outcome->totalMinor());
    }

    #[Test]
    public function a_donation_of_nothing_is_refused(): void
    {
        $fund = Fund::create([
            'masjid_id' => $this->makeOrg()->id,
            'name' => 'General',
            'type' => 'general',
            'is_active' => true,
        ]);

        $this->assertSame('gone', (new DonationLineSource)->reprice($fund, 0)->status);
        $this->assertSame('gone', (new DonationLineSource)->reprice($fund, -100)->status);
    }
}
