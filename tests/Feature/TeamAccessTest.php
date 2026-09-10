<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layer 2 of the access model: the Team screen (TeamController).
 *
 * An administrator gets everything the organisation has; a lunch-only login
 * gets the lunch board. The endpoint creates only those two, never reads
 * `type` from the request, works without the CRM, and cannot reach or remove
 * anyone outside the bound organisation.
 */
class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Mail::fake();

        $this->masjid = $this->org('masjid', crm: true);
        $this->owner = $this->staff($this->masjid, 'MasjidAdmin', 'masjid-admin');
        $this->masjid->user_id = $this->owner->id;
        $this->masjid->save();
    }

    private function org(string $orgType, bool $crm): Masjid
    {
        return Masjid::create([
            'name' => 'Team Org ' . uniqid(),
            'email' => 'team' . uniqid() . '@test.local',
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

    private function add(Masjid $masjid, array $fields)
    {
        // Form-encoded, exactly as the SPA posts it.
        return $this->post("/api/admin/masjids/{$masjid->id}/team", $fields, ['Accept' => 'application/json']);
    }

    #[Test]
    public function the_team_lists_every_login_with_what_it_can_do(): void
    {
        $second = $this->staff($this->masjid, 'MasjidAdmin', 'masjid-admin');
        $lunch = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');
        $teacher = $this->staff($this->masjid, 'Teacher', 'teacher');

        Sanctum::actingAs($this->owner);

        $res = $this->getJson("/api/admin/masjids/{$this->masjid->id}/team")->assertOk();

        $people = collect($res->json('data.people'));
        $this->assertSame((int) $this->owner->id, $people->first()['user_id'], 'the owner leads the list');
        $this->assertTrue($people->first()['is_owner']);
        $this->assertSame('admin', $people->firstWhere('user_id', $second->id)['access']);
        $this->assertSame('jummah_lunch', $people->firstWhere('user_id', $lunch->id)['access']);
        $this->assertSame('teacher', $people->firstWhere('user_id', $teacher->id)['access']);

        // Who can be removed here: not the owner, not yourself, not a teacher.
        $this->assertFalse($people->first()['removable']);
        $this->assertTrue($people->firstWhere('user_id', $second->id)['removable']);
        $this->assertFalse($people->firstWhere('user_id', $teacher->id)['removable']);

        $this->assertSame(['admin', 'jummah_lunch'], $res->json('data.can_add'));
        $this->assertContains('web_pages', collect($res->json('data.capabilities'))->pluck('key')->all());
    }

    #[Test]
    public function an_administrator_can_add_another_administrator(): void
    {
        Sanctum::actingAs($this->owner);

        $this->add($this->masjid, [
            'name' => 'Second Admin', 'email' => 'second@test.local', 'access' => 'admin',
            // Ignored: the level picks the type, never the request.
            'type' => 'SuperAdmin',
        ])->assertCreated()->assertJsonPath('data.access', 'admin');

        $user = User::where('email', 'second@test.local')->firstOrFail();
        $this->assertSame('MasjidAdmin', $user->type);
        $this->assertTrue(MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $user->id)
            ->where('role', 'masjid-admin')->exists());
    }

    #[Test]
    public function a_lunch_only_login_can_be_added_while_the_organisation_has_lunch(): void
    {
        Sanctum::actingAs($this->owner);

        $this->add($this->masjid, ['name' => 'Volunteer', 'email' => 'vol@test.local', 'access' => 'jummah_lunch'])
            ->assertCreated()->assertJsonPath('data.access', 'jummah_lunch');

        $this->assertSame(User::TYPE_LUNCH_STAFF, User::where('email', 'vol@test.local')->value('type'));
    }

    #[Test]
    public function a_lunch_login_is_refused_where_the_organisation_has_no_lunch(): void
    {
        $school = $this->org('school', crm: false);
        Sanctum::actingAs($this->staff($school, 'MasjidAdmin', 'masjid-admin'));

        $this->add($school, ['name' => 'Volunteer', 'email' => 'vol2@test.local', 'access' => 'jummah_lunch'])
            ->assertStatus(422);
        $this->assertFalse(User::where('email', 'vol2@test.local')->exists());
    }

    #[Test]
    public function an_organisation_without_the_crm_can_still_add_an_administrator(): void
    {
        // IntelliCor's shape: a school with crm_enabled = false.
        $school = $this->org('school', crm: false);
        Sanctum::actingAs($this->staff($school, 'MasjidAdmin', 'masjid-admin'));

        $this->add($school, ['name' => 'Office', 'email' => 'office@test.local', 'access' => 'admin'])
            ->assertCreated();
    }

    #[Test]
    public function teachers_and_unknown_levels_cannot_be_created_here(): void
    {
        Sanctum::actingAs($this->owner);

        $this->add($this->masjid, ['name' => 'T', 'email' => 't@test.local', 'access' => 'teacher'])->assertStatus(422);
        $this->add($this->masjid, ['name' => 'S', 'email' => 's@test.local', 'access' => 'SuperAdmin'])->assertStatus(422);
        $this->assertFalse(User::whereIn('email', ['t@test.local', 's@test.local'])->exists());
    }

    #[Test]
    public function removal_takes_the_membership_and_the_sessions(): void
    {
        $second = $this->staff($this->masjid, 'MasjidAdmin', 'masjid-admin');
        $second->createToken('session', ['staff']);
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/team/{$second->id}")->assertOk();

        $this->assertFalse(MasjidUser::where('user_id', $second->id)->exists());
        $this->assertSame(0, $second->tokens()->count());
        // Belonging to nothing any more, the login is retired.
        $this->assertSoftDeleted('users', ['id' => $second->id]);
    }

    #[Test]
    public function the_owner_yourself_and_teachers_are_not_removable_here(): void
    {
        $second = $this->staff($this->masjid, 'MasjidAdmin', 'masjid-admin');
        $teacher = $this->staff($this->masjid, 'Teacher', 'teacher');

        Sanctum::actingAs($second);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/team/{$this->owner->id}")->assertStatus(409);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/team/{$second->id}")->assertStatus(409);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/team/{$teacher->id}")->assertStatus(422);

        $this->assertTrue(MasjidUser::where('user_id', $teacher->id)->exists());
    }

    #[Test]
    public function nobody_outside_the_bound_organisation_can_be_seen_or_touched(): void
    {
        $other = $this->org('masjid', crm: true);
        $stranger = $this->staff($other, 'MasjidAdmin', 'masjid-admin');

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/admin/masjids/{$other->id}/team")->assertForbidden();
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/team/{$stranger->id}")->assertNotFound();
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/team/{$stranger->id}/invite")->assertNotFound();
        $this->assertTrue(MasjidUser::where('user_id', $stranger->id)->exists());
    }

    #[Test]
    public function an_invitation_can_be_sent_again(): void
    {
        $lunch = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/team/{$lunch->id}/invite")
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
