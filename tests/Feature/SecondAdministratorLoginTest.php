<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A second MasjidAdmin — one added by AdministratorsController rather than one
 * who owns the organisation — can actually sign in.
 *
 * `User::masjid()` is a hasOne over `masjids.user_id`, i.e. OWNERSHIP. A second
 * administrator owns nothing; their organisation is a `masjid_user` row, which
 * is what TenantResolver binds them from on every request. AuthController read
 * only the hasOne, so these accounts were emailed an invite, set a password,
 * and were then refused at the door with "you don't have a related masjid" —
 * the whole feature minted logins that could not log in.
 *
 * Both halves are pinned here because they fail differently: login() refuses at
 * the door, and user() runs on every page load, so an admin who could sign in
 * but not be re-identified would be signed straight back out on first refresh.
 */
class SecondAdministratorLoginTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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
            'name' => 'Second Admin Masjid ' . uniqid(),
            'email' => 'sa' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
    }

    private function makeAdmin(bool $owns): User
    {
        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'password' => Hash::make('correct-horse-battery'),
        ]);

        if ($owns) {
            $this->masjid->user_id = $user->id;
            $this->masjid->save();
        }

        MasjidUser::create([
            'masjid_id' => $this->masjid->id,
            'user_id' => $user->id,
            'role' => 'masjid-admin',
            'is_default' => true,
        ]);

        return $user;
    }

    #[Test]
    public function an_administrator_who_owns_nothing_can_still_sign_in(): void
    {
        $user = $this->makeAdmin(owns: false);
        $this->assertNull($user->masjid, 'precondition: this admin owns no masjid');

        $res = $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertSame('success', $res->json('status'));
        $this->assertNotEmpty($res->json('data.token'));
        // Their organisation rides on the payload, resolved from the membership,
        // because the SPA reads user.masjid to pick a dashboard.
        $this->assertSame($this->masjid->id, (int) $res->json('data.user.masjid.id'));
    }

    #[Test]
    public function and_is_still_recognised_on_every_page_load(): void
    {
        // login() refuses at the door; user() runs on refresh. An account that
        // passes one and fails the other logs in and is immediately kicked out.
        $user = $this->makeAdmin(owns: false);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/user')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.masjid.id', $this->masjid->id);
    }

    #[Test]
    public function the_owner_admins_path_is_unchanged(): void
    {
        // The fallback must be strictly additive: an owner still resolves
        // through the hasOne exactly as before.
        $user = $this->makeAdmin(owns: true);

        $res = $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertSame('success', $res->json('status'));
        $this->assertSame($this->masjid->id, (int) $res->json('data.user.masjid.id'));
    }

    #[Test]
    public function an_administrator_of_nothing_at_all_is_still_refused(): void
    {
        // The refusal has to survive: an admin with neither ownership nor a
        // membership would otherwise reach an UNBOUND tenant context.
        $orphan = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $res = $this->postJson('/api/admin/login', [
            'email' => $orphan->email,
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertSame('failed', $res->json('status'));
        $this->assertNull($res->json('data.token'));
    }
}
