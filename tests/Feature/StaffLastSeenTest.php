<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\MembershipSeen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `masjid_user.last_seen_at`: when a staff login last OPENED one specific school
 * (owner, 2026-09-29: "when they last opened the specific school instead, not
 * necessarily the last time they logged in").
 *
 * Written by ResolveMasjidTenant through App\Support\MembershipSeen, inline, after
 * the tenant binds and only on a response below 400. What is pinned here:
 *
 *   - it is set by a bound request of each staff realm (teacher, administrator,
 *     lunch staff), on THAT school's membership and no other;
 *   - it is not set by a refused request (403, 404, 422, 401), by an unbound one, by a
 *     SuperAdmin acting in a school, by the ownership fallback, or by a family token;
 *   - it is throttled to once per five minutes per membership, by ONE conditional
 *     UPDATE on the query builder (no model event, no `updated_at`);
 *   - a failure to write it never reaches the caller and is logged at WARNING;
 *   - a school's screens show only their own school's value for a shared teacher.
 *
 * Every fixture is built before the first request of a test: TenantContext is scoped
 * to the app, not to a request, so after a request it is still bound and would stamp
 * the next fixture with that school's id.
 */
class StaffLastSeenTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $alpha;
    private Masjid $beta;
    private User $teacher;
    private User $alphaAdmin;
    private User $betaAdmin;
    private Group $alphaClass;
    private Group $betaClass;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['tenancy.multi_membership' => true]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$this->alpha, $this->alphaAdmin] = $this->makeSchoolWithAdmin('Alpha School');
        [$this->beta, $this->betaAdmin] = $this->makeSchoolWithAdmin('Beta School');

        $this->alphaClass = $this->makeClass($this->alpha, 'Alpha Class');
        $this->betaClass = $this->makeClass($this->beta, 'Beta Class');

        // One teacher in both schools, leading a class in each.
        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create(['masjid_id' => $this->alpha->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
        MasjidUser::create(['masjid_id' => $this->beta->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => false]);

        foreach ([$this->alphaClass, $this->betaClass] as $class) {
            $class->staff()->attach($this->teacher->id, [
                'masjid_id' => $class->masjid_id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------- it is set by a bound request

    #[Test]
    public function a_bound_teacher_request_stamps_that_schools_membership_and_no_other(): void
    {
        $this->assertNull($this->seen($this->alpha, $this->teacher));
        $this->assertNull($this->seen($this->beta, $this->teacher));
        $before = $this->rowWithoutStamp($this->alpha, $this->teacher);

        Sanctum::actingAs($this->teacher, ['staff']);
        $this->getJson("/api/teacher/masjids/{$this->alpha->id}/school")->assertOk();

        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->teacher));
        $this->assertNull($this->seen($this->beta, $this->teacher), "school B's value is untouched by a request in school A");
        $this->assertSame($before, $this->rowWithoutStamp($this->alpha, $this->teacher), 'nothing else on the membership changed, updated_at included');

        Carbon::setTestNow(Carbon::parse('2026-09-30 08:30:00'));
        $this->getJson("/api/teacher/masjids/{$this->beta->id}/school")->assertOk();

        $this->assertSame('2026-09-30 08:30:00', $this->seen($this->beta, $this->teacher));
        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->teacher), "and school A's is untouched by a request in school B");
    }

    #[Test]
    public function an_administrators_bound_request_stamps_their_membership(): void
    {
        $this->assertNotNull(MasjidUser::where('masjid_id', $this->alpha->id)->where('user_id', $this->alphaAdmin->id)->first(), 'the owner has a membership row');

        Sanctum::actingAs($this->alphaAdmin, ['staff']);
        $this->getJson("/api/admin/masjids/{$this->alpha->id}/teachers")->assertOk();

        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->alphaAdmin));
        $this->assertNull($this->seen($this->beta, $this->betaAdmin), "another school's administrator is untouched");
        $this->assertNull($this->seen($this->alpha, $this->teacher), "and so is another login in the same school");
    }

    #[Test]
    public function lunch_staff_are_stamped_too(): void
    {
        // A masjid, because the lunch board is a capability a school does not have by default.
        $masjid = Masjid::create([
            'name' => 'Lunch Masjid', 'email' => 'lunch-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
        $lunch = User::factory()->create(['type' => User::TYPE_LUNCH_STAFF, 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $lunch->id, 'role' => 'lunch-staff', 'is_default' => true]);

        Sanctum::actingAs($lunch, ['staff']);
        $this->getJson("/api/lunch/masjids/{$masjid->id}/jummah-lunch/menus")->assertOk();

        $this->assertSame('2026-09-29 12:00:00', $this->seen($masjid, $lunch));
    }

    // ------------------------------------------------------------ it is not set by a refusal

    #[Test]
    public function a_refused_request_stamps_nothing(): void
    {
        $third = $this->makeSchoolWithAdmin('Gamma School')[0];

        Sanctum::actingAs($this->teacher, ['staff']);

        // 403: the resolver refuses a school the teacher holds no membership in.
        $this->getJson("/api/teacher/masjids/{$third->id}/school")->assertForbidden();
        // 404: the tenant binds, then the class is another school's.
        $this->getJson("/api/teacher/masjids/{$this->alpha->id}/groups/{$this->betaClass->id}")->assertNotFound();
        // 422: the tenant binds, then validation fails.
        $this->postJson("/api/teacher/masjids/{$this->alpha->id}/behavior-skills", [])->assertUnprocessable();

        $this->assertSame([], $this->allStamps(), 'no membership was stamped by any of the three refusals');
    }

    #[Test]
    public function an_unauthenticated_request_stamps_nothing(): void
    {
        $this->getJson("/api/teacher/masjids/{$this->alpha->id}/school")->assertUnauthorized();

        $this->assertSame([], $this->allStamps());
    }

    #[Test]
    public function a_request_that_binds_no_school_stamps_nothing(): void
    {
        Sanctum::actingAs($this->teacher, ['staff']);

        // `/teacher/user` names no school and sits outside the tenant middleware.
        $this->getJson('/api/teacher/user')->assertOk();

        $this->assertSame([], $this->allStamps());
    }

    #[Test]
    public function a_super_admin_acting_in_a_school_stamps_nobody(): void
    {
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);

        Sanctum::actingAs($super, ['staff']);
        $this->getJson("/api/admin/masjids/{$this->alpha->id}/teachers")->assertOk();

        $this->assertSame([], $this->allStamps(), 'a SuperAdmin holds no membership, and stamps none of the school\'s');
    }

    #[Test]
    public function the_ownership_fallback_membership_is_neither_created_nor_stamped(): void
    {
        // An owner with NO masjid_user row is bound from `masjids.user_id` by an unsaved
        // MasjidUser (TenantResolver::membershipFromOwnership). A read path must not
        // backfill authorisation rows, and an UPDATE that matches nothing must be all
        // the stamp does.
        config(['tenancy.multi_membership' => false]);
        MasjidUser::where('masjid_id', $this->alpha->id)->where('user_id', $this->alphaAdmin->id)->delete();
        $rows = MasjidUser::count();

        Sanctum::actingAs($this->alphaAdmin, ['staff']);
        $this->getJson("/api/admin/masjids/{$this->alpha->id}/teachers")->assertOk();

        $this->assertSame($rows, MasjidUser::count(), 'no membership row was created');
        $this->assertSame([], $this->allStamps());
    }

    #[Test]
    public function a_family_token_never_stamps_a_staff_membership(): void
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->alpha->id]);
        $contact->forceFill(['login_email' => 'parent-'.uniqid().'@test.local', 'login_enabled_at' => now()])->save();
        $token = $contact->refresh()->createFamilyToken()->plainTextToken;

        $family = $this->withHeader('Authorization', 'Bearer '.$token);
        $family->getJson("/api/family/masjids/{$this->alpha->id}/me")->assertOk();

        // And the staff realms refuse it before the tenant can bind.
        $this->assertGreaterThanOrEqual(400, $family->getJson("/api/teacher/masjids/{$this->alpha->id}/school")->getStatusCode());
        $this->assertGreaterThanOrEqual(400, $family->getJson("/api/admin/masjids/{$this->alpha->id}/teachers")->getStatusCode());

        $this->assertSame([], $this->allStamps(), 'not one staff membership carries a stamp after a family login');
    }

    // ----------------------------------------------------------------- the throttle

    #[Test]
    public function a_membership_is_stamped_at_most_once_every_five_minutes(): void
    {
        Sanctum::actingAs($this->teacher, ['staff']);
        $url = "/api/teacher/masjids/{$this->alpha->id}/school";

        $this->getJson($url)->assertOk();
        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->teacher));

        Carbon::setTestNow(Carbon::parse('2026-09-29 12:04:59'));
        $this->getJson($url)->assertOk();
        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->teacher), 'inside the window: unchanged');

        Carbon::setTestNow(Carbon::parse('2026-09-29 12:05:00'));
        $this->getJson($url)->assertOk();
        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->teacher), 'exactly five minutes is still inside it');

        Carbon::setTestNow(Carbon::parse('2026-09-29 12:05:01'));
        $this->getJson($url)->assertOk();
        $this->assertSame('2026-09-29 12:05:01', $this->seen($this->alpha, $this->teacher), 'past the window: stamped again');
    }

    #[Test]
    public function the_throttle_is_per_membership_not_per_person(): void
    {
        Sanctum::actingAs($this->teacher, ['staff']);

        $this->getJson("/api/teacher/masjids/{$this->alpha->id}/school")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-29 12:01:00'));
        $this->getJson("/api/teacher/masjids/{$this->beta->id}/school")->assertOk();

        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->teacher));
        $this->assertSame('2026-09-29 12:01:00', $this->seen($this->beta, $this->teacher), "school A's fresh stamp does not throttle school B");
    }

    #[Test]
    public function the_stamp_is_one_conditional_update_on_the_query_builder_and_fires_no_model_event(): void
    {
        $events = [];
        foreach (['saving', 'saved', 'updating', 'updated'] as $event) {
            MasjidUser::{$event}(function () use (&$events, $event): void {
                $events[] = $event;
            });
        }

        Sanctum::actingAs($this->teacher, ['staff']);
        $url = "/api/teacher/masjids/{$this->alpha->id}/school";

        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $log = DB::getQueryLog();
        DB::flushQueryLog();

        $updates = array_values(array_filter($log, fn (array $q): bool => str_starts_with($q['query'], 'update "masjid_user"')));

        $this->assertCount(1, $updates, 'exactly one write to masjid_user');
        $this->assertSame(
            'update "masjid_user" set "last_seen_at" = ? where "masjid_id" = ? and "user_id" = ? and ("last_seen_at" is null or "last_seen_at" < ?)',
            $updates[0]['query'],
            'one conditional statement, not a read and then a write, and no updated_at in it'
        );
        $this->assertSame([], $events, 'the stamp fires no model event');

        // Inside the window the SAME single statement runs and matches nothing.
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:01:00'));
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $again = array_values(array_filter(DB::getQueryLog(), fn (array $q): bool => str_starts_with($q['query'], 'update "masjid_user"')));
        DB::flushQueryLog();

        $this->assertCount(1, $again);
        $this->assertSame('2026-09-29 12:00:00', $this->seen($this->alpha, $this->teacher));
        $this->assertSame([], $events);
    }

    // ---------------------------------------------------------------- it cannot hurt a request

    #[Test]
    public function a_failing_stamp_never_reaches_the_caller_and_is_logged_at_warning(): void
    {
        Log::spy();

        // Whatever goes wrong with the write (a missing column, a locked row, a dead
        // connection) surfaces from inside the UPDATE.
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'update "masjid_user"') && str_contains($query->sql, 'last_seen_at')) {
                throw new \RuntimeException('simulated failure of the last_seen_at write');
            }
        });

        Sanctum::actingAs($this->teacher, ['staff']);
        $this->getJson("/api/teacher/masjids/{$this->alpha->id}/school")
            ->assertOk()
            ->assertJsonPath('data.id', $this->alpha->id);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'last_seen_at')
                && (int) ($context['masjid_id'] ?? 0) === (int) $this->alpha->id
                && str_contains((string) ($context['message'] ?? ''), 'simulated failure')
        );
    }

    // -------------------------------------------------------- what a school's screens show

    #[Test]
    public function a_school_shows_only_its_own_last_opened_for_a_shared_teacher(): void
    {
        DB::table('masjid_user')->where('masjid_id', $this->alpha->id)->where('user_id', $this->teacher->id)->update(['last_seen_at' => '2026-09-01 10:00:00']);
        DB::table('masjid_user')->where('masjid_id', $this->beta->id)->where('user_id', $this->teacher->id)->update(['last_seen_at' => '2026-09-02 11:00:00']);
        $alphaIso = Carbon::parse('2026-09-01 10:00:00')->toIso8601String();
        $betaIso = Carbon::parse('2026-09-02 11:00:00')->toIso8601String();

        foreach ([[$this->alphaAdmin, $this->alpha, $alphaIso, '2026-09-02T11:00'], [$this->betaAdmin, $this->beta, $betaIso, '2026-09-01T10:00']] as [$admin, $school, $own, $other]) {
            Sanctum::actingAs($admin, ['staff']);
            $base = "/api/admin/masjids/{$school->id}";

            $list = $this->getJson("{$base}/teachers")->assertOk();
            $show = $this->getJson("{$base}/teachers/{$this->teacher->id}")->assertOk();
            $team = $this->getJson("{$base}/team")->assertOk();

            $this->assertSame($own, collect($list->json('data'))->firstWhere('id', $this->teacher->id)['last_seen_at']);
            $show->assertJsonPath('data.last_seen_at', $own);
            $this->assertSame($own, collect($team->json('data.people'))->firstWhere('user_id', $this->teacher->id)['last_seen_at']);

            foreach ([$list, $show, $team] as $response) {
                $this->assertStringNotContainsString($other, $response->getContent(), "the other school's value is in a response for {$school->name}");
                $this->assertStringNotContainsString('last_sign_in_at', $response->getContent(), 'the global sign-in is gone');
            }
        }
    }

    #[Test]
    public function the_column_is_hidden_when_a_membership_is_serialised(): void
    {
        DB::table('masjid_user')->where('masjid_id', $this->alpha->id)->where('user_id', $this->teacher->id)->update(['last_seen_at' => '2026-09-01 10:00:00']);

        $membership = MasjidUser::where('masjid_id', $this->alpha->id)->where('user_id', $this->teacher->id)->firstOrFail();

        $this->assertNotNull($membership->last_seen_at, 'readable when a payload asks for it');
        $this->assertArrayNotHasKey('last_seen_at', $membership->toArray(), 'but never carried out by serialisation');

        $read = MembershipSeen::forOrganisation((int) $this->alpha->id, [(int) $this->teacher->id]);
        $this->assertSame(Carbon::parse('2026-09-01 10:00:00')->toIso8601String(), MembershipSeen::iso($read->get($this->teacher->id)));
        $this->assertSame([], MembershipSeen::forOrganisation((int) $this->beta->id, [(int) $this->teacher->id])->filter()->all(), "the other school's read is its own, and empty");
    }

    // ---------------------------------------------------------------- helpers

    /** The stored value for one membership, as the database holds it. */
    private function seen(Masjid $school, User $user): ?string
    {
        return DB::table('masjid_user')->where('masjid_id', $school->id)->where('user_id', $user->id)->value('last_seen_at');
    }

    /** Every membership that carries a stamp, as "masjid:user" keys. */
    private function allStamps(): array
    {
        return DB::table('masjid_user')->whereNotNull('last_seen_at')->get(['masjid_id', 'user_id'])
            ->map(fn ($row): string => "{$row->masjid_id}:{$row->user_id}")->sort()->values()->all();
    }

    /** The membership row without the stamp, so "nothing else changed" is one comparison. */
    private function rowWithoutStamp(Masjid $school, User $user): array
    {
        $row = (array) DB::table('masjid_user')->where('masjid_id', $school->id)->where('user_id', $user->id)->first();
        unset($row['last_seen_at']);

        return $row;
    }

    /** @return array{0: Masjid, 1: User} */
    private function makeSchoolWithAdmin(string $name): array
    {
        $school = Masjid::create([
            'name' => $name,
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $school->user_id = $admin->id;
        $school->save();
        // Production owners have a real membership row (the S2 backfill and the
        // provisioning hooks write one); a factory-made school has none, and its owner
        // would bind through the unsaved ownership fallback, which is never stamped.
        MasjidUser::ensureOwnerMembership((int) $school->id, (int) $admin->id);

        return [$school, $admin];
    }

    private function makeClass(Masjid $school, string $name): Group
    {
        return Group::factory()->create([
            'masjid_id' => $school->id, 'kind' => Group::KIND_CLASS,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name),
        ]);
    }
}
