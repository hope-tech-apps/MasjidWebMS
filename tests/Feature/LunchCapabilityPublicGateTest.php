<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Friday lunch is an organisation capability. Switched off, the staff board is
 * closed (EnsureOrgCapability), so the PUBLIC page must stop taking orders too,
 * or customers pay for lunches nobody at the organisation can see or hand out.
 * An order already placed keeps its status page.
 */
class LunchCapabilityPublicGateTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private MealMenu $menu;
    private MealMenuItem $plate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant(); // the public path runs UNBOUND

        $this->masjid = Masjid::create([
            'name' => 'Lunch Gate Org ' . uniqid(),
            'email' => 'gate' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
        ]);
        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $this->plate = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Kabsah Plate', 'price_minor' => 800,
        ]);
    }

    private function header(): array
    {
        return ['masjid-id' => (string) $this->masjid->id];
    }

    private function switchLunch(bool $on): void
    {
        $this->masjid->forceFill(['capability_overrides' => ['jummah_lunch' => $on]])->save();
    }

    private function orderBody(): array
    {
        return [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->plate->id, 'quantity' => 1]],
            'customer_name' => 'Public Customer',
            'customer_phone' => '3365551234',
            'payment_method' => 'pickup',
        ];
    }

    #[Test]
    public function switching_lunch_off_closes_the_public_menu_and_ordering_but_not_order_status(): void
    {
        // On (a masjid's default): the page works and takes an order.
        $this->getJson('/api/v1/lunch-menu', $this->header())->assertOk();
        $uuid = $this->postJson('/api/v1/lunch-orders', $this->orderBody(), $this->header())->assertOk()->json('data.order.uuid');
        $this->assertNotEmpty($uuid);

        $this->switchLunch(false);

        $this->getJson('/api/v1/lunch-menu', $this->header())->assertNotFound();
        $this->postJson('/api/v1/lunch-orders', $this->orderBody(), $this->header())->assertNotFound();
        $this->assertSame(1, MealOrder::withoutMasjidScope()->count());

        // A customer can still check the order they already placed.
        $this->getJson("/api/v1/lunch-orders/{$uuid}", $this->header())->assertOk();
    }

    #[Test]
    public function a_school_has_no_public_lunch_until_it_is_switched_on(): void
    {
        $this->masjid->forceFill(['org_type' => 'school'])->save();
        $this->getJson('/api/v1/lunch-menu', $this->header())->assertNotFound();

        $this->switchLunch(true);
        $this->getJson('/api/v1/lunch-menu', $this->header())->assertOk();
    }
}
