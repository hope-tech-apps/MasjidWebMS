<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\User;
use App\Services\Lunch\LunchOpeningNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin: creating and running a kitchen CATALOGUE on the meal-ordering board.
 *
 * A catalogue has no service date and always a lead time; its kind is fixed at
 * creation; its office addresses are checked when saved; it is never announced
 * as a Friday lunch; and the board shows each order's pickup on the
 * organisation's own clock. Friday menus behave exactly as before.
 */
class KitchenCatalogueAdminTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Catalogue Test Org ' . uniqid(),
            'email' => 'office' . uniqid() . '@org.example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
            'timezone' => 'America/New_York',
        ]);

        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        Sanctum::actingAs($this->admin);
    }

    private function base(): string
    {
        return '/api/admin/masjids/' . $this->masjid->id . '/jummah-lunch';
    }

    #[Test]
    public function a_catalogue_is_created_without_a_service_date_and_with_the_default_lead_time(): void
    {
        $id = $this->postJson($this->base() . '/menus', [
            'title' => 'Halal Kitchen',
            'kind' => MealMenu::KIND_CATALOGUE,
            // A date sent anyway is not kept: a catalogue has none.
            'service_date' => '2026-10-02',
        ])->assertStatus(201)
            ->assertJsonPath('data.kind', MealMenu::KIND_CATALOGUE)
            ->assertJsonPath('data.pickup_lead_hours', 48)
            ->json('data.id');

        $menu = MealMenu::withoutMasjidScope()->find($id);
        $this->assertNull($menu->service_date);
        $this->assertTrue($menu->isCatalogue());
    }

    #[Test]
    public function two_catalogues_can_live_beside_this_fridays_lunch(): void
    {
        $this->postJson($this->base() . '/menus', ['title' => 'Kitchen A', 'kind' => 'catalogue'])->assertStatus(201);
        $this->postJson($this->base() . '/menus', ['title' => 'Kitchen B', 'kind' => 'catalogue', 'pickup_lead_hours' => 72])
            ->assertStatus(201)->assertJsonPath('data.pickup_lead_hours', 72);
        $this->postJson($this->base() . '/menus', ['title' => 'Jummah Lunch', 'service_date' => '2026-10-02'])
            ->assertStatus(201)->assertJsonPath('data.kind', MealMenu::KIND_DATED);

        $this->getJson($this->base() . '/menus')->assertOk()->assertJsonCount(3, 'data');
    }

    #[Test]
    public function a_friday_menu_still_needs_its_date_and_never_carries_a_lead_time(): void
    {
        $this->postJson($this->base() . '/menus', ['title' => 'Jummah Lunch'])->assertStatus(422);

        $id = $this->postJson($this->base() . '/menus', ['title' => 'Jummah Lunch', 'service_date' => '2026-10-09', 'pickup_lead_hours' => 48])
            ->assertStatus(201)->json('data.id');

        $this->assertNull(MealMenu::withoutMasjidScope()->find($id)->pickup_lead_hours);
    }

    #[Test]
    public function the_kind_cannot_be_changed_once_the_menu_exists(): void
    {
        $friday = MealMenu::factory()->forMasjid($this->masjid)->create();
        $kitchen = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create(['pickup_lead_hours' => 72]);

        $this->putJson($this->base() . "/menus/{$friday->id}", ['kind' => 'catalogue'])->assertOk();
        $this->putJson($this->base() . "/menus/{$kitchen->id}", ['kind' => 'dated', 'title' => 'Kitchen'])->assertOk();

        $this->assertSame(MealMenu::KIND_DATED, $friday->fresh()->kind);
        $this->assertSame(MealMenu::KIND_CATALOGUE, $kitchen->fresh()->kind);
        // An edit that does not mention the lead time leaves it alone.
        $this->assertSame(72, $kitchen->fresh()->pickup_lead_hours);
    }

    #[Test]
    public function office_addresses_are_checked_when_they_are_saved(): void
    {
        $kitchen = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create();

        $this->putJson($this->base() . "/menus/{$kitchen->id}", ['notify_emails' => 'office@org.example.test, not-an-address'])
            ->assertStatus(422);
        $this->assertNull($kitchen->fresh()->notify_emails);

        $this->putJson($this->base() . "/menus/{$kitchen->id}", ['notify_emails' => 'office@org.example.test, cook@org.example.test'])
            ->assertOk();
        $this->assertSame('office@org.example.test, cook@org.example.test', $kitchen->fresh()->notify_emails);
    }

    #[Test]
    public function a_lunch_volunteer_cannot_point_new_kitchen_orders_at_an_address(): void
    {
        $kitchen = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create(['notify_emails' => 'office@org.example.test']);
        $volunteer = User::factory()->create(['type' => User::TYPE_LUNCH_STAFF, 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->masjid->id, 'user_id' => $volunteer->id, 'role' => 'lunch-staff', 'is_default' => true]);
        Sanctum::actingAs($volunteer->fresh());
        $lunch = '/api/lunch/masjids/' . $this->masjid->id . '/jummah-lunch';

        // The rest of what they save still saves; the addresses do not.
        $this->putJson("{$lunch}/menus/{$kitchen->id}", ['notify_emails' => 'elsewhere@outside.example.test', 'title' => 'Kitchen (edited)'])
            ->assertOk();
        $this->assertSame('office@org.example.test', $kitchen->fresh()->notify_emails);
        $this->assertSame('Kitchen (edited)', $kitchen->fresh()->title);

        $id = $this->postJson("{$lunch}/menus", ['title' => 'Kitchen B', 'kind' => 'catalogue', 'notify_emails' => 'elsewhere@outside.example.test'])
            ->assertStatus(201)->json('data.id');
        $this->assertNull(MealMenu::withoutMasjidScope()->find($id)->notify_emails);
    }

    #[Test]
    public function a_date_sent_when_editing_a_catalogue_is_not_kept(): void
    {
        $kitchen = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create();

        $this->putJson($this->base() . "/menus/{$kitchen->id}", ['service_date' => '2026-10-09', 'title' => 'Kitchen'])->assertOk();

        $this->assertNull($kitchen->fresh()->service_date, 'a catalogue with a date would be served as that Friday');
    }

    #[Test]
    public function the_kitchen_columns_have_the_types_their_contents_need(): void
    {
        // An admin-typed list of addresses has no length the organisation agreed
        // to; SQLite would never refuse a VARCHAR overflow that MySQL would.
        $this->assertSame('text', Schema::getColumnType('meal_menus', 'notify_emails'));
        $this->assertSame('varchar', Schema::getColumnType('meal_menus', 'kind'));
        $this->assertSame('varchar', Schema::getColumnType('meal_orders', 'preferred_payment'));
        $this->assertSame('datetime', Schema::getColumnType('meal_orders', 'pickup_at'));
        $this->assertSame('datetime', Schema::getColumnType('meal_orders', 'confirmed_at'));
        $this->assertTrue(
            collect(Schema::getColumns('meal_menus'))->firstWhere('name', 'service_date')['nullable'],
            'a catalogue has no service date, so the column must take NULL'
        );
    }

    #[Test]
    public function opening_a_catalogue_announces_nothing(): void
    {
        // Everything a Friday menu needs to be announced — open, a service to text,
        // never announced — so only the kind can be what stops it.
        $kitchen = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create(['status' => MealMenu::STATUS_OPEN]);
        $kitchen->forceFill(['notify_service_id' => 424242])->save();

        $this->assertNull(app(LunchOpeningNotifier::class)->notifyOpened($kitchen->fresh()));
        $this->assertNull($kitchen->fresh()->opening_notified_at, 'a catalogue was claimed for a "lunch is open" text');
    }

    #[Test]
    public function the_board_shows_each_kitchen_pickup_on_the_organisations_own_clock(): void
    {
        $kitchen = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create(['status' => MealMenu::STATUS_OPEN]);
        $order = MealOrder::factory()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $kitchen->id]);
        $order->forceFill(['pickup_at' => Carbon::parse('2026-10-03 18:00:00', 'UTC')])->save();

        $this->getJson($this->base() . "/menus/{$kitchen->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.pickup_at_local', '2026-10-03T14:00');
    }
}
