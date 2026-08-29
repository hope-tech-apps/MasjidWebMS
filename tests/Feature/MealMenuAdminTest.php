<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin: Jummah-lunch menus, items and the order board over HTTP at
 * /api/admin/masjids/{masjid_id}/jummah-lunch/... .
 *
 * Same two-path isolation as the offering suite: targeting B's masjid in the
 * route is a 403 (ResolveMasjidTenant), B's id under A's own route is a 404
 * (the BelongsToMasjid scope makes findOrFail miss). On top, this pins the one
 * money-safety rule of the board: an ONLINE order cannot be marked paid by hand.
 */
class MealMenuAdminTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);
    }

    private function base(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/jummah-lunch';
    }

    #[Test]
    public function it_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->base() . '/menus')->assertStatus(401);
    }

    #[Test]
    public function an_admin_creates_a_menu_adds_an_item_and_opens_it(): void
    {
        Sanctum::actingAs($this->adminA);

        $menuId = $this->postJson($this->base() . '/menus', [
            'title' => 'Jummah Lunch',
            'service_date' => '2027-02-05',
            'allow_online_payment' => true,
            'allow_pay_at_pickup' => true,
        ])->assertStatus(201)->json('data.id');

        $this->postJson($this->base() . "/menus/{$menuId}/items", [
            'name' => 'Chicken Biryani Plate',
            'price_minor' => 800,
        ])->assertStatus(201);

        $this->putJson($this->base() . "/menus/{$menuId}", [
            'status' => MealMenu::STATUS_OPEN,
        ])->assertOk()->assertJsonPath('data.status', MealMenu::STATUS_OPEN);

        $menu = MealMenu::withoutMasjidScope()->find($menuId);
        $this->assertSame($this->masjidA->id, (int) $menu->masjid_id);
        $this->assertSame(1, $menu->items()->count());
    }

    #[Test]
    public function the_order_board_returns_orders_and_a_summary(): void
    {
        $menu = MealMenu::factory()->forMasjid($this->masjidA)->open()->create();
        MealOrder::factory()->paid()->create(['masjid_id' => $this->masjidA->id, 'meal_menu_id' => $menu->id, 'total_minor' => 800]);
        MealOrder::factory()->create(['masjid_id' => $this->masjidA->id, 'meal_menu_id' => $menu->id, 'total_minor' => 900]);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->base() . "/menus/{$menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.summary.orders', 2)
            ->assertJsonPath('data.summary.paid_orders', 1)
            ->assertJsonPath('data.summary.revenue_paid_minor', 800)
            ->assertJsonCount(2, 'data.orders');
    }

    #[Test]
    public function a_pickup_order_can_be_marked_paid_but_an_online_one_cannot(): void
    {
        $menu = MealMenu::factory()->forMasjid($this->masjidA)->open()->create();
        $pickup = MealOrder::factory()->create(['masjid_id' => $this->masjidA->id, 'meal_menu_id' => $menu->id]);
        $online = MealOrder::factory()->online()->create(['masjid_id' => $this->masjidA->id, 'meal_menu_id' => $menu->id]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->base() . "/menus/{$menu->id}/orders/{$pickup->id}/mark-paid")
            ->assertOk();
        $this->assertSame(MealOrder::PAYMENT_PAID, $pickup->refresh()->payment_status);

        $this->postJson($this->base() . "/menus/{$menu->id}/orders/{$online->id}/mark-paid")
            ->assertStatus(422);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $online->refresh()->payment_status);
    }

    #[Test]
    public function an_order_can_be_moved_to_picked_up(): void
    {
        $menu = MealMenu::factory()->forMasjid($this->masjidA)->open()->create();
        $order = MealOrder::factory()->create(['masjid_id' => $this->masjidA->id, 'meal_menu_id' => $menu->id]);

        Sanctum::actingAs($this->adminA);

        $this->putJson($this->base() . "/menus/{$menu->id}/orders/{$order->id}/status", [
            'status' => MealOrder::STATUS_PICKED_UP,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(MealOrder::STATUS_PICKED_UP, $order->status);
        $this->assertNotNull($order->picked_up_at);
    }

    #[Test]
    public function another_organizations_menu_is_a_404(): void
    {
        $menuB = MealMenu::factory()->forMasjid($this->masjidB)->create();

        Sanctum::actingAs($this->adminA);

        // A's own route, B's menu id — the scope makes it miss.
        $this->getJson($this->base() . "/menus/{$menuB->id}")->assertStatus(404);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }
}
