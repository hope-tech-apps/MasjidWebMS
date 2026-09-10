<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SuperAdmin's Users & Access list: every login it manages — including the
 * lunch-only and teacher logins it used to hide — with each one's organisation
 * and what they can do there. And the generic user form never re-types a
 * scoped login (that would widen it past its membership).
 */
class UsersAccessListTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $owner;
    private User $lunch;
    private User $super;

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
            'name' => 'Users Org ' . uniqid(),
            'email' => 'users' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);

        $this->owner = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550002222']);
        $this->masjid->user_id = $this->owner->id;
        $this->masjid->save();

        $this->lunch = User::factory()->create(['type' => User::TYPE_LUNCH_STAFF, 'phone' => '+15550003333', 'name' => 'Lunch Volunteer']);
        MasjidUser::create(['masjid_id' => $this->masjid->id, 'user_id' => $this->lunch->id, 'role' => 'lunch-staff', 'is_default' => true]);

        $this->super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550004444'])->fresh();
    }

    #[Test]
    public function the_list_shows_every_login_with_its_organisation_and_access(): void
    {
        Sanctum::actingAs($this->super);

        $users = collect($this->getJson('/api/admin/users')->assertOk()->json('data'));

        $lunch = $users->firstWhere('id', $this->lunch->id);
        $this->assertNotNull($lunch, 'lunch-only logins must appear on the Users list');
        $this->assertSame([['masjid_id' => $this->masjid->id, 'name' => $this->masjid->name, 'access' => 'jummah_lunch', 'is_owner' => false]], $lunch['organisations']);

        $owner = $users->firstWhere('id', $this->owner->id);
        $this->assertTrue($owner['organisations'][0]['is_owner']);
        $this->assertSame('admin', $owner['organisations'][0]['access']);

        // SuperAdmins are the operators, not managed accounts.
        $this->assertNull($users->firstWhere('id', $this->super->id));
    }

    #[Test]
    public function a_single_user_carries_what_their_organisation_has(): void
    {
        Sanctum::actingAs($this->super);

        $orgs = $this->getJson("/api/admin/users/{$this->lunch->id}")->assertOk()->json('data.organisations');

        $this->assertSame('jummah_lunch', $orgs[0]['access']);
        $this->assertContains('jummah_lunch', $orgs[0]['capabilities']);
        $this->assertNotContains('web_pages', $orgs[0]['capabilities']);
    }

    #[Test]
    public function only_a_super_admin_sees_the_list(): void
    {
        Sanctum::actingAs($this->owner->fresh());

        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    #[Test]
    public function the_generic_user_form_never_retypes_a_scoped_login(): void
    {
        Sanctum::actingAs($this->super);

        // Even if a type is sent, a lunch-only login stays lunch-only here —
        // re-typing it would leave its 'lunch-staff' membership behind and make
        // it a full administrator. Access changes go through the Team endpoint.
        $this->post("/api/admin/users/{$this->lunch->id}", [
            'name' => 'Lunch Volunteer Renamed', 'email' => $this->lunch->email, 'phone' => '+15550003333',
            'type' => 'MasjidAdmin',
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $this->lunch->fresh();
        $this->assertSame('Lunch Volunteer Renamed', $fresh->name);
        $this->assertSame(User::TYPE_LUNCH_STAFF, $fresh->type);
    }
}
