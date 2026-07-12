<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SuperAdmin-controlled CRM feature gate for /api/admin/masjids/{masjid_id}/...
 *
 * The whole CRM (member directory + donation money path) is OFF by default:
 * masjids.crm_enabled defaults to false, the `crm` middleware (EnsureCrmEnabled)
 * 403s every CRM endpoint while it is false, and only a SuperAdmin can flip it
 * via PATCH .../crm-access. A MasjidAdmin — even one holding the full CRM
 * permission set — gets a 403 on the CRM endpoints while their masjid's CRM is
 * off, and a 403 if they try to toggle the flag themselves.
 *
 * The 2FA endpoints (general admin security) are NOT gated and stay reachable
 * regardless of crm_enabled.
 *
 * Sqlite-in-memory is forced in setUp (mirrors ContactCrudTest/TenantIsolation);
 * the spatie roles are seeded before the admins so each MasjidAdmin carries the
 * CRM permission set — proving the gate blocks even a fully-permissioned admin.
 */
class CrmFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Seed roles/permissions BEFORE the admin so it is bridged to the
        // masjid-admin role (holding the full CRM permission set) on save.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // CRM is left at its default (false) so the gate is closed to start.
        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    /** Create a Masjid row with CRM at its default (off) unless overridden. */
    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    /** Create a MasjidAdmin owning $masjid (masjids.user_id -> User::masjid()). */
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

    private function makeSuperAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    // ---------- default off ----------

    #[Test]
    public function a_new_masjid_has_crm_disabled_by_default(): void
    {
        $fresh = $this->makeMasjid();

        // Reload from the DB so the column default (false) is reflected — a
        // freshly-created model instance doesn't carry unset DB defaults.
        $this->assertFalse($fresh->fresh()->crm_enabled, 'crm_enabled must default to false.');
        $this->assertDatabaseHas('masjids', ['id' => $fresh->id, 'crm_enabled' => false]);
    }

    // ---------- gate closed -> 403 ----------

    #[Test]
    public function crm_endpoints_are_forbidden_while_crm_is_disabled(): void
    {
        Sanctum::actingAs($this->admin);

        // A MasjidAdmin holding every CRM permission is still blocked by the gate.
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts")->assertStatus(403);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/funds")->assertStatus(403);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/donations")->assertStatus(403);
    }

    // ---------- gate open -> 200 ----------

    #[Test]
    public function crm_endpoints_are_accessible_after_a_superadmin_enables_crm(): void
    {
        // SuperAdmin flips the gate on via the toggle endpoint.
        Sanctum::actingAs($this->makeSuperAdmin());
        $this->patchJson("/api/admin/masjids/{$this->masjid->id}/crm-access", ['enabled' => true])
            ->assertOk();

        // The masjid's own MasjidAdmin now reaches every CRM endpoint.
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts")->assertOk();
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/funds")->assertOk();
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/donations")->assertOk();
    }

    // ---------- only a SuperAdmin can toggle ----------

    #[Test]
    public function a_masjid_admin_cannot_toggle_crm_access(): void
    {
        Sanctum::actingAs($this->admin);

        // Own masjid in the route (so tenant resolution passes), but the caller
        // is not a SuperAdmin -> 403, and the flag must stay off.
        $this->patchJson("/api/admin/masjids/{$this->masjid->id}/crm-access", ['enabled' => true])
            ->assertStatus(403);

        $this->assertFalse($this->masjid->fresh()->crm_enabled);
    }

    #[Test]
    public function a_superadmin_can_toggle_crm_access_and_the_flag_flips(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        // Enable.
        $this->patchJson("/api/admin/masjids/{$this->masjid->id}/crm-access", ['enabled' => true])
            ->assertOk();
        $this->assertTrue($this->masjid->fresh()->crm_enabled);

        // Disable again — the switch works both ways.
        $this->patchJson("/api/admin/masjids/{$this->masjid->id}/crm-access", ['enabled' => false])
            ->assertOk();
        $this->assertFalse($this->masjid->fresh()->crm_enabled);
    }

    #[Test]
    public function the_crm_access_toggle_validates_the_enabled_flag(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->patchJson("/api/admin/masjids/{$this->masjid->id}/crm-access", [])
            ->assertStatus(422);
    }

    // ---------- 2FA is NOT gated ----------

    #[Test]
    public function two_factor_endpoints_stay_reachable_when_crm_is_disabled(): void
    {
        // The masjid's CRM is off, yet 2FA enrollment (general admin security) is
        // not behind the `crm` gate and must still work for the admin.
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/2fa/enroll')->assertOk();
    }
}
