<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layer 1 of the access model: what an ORGANISATION has (config/capabilities.php).
 *
 * The catalogue defaults must reproduce what each vertical could reach before it
 * existed — that is what lets it ship without moving anyone. After that, the
 * `capability:` gate is the server-side boundary (the menu is only a hint), a
 * SuperAdmin is the only writer, and the platform operator is never locked out.
 */
class CapabilityGateTest extends TestCase
{
    use RefreshDatabase;

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

    private function org(string $orgType = 'masjid', bool $crm = true): Masjid
    {
        return Masjid::create([
            'name' => 'Capability Org ' . uniqid(),
            'email' => 'cap' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => $crm, 'org_type' => $orgType,
        ]);
    }

    private function staff(Masjid $masjid, string $type, string $role): User
    {
        $user = User::factory()->create([
            'type' => $type,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $user->id,
            'role' => $role, 'is_default' => true,
        ]);

        return $user->fresh();
    }

    private function admin(Masjid $masjid): User
    {
        return $this->staff($masjid, 'MasjidAdmin', 'masjid-admin');
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh();
    }

    private function grant(Masjid $masjid, string $key, bool $on): void
    {
        $overrides = $masjid->capability_overrides ?? [];
        $overrides[$key] = $on;
        $masjid->forceFill(['capability_overrides' => $overrides])->save();
    }

    #[Test]
    public function the_defaults_reproduce_what_each_vertical_reached_before(): void
    {
        $masjid = $this->org('masjid', crm: true);
        $school = $this->org('school', crm: false);

        // Web Pages was SuperAdmin-only in the menu for everyone.
        $this->assertFalse($masjid->hasCapability('web_pages'));
        $this->assertFalse($school->hasCapability('web_pages'));

        // The lunch menu item was offered to masjids only.
        $this->assertTrue($masjid->hasCapability('jummah_lunch'));
        $this->assertFalse($school->hasCapability('jummah_lunch'));

        // Column-backed entries can never disagree with their own gates.
        $this->assertTrue($masjid->hasCapability('crm'));
        $this->assertFalse($school->hasCapability('crm'));
        $this->assertSame((bool) $masjid->assistant_enabled, $masjid->hasCapability('assistant'));

        // The school calendar is new, so nobody had it: off for schools too, and
        // switched on per organisation (DECISIONS.md 2026-09-14). A default of
        // true would have handed Al-Razi a screen nobody decided to give it.
        $this->assertFalse($masjid->hasCapability('school_calendar'));
        $this->assertFalse($school->hasCapability('school_calendar'));
        $this->assertFalse($this->org('community')->hasCapability('school_calendar'));

        // A typo is never a grant.
        $this->assertFalse($masjid->hasCapability('web_page'));
    }

    #[Test]
    public function a_super_admins_decision_wins_over_the_default(): void
    {
        $masjid = $this->org('masjid');

        $this->grant($masjid, 'web_pages', true);
        $this->grant($masjid, 'jummah_lunch', false);

        $fresh = $masjid->fresh();
        $this->assertTrue($fresh->hasCapability('web_pages'));
        $this->assertFalse($fresh->hasCapability('jummah_lunch'));
    }

    #[Test]
    public function the_overrides_are_neither_mass_assignable_nor_public(): void
    {
        $this->assertTrue(Schema::hasColumn('masjids', 'capability_overrides'));
        $this->assertNotContains('capability_overrides', (new Masjid)->getFillable());
        $this->assertContains('capability_overrides', Masjid::PUBLIC_DIRECTORY_DENYLIST);
        // The retired per-account grant is gone, not merely unused.
        $this->assertFalse(Schema::hasColumn('users', 'can_manage_web_pages'));
    }

    #[Test]
    public function the_admin_payload_carries_what_the_organisation_has(): void
    {
        $masjid = $this->org('masjid');
        Sanctum::actingAs($this->admin($masjid));

        $this->getJson("/api/admin/masjids/{$masjid->id}")
            ->assertOk()
            ->assertJsonPath('data.capabilities.web_pages', false)
            ->assertJsonPath('data.capabilities.jummah_lunch', true)
            ->assertJsonPath('data.capabilities.crm', true)
            ->assertJsonPath('data.modules_off', [])
            ->assertJsonPath('data.modules_on', []);
    }

    #[Test]
    public function the_web_pages_api_follows_the_organisations_capability(): void
    {
        $masjid = $this->org('masjid');
        $other = $this->org('masjid');
        $this->grant($other, 'web_pages', true);
        Sanctum::actingAs($this->admin($masjid));

        // The server is the boundary — the menu hiding it was never enough.
        $this->getJson("/api/admin/masjids/{$masjid->id}/pages")->assertForbidden();
        $this->getJson("/api/admin/masjids/{$masjid->id}/section-types")->assertForbidden();

        $this->grant($masjid, 'web_pages', true);
        $this->getJson("/api/admin/masjids/{$masjid->id}/pages")->assertOk();

        // Having the capability never widens the tenant boundary.
        $this->getJson("/api/admin/masjids/{$other->id}/pages")->assertForbidden();
    }

    #[Test]
    public function a_super_admin_passes_every_capability_gate(): void
    {
        $school = $this->org('school', crm: false);
        Sanctum::actingAs($this->superAdmin());

        $this->getJson("/api/admin/masjids/{$school->id}/pages")->assertOk();
        $this->getJson("/api/admin/masjids/{$school->id}/jummah-lunch/menus")->assertOk();
    }

    #[Test]
    public function the_admin_lunch_board_follows_jummah_lunch(): void
    {
        $masjid = $this->org('masjid');
        $school = $this->org('school');

        Sanctum::actingAs($this->admin($masjid));
        $this->getJson("/api/admin/masjids/{$masjid->id}/jummah-lunch/menus")->assertOk();

        Sanctum::actingAs($this->admin($school));
        $this->getJson("/api/admin/masjids/{$school->id}/jummah-lunch/menus")->assertForbidden();
    }

    #[Test]
    public function a_lunch_login_reaches_nothing_once_its_organisation_loses_lunch(): void
    {
        $masjid = $this->org('masjid');
        Sanctum::actingAs($this->staff($masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff'));

        $this->getJson("/api/lunch/masjids/{$masjid->id}/jummah-lunch/menus")->assertOk();

        $this->grant($masjid, 'jummah_lunch', false);
        $this->getJson("/api/lunch/masjids/{$masjid->id}/jummah-lunch/menus")->assertForbidden();
    }

    #[Test]
    public function only_a_super_admin_can_change_what_an_organisation_has(): void
    {
        $masjid = $this->org('masjid');

        Sanctum::actingAs($this->admin($masjid));
        // Form-encoded "1", exactly as the SPA sends it.
        $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/web_pages", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertFalse($masjid->fresh()->hasCapability('web_pages'));

        Sanctum::actingAs($this->superAdmin());
        $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/web_pages", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.capabilities.web_pages', true);
        $this->assertTrue($masjid->fresh()->hasCapability('web_pages'));

        $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/web_pages", ['enabled' => '0'], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertFalse($masjid->fresh()->hasCapability('web_pages'));
    }

    #[Test]
    public function column_backed_and_unknown_capabilities_are_refused_by_that_toggle(): void
    {
        $masjid = $this->org('masjid', crm: false);
        Sanctum::actingAs($this->superAdmin());

        // crm keeps its own switch (crm-access) — one writer per capability.
        $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/crm", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertFalse($masjid->fresh()->crm_enabled);

        $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/nope", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    #[Test]
    public function every_capability_gate_in_the_route_table_names_a_real_catalogue_entry(): void
    {
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'capability:')) {
                    continue;
                }

                // `capability:a,b` is any-of: every key in it must be real.
                foreach (explode(',', substr($middleware, strlen('capability:'))) as $key) {
                    $definition = config("capabilities.{$key}");

                    $this->assertIsArray($definition, "Route {$route->uri()} gates on unknown capability '{$key}'.");
                    $this->assertEmpty($definition['column'] ?? null, "Route {$route->uri()} gates a column-backed capability through `capability:`; use its own middleware.");
                    $seen[$key] = true;
                }
            }
        }

        $this->assertArrayHasKey('web_pages', $seen);
        $this->assertArrayHasKey('jummah_lunch', $seen);
        $this->assertArrayHasKey('school_calendar', $seen);
        $this->assertArrayHasKey('form_editing', $seen);

        // A module no route carries would be a switch that switches nothing off.
        foreach (Masjid::MODULE_KEYS as $key) {
            $this->assertArrayHasKey($key, $seen, "Module '{$key}' is in the catalogue but no route carries capability:{$key}.");
        }
    }

    #[Test]
    public function the_module_keys_in_code_are_the_catalogues_modules_in_order(): void
    {
        // Masjid::MODULE_KEYS is what lets a module read fail OPEN on a stale
        // config cache, so it must never drift from the catalogue.
        $fromConfig = array_keys(array_filter(
            config('capabilities', []),
            fn ($definition) => ($definition['kind'] ?? null) === 'module'
        ));

        $this->assertSame($fromConfig, Masjid::MODULE_KEYS);
    }

    #[Test]
    public function every_entry_is_classified_and_every_non_column_entry_names_every_org_type(): void
    {
        $groups = array_keys(config('capability_groups', []));

        foreach (config('capabilities', []) as $key => $definition) {
            $this->assertContains($definition['kind'] ?? null, ['grant', 'module'], "{$key} has no kind");
            $this->assertContains($definition['group'] ?? null, $groups, "{$key} names a group config/capability_groups.php does not have");
            $this->assertNotEmpty($definition['label'] ?? null, "{$key} has no label");
            $this->assertNotEmpty($definition['description'] ?? null, "{$key} has no description");

            // `where` places a module that has no sidebar item of its own; the
            // panel prints it after the Details menu title, so blank is a bug.
            if (array_key_exists('where', $definition)) {
                $this->assertIsString($definition['where'], "{$key}'s where is not a string");
                $this->assertNotSame('', trim($definition['where']), "{$key} has an empty where");
            }

            if (! empty($definition['column'])) {
                $this->assertSame('grant', $definition['kind'], "{$key} is column-backed, so it is a grant");

                continue;
            }

            // hasCapability() reads `?? false`: a missing org type silently turns
            // the entry off for that whole vertical.
            foreach (Masjid::ORG_TYPES as $orgType) {
                $this->assertArrayHasKey($orgType, $definition['defaults'] ?? [], "{$key} has no default for a {$orgType}");
            }
        }
    }

    #[Test]
    public function the_module_defaults_in_code_are_the_catalogues_and_a_fresh_organisation_has_nothing_off_or_on(): void
    {
        // Masjid::MODULE_DEFAULTS is what a module read answers from on a stale
        // config cache, so it must be the catalogue's defaults, key for key and
        // in order.
        $fromConfig = [];

        foreach (Masjid::MODULE_KEYS as $key) {
            foreach (Masjid::ORG_TYPES as $orgType) {
                $fromConfig[$key][$orgType] = config("capabilities.{$key}.defaults.{$orgType}");
            }
        }

        $this->assertSame(Masjid::MODULE_DEFAULTS, $fromConfig);

        // The masjid screens, and nothing else, are held back from schools and
        // community organisations (owner, 2026-09-14): a SuperAdmin switches one
        // on per organisation.
        foreach ([Masjid::ORG_TYPE_SCHOOL, Masjid::ORG_TYPE_COMMUNITY] as $orgType) {
            $this->assertSame(
                ['splash', 'services', 'donation_link', 'giving', 'properties'],
                array_keys(array_filter(Masjid::MODULE_DEFAULTS, fn (array $defaults) => $defaults[$orgType] === false)),
                "the modules not offered to a {$orgType} changed"
            );
        }

        foreach (Masjid::ORG_TYPES as $orgType) {
            $org = $this->org($orgType);

            foreach (Masjid::MODULE_KEYS as $key) {
                $offered = Masjid::MODULE_DEFAULTS[$key][$orgType];

                // A masjid is offered every module: nothing it had moved.
                if ($orgType === Masjid::ORG_TYPE_MASJID) {
                    $this->assertTrue($offered, "{$key} is not offered to a masjid");
                }

                $this->assertSame($offered, $org->moduleOfferedByDefault($key), "{$key} for a {$orgType}");
                $this->assertSame($offered, $org->hasCapability($key), "{$key} for a {$orgType}");
                $this->assertSame(! $offered, $org->moduleIsOff($key), "{$key} for a {$orgType}");
            }

            // Nothing switched off, and nothing a SuperAdmin switched on.
            $this->assertSame([], $org->modules_off, "a fresh {$orgType} has something in modules_off");
            $this->assertSame([], $org->modules_on, "a fresh {$orgType} has something in modules_on");
            // New, so nobody had it.
            $this->assertFalse($org->hasCapability('form_editing'));
        }
    }

    #[Test]
    public function a_module_read_fails_open_and_a_grant_read_fails_closed(): void
    {
        $masjid = $this->org('masjid');
        $this->grant($masjid, 'events', false);
        $masjid = $masjid->fresh();

        $this->assertTrue($masjid->moduleIsOff('events'));

        // A grant is never "switched off" through the module reader, whatever
        // hasCapability says about it.
        $this->assertFalse($masjid->hasCapability('web_pages'));
        $this->assertFalse($masjid->moduleIsOff('web_pages'));

        // A typo is neither a grant nor a switched-off screen.
        $this->assertFalse($masjid->hasCapability('event'));
        $this->assertFalse($masjid->moduleIsOff('event'));
        $this->assertTrue($masjid->moduleOfferedByDefault('event'));
    }

    #[Test]
    public function a_super_admin_passes_a_switched_off_module_gate(): void
    {
        $school = $this->org('school', crm: true);

        // Giving and Properties & Rent are not offered to a school at all; the
        // rest are switched off here.
        foreach (['announcements', 'zakat', 'programs', 'website', 'prayer_times', 'appointment_requests'] as $key) {
            $this->grant($school, $key, false);
        }

        Sanctum::actingAs($this->superAdmin());

        foreach (['announcements', 'zakat-settings', 'offerings', 'pages', 'iqama', 'funds', 'properties', 'appointment-requests'] as $path) {
            $status = $this->getJson("/api/admin/masjids/{$school->id}/{$path}")->getStatusCode();

            $this->assertNotSame(403, $status, "a SuperAdmin was refused /{$path}");
            $this->assertLessThan(500, $status, "/{$path} errored for a SuperAdmin");
        }
    }
}
