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
            ->assertJsonPath('data.capabilities.crm', true);
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

                $key = substr($middleware, strlen('capability:'));
                $definition = config("capabilities.{$key}");

                $this->assertIsArray($definition, "Route {$route->uri()} gates on unknown capability '{$key}'.");
                $this->assertEmpty($definition['column'] ?? null, "Route {$route->uri()} gates a column-backed capability through `capability:`; use its own middleware.");
                $seen[$key] = true;
            }
        }

        $this->assertArrayHasKey('web_pages', $seen);
        $this->assertArrayHasKey('jummah_lunch', $seen);
    }
}
