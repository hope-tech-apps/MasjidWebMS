<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mandatory cross-tenant guardrail for the meal-ordering models
 * (.claude/rules/tenant-scoping.md): a bound tenant sees only its own rows, the
 * creating hook stamps the bound tenant over client input, and
 * withoutMasjidScope() is the one documented bypass.
 */
class MealOrderTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;

    private Masjid $masjidB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();          // every test starts UNBOUND

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    #[Test]
    public function a_bound_tenant_sees_only_its_own_menus(): void
    {
        $a = MealMenu::factory()->forMasjid($this->masjidA)->create();
        $b = MealMenu::factory()->forMasjid($this->masjidB)->create();

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, MealMenu::count());
        $this->assertNotNull(MealMenu::find($a->id));
        $this->assertNull(MealMenu::find($b->id), 'another masjid\'s menu must resolve to null, not leak');
    }

    #[Test]
    public function create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        // A client-supplied masjid_id must never win.
        $menu = MealMenu::create([
            'masjid_id' => $this->masjidB->id,
            'title' => 'Jummah Lunch',
            'service_date' => '2027-01-01',
        ]);

        $this->assertSame($this->masjidA->id, (int) $menu->masjid_id);
    }

    #[Test]
    public function orders_and_items_are_scoped_to_their_masjid(): void
    {
        $menuA = MealMenu::factory()->forMasjid($this->masjidA)->create();
        $orderA = MealOrder::factory()->create(['masjid_id' => $this->masjidA->id, 'meal_menu_id' => $menuA->id]);
        MealOrderItem::factory()->create(['masjid_id' => $this->masjidA->id, 'meal_order_id' => $orderA->id]);
        MealMenuItem::factory()->create(['masjid_id' => $this->masjidA->id, 'meal_menu_id' => $menuA->id]);

        $menuB = MealMenu::factory()->forMasjid($this->masjidB)->create();
        $orderB = MealOrder::factory()->create(['masjid_id' => $this->masjidB->id, 'meal_menu_id' => $menuB->id]);
        MealMenuItem::factory()->create(['masjid_id' => $this->masjidB->id, 'meal_menu_id' => $menuB->id]);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, MealOrder::count());
        $this->assertNull(MealOrder::find($orderB->id));
        $this->assertSame(1, MealMenuItem::count(), 'only masjid A\'s items are visible');
    }

    #[Test]
    public function without_masjid_scope_sees_every_tenant(): void
    {
        MealMenu::factory()->forMasjid($this->masjidA)->create();
        MealMenu::factory()->forMasjid($this->masjidB)->create();

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, MealMenu::count());
        $this->assertSame(2, MealMenu::withoutMasjidScope()->count());
    }

    #[Test]
    public function find_by_uuid_for_masjid_refuses_a_foreign_uuid(): void
    {
        $b = MealMenu::factory()->forMasjid($this->masjidB)->create();

        // Even asked for masjid A's context, B's uuid resolves only under B.
        $this->assertNull(MealMenu::findByUuidForMasjid($b->uuid, $this->masjidA->id));
        $this->assertNotNull(MealMenu::findByUuidForMasjid($b->uuid, $this->masjidB->id));
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Masjid ' . uniqid(),
            'email' => 'office' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
    }
}
