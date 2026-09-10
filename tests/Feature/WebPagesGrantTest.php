<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `users.can_manage_web_pages` — the per-account grant that shows Web Pages
 * Management to a MasjidAdmin. The menu item is otherwise SuperAdmin-only.
 *
 * The SPA reads the flag off `/api/admin/user`, so that payload is the
 * contract: a real boolean, false for every account until one is granted. The
 * grant must not be reachable through mass assignment, and it must not change
 * what the pages API already allowed — any MasjidAdmin, own masjid only,
 * granted or not.
 */
class WebPagesGrantTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $own;
    private Masjid $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->own = $this->makeMasjid();
        $this->other = $this->makeMasjid();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Web Pages Masjid ' . uniqid(),
            'email' => 'wp' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
    }

    private function makeAdmin(bool $granted = false): User
    {
        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $this->own->id,
            'user_id' => $user->id,
            'role' => 'masjid-admin',
            'is_default' => true,
        ]);

        if ($granted) {
            // How an account is actually granted: explicitly, never via fill().
            $user->forceFill(['can_manage_web_pages' => true])->save();
        }

        // Re-read so the instance carries every column, as Auth::user() does
        // on a real request — the factory model knows nothing of DB defaults.
        return $user->fresh();
    }

    #[Test]
    public function no_account_is_granted_until_someone_grants_it(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'can_manage_web_pages'));

        Sanctum::actingAs($this->makeAdmin());

        // Strictly false, not null or 0: the menu tests `=== true`, and a
        // missing key would hide a broken serializer behind the same closed menu.
        $this->getJson('/api/admin/user')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.can_manage_web_pages', false);
    }

    #[Test]
    public function a_granted_admin_carries_it_on_every_page_load(): void
    {
        Sanctum::actingAs($this->makeAdmin(granted: true));

        $this->getJson('/api/admin/user')
            ->assertOk()
            ->assertJsonPath('data.can_manage_web_pages', true)
            ->assertJsonPath('data.masjid.id', $this->own->id);
    }

    #[Test]
    public function the_grant_cannot_be_mass_assigned(): void
    {
        // No request payload — profile edit, administrator invite, user update —
        // can hand an account this screen. Only an explicit forceFill does.
        $this->assertNotContains('can_manage_web_pages', (new User)->getFillable());
    }

    #[Test]
    public function the_grant_does_not_widen_the_pages_api(): void
    {
        // Granted or not, the pages API answers exactly as it did before the
        // flag existed: own masjid 200, anyone else's 403. The flag is menu
        // visibility; the tenant gate is the boundary.
        foreach ([false, true] as $granted) {
            Sanctum::actingAs($this->makeAdmin($granted));

            $this->getJson("/api/admin/masjids/{$this->own->id}/pages")->assertOk();
            $this->getJson("/api/admin/masjids/{$this->other->id}/pages")->assertForbidden();
        }
    }
}
