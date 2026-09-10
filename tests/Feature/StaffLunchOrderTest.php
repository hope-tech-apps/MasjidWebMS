<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orders taken by staff on the lunch board — admins, SuperAdmins and lunch
 * volunteers — without going through the public order link.
 *
 * Prices come from the menu, never the request; the online ordering window
 * does not apply (walk-ups happen after it closes); payment is recorded in
 * person; nobody is opted into texts; and the order says who took it.
 */
class StaffLunchOrderTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;
    private MealMenu $menu;
    private MealMenuItem $biryani;
    private MealMenuItem $water;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->org();
        $this->admin = $this->staff($this->masjid, 'MasjidAdmin', 'masjid-admin');
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $this->biryani = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Chicken Biryani Plate', 'price_minor' => 800,
        ]);
        $this->water = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Water', 'price_minor' => 100, 'max_quantity' => 2,
        ]);
    }

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Lunch Org ' . uniqid(),
            'email' => 'lunch' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
        ]);
    }

    private function staff(Masjid $masjid, string $type, string $role): User
    {
        $user = User::factory()->create(['type' => $type, 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => $role, 'is_default' => true]);

        return $user->fresh();
    }

    /** Form-encoded, exactly as the board posts it. */
    private function order(string $prefix, array $fields)
    {
        return $this->post("{$prefix}/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders", $fields, ['Accept' => 'application/json']);
    }

    #[Test]
    public function an_admin_takes_a_pay_at_pickup_order_priced_by_the_server(): void
    {
        Sanctum::actingAs($this->admin);

        $this->order('/api/admin', [
            'customer_name' => 'Walk-up Brother',
            'items' => [
                ['item_id' => $this->biryani->id, 'quantity' => 2],
                ['item_id' => $this->water->id, 'quantity' => 1],
            ],
            'paid' => '0',
            // Ignored: prices never come from the request.
            'total_minor' => 1, 'unit_price_minor' => 1,
        ])->assertCreated()->assertJsonPath('data.order_number', '001');

        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(1700, $order->total_minor);
        $this->assertSame(MealOrder::SOURCE_STAFF, $order->source);
        $this->assertSame((int) $this->admin->id, $order->entered_by_user_id);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame(MealOrder::METHOD_PICKUP, $order->payment_method);
        $this->assertSame(MealOrder::STATUS_PENDING, $order->status);
        $this->assertSame(0, (int) $order->fee_covered_minor);
    }

    #[Test]
    public function a_lunch_volunteer_takes_a_cash_order_at_the_table(): void
    {
        $volunteer = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');
        Sanctum::actingAs($volunteer);

        $this->order('/api/lunch', [
            'customer_name' => 'Sister at the table',
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'paid' => '1',
        ])->assertCreated();

        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(MealOrder::STATUS_CONFIRMED, $order->status);
        $this->assertSame((int) $volunteer->id, $order->entered_by_user_id);
    }

    #[Test]
    public function a_super_admin_can_add_an_order_too(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550005555'])->fresh());

        $this->order('/api/admin', [
            'customer_name' => 'Phone order', 'customer_phone' => '+1 555 000 1234',
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 3]],
            'paid' => 'false',
        ])->assertCreated();

        $this->assertSame(2400, MealOrder::withoutMasjidScope()->value('total_minor'));
    }

    #[Test]
    public function staff_can_still_take_orders_after_online_ordering_closes_but_not_on_a_draft(): void
    {
        Sanctum::actingAs($this->admin);
        $one = ['customer_name' => 'After Jummah', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]], 'paid' => '1'];

        $this->menu->update(['status' => MealMenu::STATUS_CLOSED]);
        $this->order('/api/admin', $one)->assertCreated();

        $this->menu->update(['status' => MealMenu::STATUS_DRAFT]);
        $this->order('/api/admin', $one)->assertStatus(422);
    }

    #[Test]
    public function items_must_be_on_this_menu_available_and_within_the_cap(): void
    {
        Sanctum::actingAs($this->admin);
        $otherMenu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $elsewhere = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $otherMenu->id, 'name' => 'Other plate', 'price_minor' => 900,
        ]);
        $soldOut = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id, 'name' => 'Sold out', 'price_minor' => 700, 'is_available' => false,
        ]);

        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [['item_id' => $elsewhere->id, 'quantity' => 1]], 'paid' => '0'])->assertStatus(422);
        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [['item_id' => $soldOut->id, 'quantity' => 1]], 'paid' => '0'])->assertStatus(422);
        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [['item_id' => $this->water->id, 'quantity' => 3]], 'paid' => '0'])->assertStatus(422);
        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [], 'paid' => '0'])->assertStatus(422);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function staff_orders_never_opt_anyone_into_texts(): void
    {
        Sanctum::actingAs($this->admin);

        $this->order('/api/admin', [
            'customer_name' => 'No consent given', 'customer_phone' => '+15550009999',
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]], 'paid' => '0',
            'notify_sms' => '1',
        ])->assertCreated();

        // The staff door has no SMS path at all: the controller never reads
        // notify_sms, so nothing downstream can have recorded a consent.
        $this->assertStringNotContainsString(
            'LunchSmsOptIn',
            file_get_contents(app_path('Http/Controllers/AdminDashboard/MealOrdersController.php'))
        );
    }

    #[Test]
    public function the_board_shows_who_took_each_order(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', ['customer_name' => 'Walk-up', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]], 'paid' => '1'])->assertCreated();

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.source', 'staff')
            ->assertJsonPath('data.orders.0.entered_by.name', $this->admin->name);
    }

    #[Test]
    public function another_organisation_cannot_add_to_this_menu(): void
    {
        $other = $this->org();
        Sanctum::actingAs($this->staff($other, 'MasjidAdmin', 'masjid-admin'));

        // Through its own organisation's URL: this menu is not in its tenant.
        $this->post("/api/admin/masjids/{$other->id}/jummah-lunch/menus/{$this->menu->id}/orders",
            ['customer_name' => 'X', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]], 'paid' => '1'],
            ['Accept' => 'application/json'])->assertNotFound();

        // Through this organisation's URL: the tenant gate refuses.
        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]], 'paid' => '1'])
            ->assertForbidden();

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }
}
