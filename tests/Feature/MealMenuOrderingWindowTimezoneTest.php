<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Jummah-lunch ordering window, across the two clocks it lives between.
 *
 * Datetime columns are UTC instants; the admin filling in "ordering closes at"
 * is reading a wall clock in the masjid's own timezone, and an
 * <input type="datetime-local"> submits a naive string with no offset at all.
 * Storing that string verbatim closed a New York masjid's ordering four hours
 * early, and the edit form then redisplayed the UTC instant as if it were local
 * — so a round trip through the form moved the cutoff every single time.
 *
 * These pin BOTH directions plus the consequence that actually costs money:
 * whether ordering is open at 10:59 and shut at 11:01, local time.
 */
class MealMenuOrderingWindowTimezoneTest extends TestCase
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

        // The app clock is UTC in every environment; that is the whole point.
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Timezone Masjid ' . uniqid(),
            'email' => 'tz-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
            'timezone' => 'America/New_York',
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        Sanctum::actingAs($this->admin);
    }

    private function base(): string
    {
        return '/api/admin/masjids/' . $this->masjid->id . '/jummah-lunch';
    }

    private function createMenu(array $overrides = []): int
    {
        $res = $this->postJson($this->base() . '/menus', array_merge([
            'title' => 'Jummah Lunch',
            'service_date' => '2027-02-05',
        ], $overrides))->assertStatus(201);

        return (int) $res->json('data.id');
    }

    #[Test]
    public function a_naive_datetime_is_read_as_the_masjids_wall_clock(): void
    {
        // 11:00 in New York on a February date is EST (UTC-5) -> 16:00 UTC.
        $id = $this->createMenu(['ordering_closes_at' => '2027-02-05T11:00']);

        $this->assertSame(
            '2027-02-05 16:00:00',
            MealMenu::withoutMasjidScope()->find($id)->getRawOriginal('ordering_closes_at')
        );
    }

    #[Test]
    public function it_honours_daylight_saving_rather_than_a_fixed_offset(): void
    {
        // The same 11:00 in September is EDT (UTC-4) -> 15:00 UTC, not 16:00.
        // A hardcoded offset would silently drift by an hour for half the year.
        $id = $this->createMenu([
            'service_date' => '2027-09-10',
            'ordering_closes_at' => '2027-09-10T11:00',
        ]);

        $this->assertSame(
            '2027-09-10 15:00:00',
            MealMenu::withoutMasjidScope()->find($id)->getRawOriginal('ordering_closes_at')
        );
    }

    #[Test]
    public function a_datetime_carrying_its_own_offset_is_not_reinterpreted(): void
    {
        // Load-bearing: PHP's DateTime ignores the fallback timezone whenever the
        // input already carries an offset, so an API client sending a real instant
        // is stored as that instant and not shifted into New York a second time.
        $id = $this->createMenu(['ordering_closes_at' => '2027-02-05T16:00:00Z']);

        $this->assertSame(
            '2027-02-05 16:00:00',
            MealMenu::withoutMasjidScope()->find($id)->getRawOriginal('ordering_closes_at')
        );
    }

    #[Test]
    public function the_edit_form_reads_back_exactly_what_it_submitted(): void
    {
        // The regression that made this worse than a one-off: show() served the
        // UTC instant, the form sliced it to 16:00 and saved that back as local.
        $id = $this->createMenu(['ordering_closes_at' => '2027-02-05T11:00']);

        $shown = $this->getJson($this->base() . '/menus/' . $id)->assertOk();
        $this->assertSame('2027-02-05T11:00', $shown->json('data.ordering_closes_at_local'));
        $this->assertSame('America/New_York', $shown->json('data.timezone'));

        // Save the displayed value straight back, as an untouched edit does.
        $this->putJson($this->base() . '/menus/' . $id, [
            'ordering_closes_at' => $shown->json('data.ordering_closes_at_local'),
        ])->assertOk();

        $this->assertSame(
            '2027-02-05 16:00:00',
            MealMenu::withoutMasjidScope()->find($id)->getRawOriginal('ordering_closes_at')
        );
    }

    #[Test]
    public function the_index_carries_the_local_window_for_every_row(): void
    {
        $this->createMenu(['ordering_closes_at' => '2027-02-05T11:00']);

        $this->getJson($this->base() . '/menus')
            ->assertOk()
            ->assertJsonPath('data.0.ordering_closes_at_local', '2027-02-05T11:00')
            ->assertJsonPath('data.0.timezone', 'America/New_York');
    }

    #[Test]
    public function an_update_that_omits_the_window_leaves_it_alone(): void
    {
        $id = $this->createMenu(['ordering_closes_at' => '2027-02-05T11:00']);

        $this->putJson($this->base() . '/menus/' . $id, ['title' => 'Renamed'])->assertOk();

        $menu = MealMenu::withoutMasjidScope()->find($id);
        $this->assertSame('Renamed', $menu->title);
        $this->assertSame('2027-02-05 16:00:00', $menu->getRawOriginal('ordering_closes_at'));
    }

    #[Test]
    public function clearing_the_field_clears_the_window(): void
    {
        $id = $this->createMenu(['ordering_closes_at' => '2027-02-05T11:00']);

        // An emptied datetime-local input submits "", which must null the column
        // rather than fail validation or store the epoch.
        $this->putJson($this->base() . '/menus/' . $id, ['ordering_closes_at' => ''])->assertOk();

        $this->assertNull(MealMenu::withoutMasjidScope()->find($id)->ordering_closes_at);
    }

    #[Test]
    public function ordering_closes_on_the_masjids_clock_not_the_servers(): void
    {
        // What the bug actually cost: a cutoff set for 11:00 AM local used to shut
        // ordering at 07:00 AM local. These two assertions are the money.
        $id = $this->createMenu([
            'service_date' => '2027-09-10',
            'ordering_closes_at' => '2027-09-10T11:00',
            'status' => MealMenu::STATUS_OPEN,
        ]);

        // The public payload identifies a menu by uuid, never the sequential id.
        $uuid = MealMenu::withoutMasjidScope()->find($id)->uuid;
        $headers = ['masjid-id' => (string) $this->masjid->id];

        // 10:59 EDT -> still open.
        $this->travelTo('2027-09-10 14:59:00');
        $this->getJson('/api/v1/lunch-menu', $headers)
            ->assertOk()
            ->assertJsonPath('data.menu.uuid', $uuid);

        // 11:01 EDT -> shut.
        $this->travelTo('2027-09-10 15:01:00');
        $this->getJson('/api/v1/lunch-menu', $headers)
            ->assertOk()
            ->assertJsonPath('data.menu', null);

        $this->travelBack();
    }

    #[Test]
    public function a_masjid_without_a_timezone_falls_back_to_the_app_clock(): void
    {
        // Masjids predating the timezone column store 'UTC'; they must keep
        // behaving exactly as they did rather than throwing.
        $this->masjid->timezone = 'UTC';
        $this->masjid->save();

        $id = $this->createMenu(['ordering_closes_at' => '2027-02-05T11:00']);

        $this->assertSame(
            '2027-02-05 11:00:00',
            MealMenu::withoutMasjidScope()->find($id)->getRawOriginal('ordering_closes_at')
        );
    }
}
