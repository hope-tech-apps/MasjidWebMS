<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The ADDITIVE spatie authorization layer and its bridge to the legacy
 * `users.type` enum.
 *
 * Proves four things:
 *   1. The seeder creates the expected roles + granular CRM permissions and
 *      assigns them sensibly (super-admin = all, masjid-admin = full CRM set,
 *      member = none).
 *   2. Saving a user mirrors its `type` onto the matching spatie role, and a
 *      later `type` change re-syncs the role (the bridge stays in sync).
 *   3. A MasjidAdmin (bridged to masjid-admin) can reach a CRM endpoint that the
 *      new `permission:` gate protects — no loss of existing access.
 *   4. An admin lacking the permission is forbidden (403) on that endpoint.
 *
 * Sqlite-in-memory is forced in setUp (mirrors TenantIsolationTest/ContactCrud).
 */
class RolePermissionBridgeTest extends TestCase
{
    use RefreshDatabase;

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

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
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

    // ---------- seeding ----------

    #[Test]
    public function seeder_creates_the_expected_roles_and_permissions(): void
    {
        foreach (['super-admin', 'masjid-admin', 'member'] as $role) {
            $this->assertTrue(Role::where('name', $role)->exists(), "role {$role} should exist");
        }

        $expectedPermissions = [
            'view contacts', 'manage contacts', 'view donations',
            'manage funds', 'view donor pii', 'manage donations',
        ];
        foreach ($expectedPermissions as $permission) {
            $this->assertTrue(Permission::where('name', $permission)->exists(), "permission {$permission} should exist");
        }

        $this->assertSame(6, Permission::count());
    }

    #[Test]
    public function super_admin_has_all_permissions_and_member_has_none(): void
    {
        $this->assertCount(6, Role::findByName('super-admin')->permissions);
        $this->assertCount(0, Role::findByName('member')->permissions);
    }

    #[Test]
    public function masjid_admin_has_the_full_crm_permission_set(): void
    {
        $masjidAdmin = Role::findByName('masjid-admin');

        foreach ([
            'view contacts', 'manage contacts', 'view donations',
            'manage funds', 'view donor pii', 'manage donations',
        ] as $permission) {
            $this->assertTrue(
                $masjidAdmin->hasPermissionTo($permission),
                "masjid-admin should hold '{$permission}'"
            );
        }
    }

    // ---------- type <-> role bridge ----------

    #[Test]
    public function saving_a_user_bridges_its_type_to_the_matching_role(): void
    {
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        $masjid = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        $member = User::factory()->create(['type' => 'User', 'phone' => '+1' . random_int(1000000000, 9999999999)]);

        $this->assertTrue($super->fresh()->hasRole('super-admin'));
        $this->assertTrue($masjid->fresh()->hasRole('masjid-admin'));
        $this->assertTrue($member->fresh()->hasRole('member'));
    }

    #[Test]
    public function changing_a_users_type_resyncs_its_role(): void
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        $this->assertTrue($user->fresh()->hasRole('masjid-admin'));

        $user->type = 'SuperAdmin';
        $user->save();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('super-admin'));
        $this->assertFalse($fresh->hasRole('masjid-admin'), 'the old role should be replaced, not accumulated');
    }

    // ---------- gate on the new CRM endpoints ----------

    #[Test]
    public function bridged_masjid_admin_can_reach_a_permission_gated_crm_endpoint(): void
    {
        $masjid = $this->makeMasjid();
        $admin = $this->makeAdminFor($masjid);
        Contact::factory()->count(2)->create(['masjid_id' => $masjid->id]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/masjids/{$masjid->id}/contacts")
            ->assertOk();
    }

    #[Test]
    public function admin_without_the_permission_is_forbidden_on_a_crm_endpoint(): void
    {
        $masjid = $this->makeMasjid();
        $admin = $this->makeAdminFor($masjid);

        // Strip the bridged role so the admin passes `admin` (type check) but
        // holds NO CRM permission. syncRoles only touches pivots (no user save),
        // so the observer does not re-bridge.
        $admin->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($admin);

        // Own masjid in the route (tenant middleware passes), but the `permission`
        // gate denies -> 403 (spatie UnauthorizedException -> HttpException 403).
        $this->getJson("/api/admin/masjids/{$masjid->id}/contacts")
            ->assertStatus(403);
    }
}
