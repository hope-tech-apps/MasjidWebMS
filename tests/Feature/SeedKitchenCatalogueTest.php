<?php

namespace Tests\Feature;

use App\Console\Commands\SeedKitchenCatalogue;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `kitchen:seed-catalogue` — MEC's Halal Kitchen trays onto the board, guarded:
 * a dry run unless --apply, --apply only with the organisation's exact name, never
 * a second copy or an overwrite, the file checked whole before anything is written.
 *
 * The shipped data file is pinned too: 32 dishes, the Wix duplicate "Kunafeh"
 * left out, whole-cent prices adding to the menu MEC published.
 */
class SeedKitchenCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/data/mec-halal-kitchen.json';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjid = Masjid::create([
            'name' => 'Seed Test Center',
            'email' => 'office@seed.example.test',
            'phone' => '+15550100001',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
        ]);
    }

    #[Test]
    public function the_shipped_file_is_mecs_thirty_two_priced_dishes_without_the_duplicate(): void
    {
        $catalogue = SeedKitchenCatalogue::read(base_path(self::FILE));
        $names = array_column($catalogue['items'], 'name');

        $this->assertCount(32, $catalogue['items']);
        $this->assertNotContains('Kunafeh', $names, 'the Wix duplicate of Kunafeh (Small) is left out');
        $this->assertContains('Kunafeh (Small)', $names);
        $this->assertSame(327000, array_sum(array_column($catalogue['items'], 'price_minor')));
        $this->assertSame(48, $catalogue['pickup_lead_hours']);
        $this->assertSame('Halal Kitchen', $catalogue['title']);
        // MEC's words verbatim, the HTML entity decoded.
        $this->assertSame('Served with Rice, Salad & Garlic', $catalogue['items'][array_search('Chicken Leg quarters (serves 6)', $names, true)]['description']);
    }

    #[Test]
    public function without_apply_it_reports_and_writes_nothing(): void
    {
        $this->artisan('kitchen:seed-catalogue', ['masjid' => $this->masjid->id])
            ->expectsOutputToContain('Would create a DRAFT catalogue "Halal Kitchen"')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, MealMenu::withoutMasjidScope()->count());
        $this->assertSame(0, MealMenuItem::withoutMasjidScope()->count());
    }

    #[Test]
    public function apply_needs_the_organisations_exact_name(): void
    {
        foreach ([[], ['--expect-name' => 'Burlington Masjid']] as $options) {
            $this->artisan('kitchen:seed-catalogue', ['masjid' => $this->masjid->id, '--apply' => true] + $options)
                ->expectsOutputToContain('--apply needs --expect-name')
                ->assertFailed();
        }

        $this->assertSame(0, MealMenu::withoutMasjidScope()->count());
    }

    #[Test]
    public function apply_creates_a_draft_catalogue_once_and_never_overwrites_it(): void
    {
        $this->artisan('kitchen:seed-catalogue', [
            'masjid' => $this->masjid->id, '--apply' => true, '--expect-name' => 'seed test center',
        ])->assertSuccessful();

        $menu = MealMenu::withoutMasjidScope()->sole();
        $this->assertSame((int) $this->masjid->id, (int) $menu->masjid_id);
        $this->assertTrue($menu->isCatalogue());
        $this->assertSame(MealMenu::STATUS_DRAFT, $menu->status);
        $this->assertNull($menu->service_date);
        $this->assertSame(48, $menu->pickup_lead_hours);
        $this->assertSame(32, $menu->items()->count());
        $this->assertSame(327000, (int) $menu->items()->sum('price_minor'));
        $this->assertSame('Kunafeh (Small)', $menu->items()->orderBy('sort_order')->value('name'));

        // The office changes a price; a second run must not undo it.
        $menu->items()->orderBy('sort_order')->first()->update(['price_minor' => 7000]);

        $this->artisan('kitchen:seed-catalogue', [
            'masjid' => $this->masjid->id, '--apply' => true, '--expect-name' => 'Seed Test Center',
        ])->expectsOutputToContain('already has a menu titled "Halal Kitchen"')->assertFailed();

        $this->assertSame(1, MealMenu::withoutMasjidScope()->count());
        $this->assertSame(32, MealMenuItem::withoutMasjidScope()->count());
        $this->assertSame(7000, (int) $menu->items()->orderBy('sort_order')->value('price_minor'));
    }

    #[Test]
    public function undo_deletes_exactly_the_catalogue_the_apply_created_and_only_when_told_to(): void
    {
        $this->artisan('kitchen:seed-catalogue', [
            'masjid' => $this->masjid->id, '--apply' => true, '--expect-name' => 'Seed Test Center',
        ])->expectsOutputToContain('--undo --menu=')->assertSuccessful();
        $menu = MealMenu::withoutMasjidScope()->catalogue()->sole();
        // A Friday menu an office later gave the same title.
        $friday = MealMenu::factory()->forMasjid($this->masjid)->create(['title' => 'Halal Kitchen']);

        // A dry run by default, and the name guard as for --apply.
        $this->artisan('kitchen:seed-catalogue', ['masjid' => $this->masjid->id, '--undo' => true, '--menu' => $menu->id])
            ->expectsOutputToContain("Would delete catalogue {$menu->id}")
            ->assertSuccessful();
        $this->artisan('kitchen:seed-catalogue', ['masjid' => $this->masjid->id, '--undo' => true, '--menu' => $menu->id, '--apply' => true])
            ->assertFailed();
        // Never a menu that is not this file's catalogue: the Friday menu of the same title stays.
        $this->artisan('kitchen:seed-catalogue', [
            'masjid' => $this->masjid->id, '--undo' => true, '--menu' => $friday->id, '--apply' => true, '--expect-name' => 'Seed Test Center',
        ])->expectsOutputToContain('Nothing was deleted.')->assertFailed();
        $this->assertSame(2, MealMenu::withoutMasjidScope()->count());
        $this->assertSame(32, MealMenuItem::withoutMasjidScope()->count());

        $this->artisan('kitchen:seed-catalogue', [
            'masjid' => $this->masjid->id, '--undo' => true, '--menu' => $menu->id, '--apply' => true, '--expect-name' => 'Seed Test Center',
        ])->expectsOutputToContain("Deleted catalogue {$menu->id} and its 32 dishes.")->assertSuccessful();

        $this->assertNull(MealMenu::withoutMasjidScope()->withTrashed()->find($menu->id));
        $this->assertSame(0, MealMenuItem::withoutMasjidScope()->count());
        $this->assertNotNull($friday->fresh());
    }

    #[Test]
    public function undo_refuses_a_catalogue_that_has_taken_an_order(): void
    {
        $this->artisan('kitchen:seed-catalogue', [
            'masjid' => $this->masjid->id, '--apply' => true, '--expect-name' => 'Seed Test Center',
        ])->assertSuccessful();
        $menu = MealMenu::withoutMasjidScope()->sole();
        MealOrder::factory()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $menu->id]);

        $this->artisan('kitchen:seed-catalogue', [
            'masjid' => $this->masjid->id, '--undo' => true, '--menu' => $menu->id, '--apply' => true, '--expect-name' => 'Seed Test Center',
        ])->expectsOutputToContain('has 1 order(s)')->assertFailed();

        $this->assertNotNull(MealMenu::withoutMasjidScope()->find($menu->id));
        $this->assertSame(32, MealMenuItem::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_catalogue_deleted_on_the_board_is_not_seeded_past_until_it_is_undone(): void
    {
        $args = ['masjid' => $this->masjid->id, '--apply' => true, '--expect-name' => 'Seed Test Center'];
        $this->artisan('kitchen:seed-catalogue', $args)->assertSuccessful();
        $menu = MealMenu::withoutMasjidScope()->sole();
        $menu->delete(); // the board's delete is a soft delete

        $this->artisan('kitchen:seed-catalogue', $args)
            ->expectsOutputToContain("has a DELETED menu titled \"Halal Kitchen\" (menu {$menu->id})")
            ->assertFailed();
        $this->assertSame(32, MealMenuItem::withoutMasjidScope()->count(), 'no second copy of every dish');

        $this->artisan('kitchen:seed-catalogue', $args + ['--undo' => true, '--menu' => $menu->id])->assertSuccessful();
        $this->artisan('kitchen:seed-catalogue', $args)->assertSuccessful();
        $this->assertSame(1, MealMenu::withoutMasjidScope()->withTrashed()->count());
        $this->assertSame(32, MealMenuItem::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_file_with_a_bad_dish_is_refused_whole(): void
    {
        $bad = [
            ['title' => 'Kitchen', 'items' => [['name' => 'Tray', 'price_minor' => 1000], ['name' => 'tray', 'price_minor' => 2000]]],
            ['title' => 'Kitchen', 'items' => [['name' => 'Tray', 'price_minor' => 1000], ['name' => 'Soup', 'price_minor' => 40.5]]],
            ['title' => 'Kitchen', 'items' => [['name' => 'Tray', 'price_minor' => 0]]],
            ['title' => '', 'items' => [['name' => 'Tray', 'price_minor' => 1000]]],
            ['title' => 'Kitchen', 'items' => []],
        ];

        foreach ($bad as $i => $document) {
            $path = tempnam(sys_get_temp_dir(), 'kitchen') . '.json';
            file_put_contents($path, json_encode($document));

            $this->artisan('kitchen:seed-catalogue', [
                'masjid' => $this->masjid->id, '--file' => $path, '--apply' => true, '--expect-name' => 'Seed Test Center',
            ])->expectsOutputToContain('Nothing was written.')->assertFailed();

            @unlink($path);
        }

        $this->assertSame(0, MealMenu::withoutMasjidScope()->count());
        $this->assertSame(0, MealMenuItem::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_unknown_organisation_is_refused(): void
    {
        $this->artisan('kitchen:seed-catalogue', ['masjid' => 999999])->assertFailed();
        $this->assertSame(0, MealMenu::withoutMasjidScope()->count());
    }
}
