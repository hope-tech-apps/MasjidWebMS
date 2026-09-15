<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The five app-only worship modules: quran, hadith, adhkar, qibla, tasbih.
 *
 * They are the first modules with no admin screen at all. `surface` => 'app' is
 * their placement: each one decides whether the MOBILE APP's menu lists that
 * entry (GET /api/mobile/masjids/{id}/menu), one per legacy Mobile App Features
 * id 1-5, so the app's feature list can name every worship feature separately.
 *
 * What this pins, and why each line would otherwise bite:
 *   - the keys and their order in the catalogue, Masjid::MODULE_KEYS and the
 *     SPA's Capability.ts — the cutover maps legacy ids 1-5 onto them by name;
 *   - masjid true / school false / community false, the same rule
 *     config/verticals.php already applies to those app feature keys;
 *   - `surface` => 'app' with no `where` and no sidebar item, which is what the
 *     switch panel places the row from;
 *   - no `capability:` route: an admin gate on one of these would refuse a
 *     screen that does not exist;
 *   - a stale config cache reads them as their org type's default, never as a
 *     decision, so a deploy can never empty a masjid's app menu (the
 *     2026-08-28 drawer outage is the reason that rule exists at all).
 */
class WorshipAppModulesTest extends TestCase
{
    use RefreshDatabase;

    /** In catalogue order: the cutover maps legacy Mobile App Features ids 1-5 onto these. */
    private const KEYS = ['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function org(string $orgType = 'masjid'): Masjid
    {
        return Masjid::create([
            'name' => 'Worship Org ' . uniqid(),
            'email' => 'worship' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
            'assistant_enabled' => false,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
    }

    #[Test]
    public function the_five_keys_are_modules_in_the_catalogue_in_order(): void
    {
        $modules = array_keys(array_filter(
            config('capabilities', []),
            fn ($definition) => is_array($definition) && ($definition['kind'] ?? null) === 'module'
        ));

        // Appended after the existing modules, in this order, so MODULE_KEYS and
        // the SPA's copy can be read as one list.
        $this->assertSame(self::KEYS, array_slice($modules, -5));
        $this->assertSame(self::KEYS, array_slice(Masjid::MODULE_KEYS, -5));

        foreach (self::KEYS as $key) {
            $definition = config("capabilities.{$key}");

            $this->assertIsArray($definition, "{$key} is not in the catalogue");
            $this->assertSame('module', $definition['kind'], "{$key} is not a module");
            $this->assertSame('prayer', $definition['group'], "{$key} is not in the prayer group");
            $this->assertNotEmpty($definition['label'] ?? null, "{$key} has no label");
            $this->assertNotEmpty($definition['description'] ?? null, "{$key} has no description");
        }

        // The app menu's row order is the client's, but the label is the one an
        // admin flips here, and the legacy /features rows carry the same U+2019.
        $this->assertSame('Qur’an', config('capabilities.quran.label'));
    }

    #[Test]
    public function each_one_is_placed_on_the_app_menu_and_nowhere_in_the_admin(): void
    {
        foreach (self::KEYS as $key) {
            $definition = config("capabilities.{$key}");

            $this->assertSame('app', $definition['surface'] ?? null, "{$key} is not placed on the app menu");
            $this->assertArrayNotHasKey('where', $definition, "{$key} names a place on the Details screen");
        }

        // No sidebar item may point at one: `requiresModule` is how a screen is
        // hidden, and these have no screen. The SPA's menu table is TypeScript,
        // so the check that matters here is the route table.
        $gated = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'capability:')) {
                    continue;
                }

                foreach (explode(',', substr($middleware, strlen('capability:'))) as $key) {
                    $gated[$key] = $route->uri();
                }
            }
        }

        foreach (self::KEYS as $key) {
            $this->assertArrayNotHasKey($key, $gated, "an admin route gates on the app-only module '{$key}'");
        }
    }

    #[Test]
    public function they_are_offered_to_masjids_only_and_a_super_admin_switches_one_on(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertSame(
                ['masjid' => true, 'school' => false, 'community' => false],
                Masjid::MODULE_DEFAULTS[$key],
                "{$key}'s defaults changed"
            );
            $this->assertSame(Masjid::MODULE_DEFAULTS[$key], config("capabilities.{$key}.defaults"));
        }

        $masjid = $this->org('masjid');
        $school = $this->org('school');

        foreach (self::KEYS as $key) {
            $this->assertTrue($masjid->hasCapability($key), "a masjid does not have {$key}");
            $this->assertFalse($masjid->moduleIsOff($key), "{$key} is off for a fresh masjid");

            $this->assertFalse($school->hasCapability($key), "a school has {$key}");
            $this->assertTrue($school->moduleIsOff($key), "{$key} is on for a fresh school");
            $this->assertFalse($school->moduleOfferedByDefault($key));
        }

        // Nothing appears in the lists until somebody decides: a fresh org of
        // either type reports neither an off nor an on.
        $this->assertSame([], $masjid->modules_off);
        $this->assertSame([], $school->modules_on);
    }

    #[Test]
    public function switching_one_off_for_one_masjid_is_a_decision_the_gate_and_the_lists_report(): void
    {
        $masjid = $this->org('masjid');
        $other = $this->org('masjid');
        Sanctum::actingAs($this->superAdmin());

        $this->patch(
            "/api/admin/masjids/{$masjid->id}/capabilities/quran",
            ['enabled' => '0'],
            ['Accept' => 'application/json']
        )->assertOk();

        $masjid = $masjid->fresh();

        $this->assertTrue($masjid->moduleIsOff('quran'));
        $this->assertFalse($masjid->moduleIsOff('hadith'), 'switching Qur’an off took Hadith with it');
        $this->assertContains('quran', $masjid->modules_off);
        $this->assertSame([], $masjid->modules_on);

        // Per organisation, never per platform.
        $this->assertFalse($other->fresh()->moduleIsOff('quran'));
    }

    #[Test]
    public function switching_one_on_for_a_school_is_the_only_way_it_gets_one(): void
    {
        $school = $this->org('school');
        Sanctum::actingAs($this->superAdmin());

        $this->patch(
            "/api/admin/masjids/{$school->id}/capabilities/tasbih",
            ['enabled' => '1'],
            ['Accept' => 'application/json']
        )->assertOk();

        $school = $school->fresh();

        $this->assertTrue($school->hasCapability('tasbih'));
        $this->assertFalse($school->moduleIsOff('tasbih'));
        // Not offered to a school, so it rides `modules_on`, never `modules_off`.
        $this->assertContains('tasbih', $school->modules_on);
        $this->assertNotContains('tasbih', $school->modules_off);
    }

    #[Test]
    public function the_switch_panel_places_them_in_the_prayer_and_worship_card(): void
    {
        $masjid = $this->org('masjid');
        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson("/api/admin/masjids/{$masjid->id}/capabilities")
            ->assertOk()
            ->json('data');

        $prayer = collect($data['groups'])->firstWhere('key', 'prayer');

        $this->assertNotNull($prayer, 'the panel has no prayer card');
        $this->assertSame(
            ['prayer_times', 'quran', 'hadith', 'adhkar', 'qibla', 'tasbih'],
            array_column($prayer['entries'], 'key')
        );

        $entries = collect($prayer['entries'])->keyBy('key');

        foreach (self::KEYS as $key) {
            // `surface` is what the panel places the row from: without it the row
            // is dropped as "a screen a masjid does not have", which is how it
            // read before this key existed.
            $this->assertSame('app', $entries[$key]['surface'], "{$key} carries no surface");
            $this->assertNull($entries[$key]['where'], "{$key} carries a where");
            $this->assertTrue($entries[$key]['enabled'], "{$key} is off for a fresh masjid");
            $this->assertTrue($entries[$key]['offered_by_default']);
            $this->assertFalse($entries[$key]['overridden']);
            $this->assertSame('capability', $entries[$key]['writer']);
            $this->assertSame([], $entries[$key]['facts']);
        }

        $this->assertSame('Qur’an', $entries['quran']['label']);
    }

    #[Test]
    public function a_config_cache_that_does_not_know_them_yet_reads_the_org_types_default(): void
    {
        // bin/deploy runs the new PHP against the previous config cache for a
        // while. A module the loaded config does not know must read as its org
        // type's default, never as a decision — otherwise the first request of a
        // deploy empties every masjid's app menu.
        $masjid = $this->org('masjid');
        $school = $this->org('school');

        foreach (self::KEYS as $key) {
            config(["capabilities.{$key}" => null]);

            $this->assertFalse($masjid->moduleIsOff($key), "{$key} read as switched off for a masjid on a stale config");
            $this->assertTrue($school->moduleIsOff($key), "{$key} read as switched on for a school on a stale config");
        }
    }

    #[Test]
    public function a_stale_config_reads_the_default_rather_than_an_override_so_the_app_menu_fails_open(): void
    {
        $masjid = $this->org('masjid');
        Sanctum::actingAs($this->superAdmin());

        $this->patch(
            "/api/admin/masjids/{$masjid->id}/capabilities/qibla",
            ['enabled' => '0'],
            ['Accept' => 'application/json']
        )->assertOk();

        $masjid = $masjid->fresh();
        $this->assertTrue($masjid->moduleIsOff('qibla'));

        // With the entry gone from the loaded config the override is not read at
        // all: the answer is the org type's default, which for a masjid is ON.
        // Fail-open is the deliberate trade — a deploy window shows one extra app
        // menu row rather than taking every row away.
        config(['capabilities.qibla' => null]);
        $this->assertFalse($masjid->moduleIsOff('qibla'));
    }
}
