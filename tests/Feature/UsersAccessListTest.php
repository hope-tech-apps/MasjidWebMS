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
        $this->assertSame([['masjid_id' => $this->masjid->id, 'name' => $this->masjid->name, 'access' => 'jummah_lunch', 'is_owner' => false, 'archived' => false]], $lunch['organisations']);

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

    #[Test]
    public function restoring_an_archived_lunch_login_never_makes_it_an_administrator(): void
    {
        Sanctum::actingAs($this->super);

        // Archived, with its Burlington lunch membership still in place.
        $this->lunch->delete();

        // The Super creates "a new administrator" with the same email, through the
        // real form rules (an avatar, and a password with every character class).
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->post('/api/admin/users', [
            'name' => 'Fresh Admin',
            'email' => $this->lunch->email,
            'phone' => '+15550007777',
            'type' => 'MasjidAdmin',
            'avatar' => \Illuminate\Http\UploadedFile::fake()->image('avatar.png', 20, 20),
            'password' => 'Restore#2026x',
            'password_confirmation' => 'Restore#2026x',
        ], ['Accept' => 'application/json'])->assertSuccessful()
            ->assertJsonFragment(['message' => 'This email belonged to an archived lunch-only or teacher login. It has been restored with the same access; change that on the organisation\'s Team & Access screen.']);

        // Restored, but still lunch-only: its leftover membership must not have
        // become full administration of the organisation.
        $user = User::findOrFail($this->lunch->id);
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->type);
        $this->assertTrue(MasjidUser::where('user_id', $user->id)->where('role', 'lunch-staff')->exists());
    }

    #[Test]
    public function an_archived_lunch_login_with_no_membership_takes_the_forms_type(): void
    {
        Sanctum::actingAs($this->super);
        \Illuminate\Support\Facades\Storage::fake('public');

        // No membership left, so there is nothing a new type could escalate into.
        MasjidUser::where('user_id', $this->lunch->id)->delete();
        $this->lunch->delete();

        $this->post('/api/admin/users', [
            'name' => 'Fresh Admin', 'email' => $this->lunch->email, 'phone' => '+15550007778',
            'type' => 'MasjidAdmin',
            'avatar' => \Illuminate\Http\UploadedFile::fake()->image('avatar.png', 20, 20),
            'password' => 'Restore#2026x', 'password_confirmation' => 'Restore#2026x',
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame('MasjidAdmin', User::findOrFail($this->lunch->id)->type);
    }
}
