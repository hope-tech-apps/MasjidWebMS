<?php

namespace Tests\Feature;

use App\Http\Middleware\EchoResolvedTenant;
use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One teacher, two schools, over HTTP (docs/multi-tenant-admin-design.md §1.3 and
 * T1.1 to T1.3, T1.15, T1.16 and the critic's L4 additions).
 *
 * The claim under test is that the resolver ALREADY serves a teacher in several
 * schools — every tenant-bound teacher route carries `{masjid_id}`, so the URL
 * says which school a request is about — and that nothing about the gate decides
 * it. That was code reading until this file; the gate-shut cases turn it into a
 * measurement, because §7 of the design rests on it (closing the gate must leave
 * a two-school teacher working).
 *
 * The other half is the leak: acting in school B must never return school A's
 * classes, students, messages, skills or calendar, and the reverse. The sweep at
 * the bottom is generated from the route list, not from a hand list, so a route
 * added tomorrow is swept without anybody remembering to add it.
 */
class TeacherMultiSchoolTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $schoolA;
    private Masjid $schoolB;
    private User $teacher;
    private Group $classA;
    private Group $classB;
    private GroupThread $threadA;
    private GroupThread $threadB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->schoolA = $this->makeSchool('Alpha School');
        $this->schoolB = $this->makeSchool('Beta School');

        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create(['masjid_id' => $this->schoolA->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
        MasjidUser::create(['masjid_id' => $this->schoolB->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => false]);

        $this->classA = $this->makeClass($this->schoolA, 'MARK-A-CLASS');
        $this->classB = $this->makeClass($this->schoolB, 'MARK-B-CLASS');

        $this->lead($this->classA);
        $this->lead($this->classB);

        $this->makeStudent($this->classA, 'MarkAStudent');
        $this->makeStudent($this->classB, 'MarkBStudent');

        $this->threadA = $this->makeThread($this->classA, 'MARK-A-THREAD', 'MARK-A-MESSAGE');
        $this->threadB = $this->makeThread($this->classB, 'MARK-B-THREAD', 'MARK-B-MESSAGE');

        BehaviorSkill::factory()->create(['masjid_id' => $this->schoolA->id, 'label' => 'MARK-A-SKILL']);
        BehaviorSkill::factory()->create(['masjid_id' => $this->schoolB->id, 'label' => 'MARK-B-SKILL']);

        SchoolYear::create(['masjid_id' => $this->schoolA->id, 'label' => 'MARK-A-YEAR', 'first_day' => '2026-09-01', 'last_day' => '2027-06-30']);
        SchoolYear::create(['masjid_id' => $this->schoolB->id, 'label' => 'MARK-B-YEAR', 'first_day' => '2026-09-01', 'last_day' => '2027-06-30']);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    // ------------------------------------------------------------ T1.1 / T1.3

    /** @return array<string, array{0: bool}> */
    public static function gateStates(): array
    {
        return ['gate open (production)' => [true], 'gate shut (the suite default)' => [false]];
    }

    #[Test]
    #[DataProvider('gateStates')]
    public function a_two_school_teacher_binds_whichever_school_the_url_names_and_no_third(bool $gateOpen): void
    {
        // T1.1 with the gate open, T1.3 with it shut: the SAME answers either way,
        // which is the design's §1.3 claim and the reason closing the gate is safe
        // for teachers.
        config(['tenancy.multi_membership' => $gateOpen]);

        $inA = $this->getJson("/api/teacher/masjids/{$this->schoolA->id}/groups")->assertOk();
        $this->assertSame([$this->classA->id], collect($inA->json('data'))->pluck('id')->all());

        $inB = $this->getJson("/api/teacher/masjids/{$this->schoolB->id}/groups")->assertOk();
        $this->assertSame([$this->classB->id], collect($inB->json('data'))->pluck('id')->all());

        $third = $this->makeSchool('Gamma School');
        $this->getJson("/api/teacher/masjids/{$third->id}/groups")
            ->assertForbidden()
            ->assertJsonPath('message', ResolveMasjidTenant::FORBIDDEN_MESSAGE);
    }

    #[Test]
    #[DataProvider('gateStates')]
    public function the_teacher_payload_lists_both_schools_by_name_with_the_default_marked(bool $gateOpen): void
    {
        // L4: `/teacher/user` is what the picker is built from. Both schools, by
        // NAME (a row with no name renders as "Organisation #14"), the default
        // marked, and — gate open or shut — exactly what the next request binds.
        config(['tenancy.multi_membership' => $gateOpen]);

        $response = $this->getJson('/api/teacher/user')->assertOk();

        $memberships = collect($response->json('data.memberships'))->keyBy('masjid_id');

        $this->assertEqualsCanonicalizing(
            [$this->schoolA->id, $this->schoolB->id],
            $memberships->keys()->map(fn ($id): int => (int) $id)->all()
        );
        $this->assertSame('Alpha School', $memberships[$this->schoolA->id]['masjid']['name']);
        $this->assertSame('Beta School', $memberships[$this->schoolB->id]['masjid']['name']);
        $this->assertTrue($memberships[$this->schoolA->id]['is_default']);
        $this->assertFalse($memberships[$this->schoolB->id]['is_default']);
        $this->assertSame('teacher', $memberships[$this->schoolB->id]['role']);

        // The login header still names the default membership (compatibility).
        $response->assertJsonPath('data.masjid.id', $this->schoolA->id);
    }

    // ------------------------------------------------------------------ T1.15

    #[Test]
    public function the_school_endpoint_names_the_bound_school_and_refuses_a_foreign_id(): void
    {
        $a = $this->getJson("/api/teacher/masjids/{$this->schoolA->id}/school")->assertOk();
        $a->assertJsonPath('data.id', $this->schoolA->id);
        $a->assertJsonPath('data.name', 'Alpha School');
        $a->assertJsonPath('data.school_calendar_published', true);

        // The SELECTED school, not the default: this is the header's whole point.
        $b = $this->getJson("/api/teacher/masjids/{$this->schoolB->id}/school")->assertOk();
        $b->assertJsonPath('data.id', $this->schoolB->id);
        $b->assertJsonPath('data.name', 'Beta School');
        $this->assertStringNotContainsString('Alpha School', $b->getContent());

        $third = $this->makeSchool('Gamma School');
        $this->getJson("/api/teacher/masjids/{$third->id}/school")->assertForbidden();
    }

    #[Test]
    public function the_calendar_flag_is_the_bound_schools_not_the_defaults(): void
    {
        // Alpha (the default) has a calendar; Beta does not. The header must not
        // offer Beta's teacher a Calendar link because Alpha has one.
        SchoolYear::withoutGlobalScopes()->where('masjid_id', $this->schoolB->id)->delete();

        $this->getJson("/api/teacher/masjids/{$this->schoolA->id}/school")->assertJsonPath('data.school_calendar_published', true);
        $this->getJson("/api/teacher/masjids/{$this->schoolB->id}/school")->assertJsonPath('data.school_calendar_published', false);
    }

    // ------------------------------------------------------------------ T1.16

    #[Test]
    public function every_teacher_tenant_response_echoes_the_school_the_server_bound(): void
    {
        foreach ([$this->schoolA, $this->schoolB] as $school) {
            foreach (['school', 'groups', 'school-calendar'] as $path) {
                $this->getJson("/api/teacher/masjids/{$school->id}/{$path}")
                    ->assertOk()
                    ->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $school->id);
            }
        }
    }

    #[Test]
    public function a_refused_school_is_never_echoed_as_the_bound_one(): void
    {
        // A refusal resolved no tenant, so it must not name one. (Laravel renders the
        // 403 inside the pipeline, so the outer echo still stamps the response — with
        // the literal `unbound`, which the SPA reads as "no echo", never a school id.)
        $third = $this->makeSchool('Gamma School');

        $response = $this->getJson("/api/teacher/masjids/{$third->id}/school")->assertForbidden();

        $this->assertContains(
            $response->headers->get(EchoResolvedTenant::TENANT_HEADER),
            [null, EchoResolvedTenant::UNBOUND],
            'a refused school must never be echoed as a bound tenant'
        );
    }

    // --------------------------------------------------------------------- L4

    #[Test]
    public function every_tenant_bound_teacher_and_lunch_route_carries_a_masjid_id(): void
    {
        // The standing rule (routes/teacher.php), pinned mechanically. A tenant-bound
        // route with no `{masjid_id}` is a 403 for every multi-school teacher
        // ("several memberships and no masjid in the route") while every
        // single-school teacher, and every test written for one, keeps passing.
        $seen = ['teacher' => 0, 'lunch' => 0];
        $offenders = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (['teacher', 'lunch'] as $realm) {
                if (! str_starts_with($route->uri(), "api/{$realm}/")) {
                    continue;
                }

                if (! in_array('tenant', $route->gatherMiddleware(), true)) {
                    continue;
                }

                $seen[$realm]++;

                if (! str_contains($route->uri(), '{masjid_id}')) {
                    $offenders[] = implode('|', $route->methods()).' /'.$route->uri();
                }
            }
        }

        $this->assertGreaterThan(20, $seen['teacher'], 'The sweep found almost no teacher routes; it is not looking at the right list.');
        $this->assertGreaterThan(5, $seen['lunch'], 'The sweep found almost no lunch routes; it is not looking at the right list.');
        $this->assertSame([], $offenders, 'Tenant-bound routes with no {masjid_id} would 403 every multi-school user: '.implode(', ', $offenders));
    }

    #[Test]
    public function the_two_id_less_teacher_routes_sit_outside_the_tenant_middleware(): void
    {
        // The other half of the rule: the routes that name no school are the ones
        // that must NOT be tenant-bound.
        $idLess = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/teacher/') && ! str_contains($route->uri(), '{masjid_id}')) {
                $idLess[$route->uri()] = in_array('tenant', $route->gatherMiddleware(), true);
            }
        }

        ksort($idLess);

        $this->assertSame(['api/teacher/logout' => false, 'api/teacher/user' => false], $idLess);
    }

    #[Test]
    public function every_teacher_tenant_route_is_wrapped_by_the_echo(): void
    {
        $unwrapped = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/teacher/masjids/')
                && ! in_array(EchoResolvedTenant::class, $route->gatherMiddleware(), true)) {
                $unwrapped[] = $route->uri();
            }
        }

        $this->assertSame([], $unwrapped);
    }

    // ------------------------------------------------- the cross-school leak

    #[Test]
    public function acting_in_school_b_never_returns_school_as_classes_students_or_messages(): void
    {
        $base = "/api/teacher/masjids/{$this->schoolB->id}";

        $groups = $this->getJson("{$base}/groups")->assertOk();
        $this->assertNoMarker($groups->getContent(), 'A');

        $class = $this->getJson("{$base}/groups/{$this->classB->id}")->assertOk();
        $this->assertStringContainsString('MarkBStudent', $class->getContent());
        $this->assertNoMarker($class->getContent(), 'A');
        $this->assertStringNotContainsString('MarkAStudent', $class->getContent());

        $threads = $this->getJson("{$base}/groups/{$this->classB->id}/threads")->assertOk();
        $this->assertStringContainsString('MARK-B-THREAD', $threads->getContent());
        $this->assertNoMarker($threads->getContent(), 'A');

        $thread = $this->getJson("{$base}/groups/{$this->classB->id}/threads/{$this->threadB->id}")->assertOk();
        $this->assertStringContainsString('MARK-B-MESSAGE', $thread->getContent());
        $this->assertNoMarker($thread->getContent(), 'A');

        // School A's class, thread and message are not reachable from B's URL by
        // swapping ids — Group is tenant-scoped, so they simply do not exist here.
        $this->getJson("{$base}/groups/{$this->classA->id}")->assertNotFound();
        $this->getJson("{$base}/groups/{$this->classA->id}/threads")->assertNotFound();
        $this->getJson("{$base}/groups/{$this->classA->id}/threads/{$this->threadA->id}")->assertNotFound();
        $this->getJson("{$base}/groups/{$this->classB->id}/threads/{$this->threadA->id}")->assertNotFound();

        $this->getJson("{$base}/behavior-skills")->assertOk()->assertJsonMissing(['label' => 'MARK-A-SKILL']);
    }

    #[Test]
    public function acting_in_school_a_never_returns_school_bs_data_either(): void
    {
        $base = "/api/teacher/masjids/{$this->schoolA->id}";

        $this->assertNoMarker($this->getJson("{$base}/groups")->assertOk()->getContent(), 'B');
        $this->assertNoMarker($this->getJson("{$base}/groups/{$this->classA->id}")->assertOk()->getContent(), 'B');
        $this->assertNoMarker($this->getJson("{$base}/groups/{$this->classA->id}/threads/{$this->threadA->id}")->assertOk()->getContent(), 'B');

        $this->getJson("{$base}/groups/{$this->classB->id}")->assertNotFound();
        $this->getJson("{$base}/groups/{$this->classB->id}/threads/{$this->threadB->id}")->assertNotFound();
    }

    #[Test]
    #[DataProvider('gateStates')]
    public function a_route_list_sweep_finds_no_bleed_between_the_two_schools_in_either_direction(bool $gateOpen): void
    {
        // T1.2, GENERATED. Every GET the teacher realm serves under a school, run
        // with A in the URL and — where the route takes a class — B's class id, and
        // the mirror. A class id from the other school must be a 403 or 404 (never
        // a 200), and no route may put the other school's marked data in a body.
        config(['tenancy.multi_membership' => $gateOpen]);

        $swept = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/teacher/masjids/{masjid_id}') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            foreach ([[$this->schoolA, $this->classB, 'B', $this->classA], [$this->schoolB, $this->classA, 'A', $this->classB]] as [$inSchool, $foreignClass, $foreignTag, $ownClass]) {
                $usesClass = str_contains($route->uri(), '{group_id}');

                // A foreign class id under this school's URL: refused.
                if ($usesClass) {
                    $status = $this->getJson($this->fill($route->uri(), $inSchool->id, $foreignClass->id))->getStatusCode();
                    $this->assertContains($status, [403, 404], "GET /{$route->uri()} with school {$inSchool->id} and a class of the other school answered {$status}");
                }

                // Its own class (or no class): whatever it says, it says nothing
                // about the other school.
                $response = $this->getJson($this->fill($route->uri(), $inSchool->id, $ownClass->id));
                $this->assertLessThan(500, $response->getStatusCode(), "GET /{$route->uri()} errored");
                $this->assertNoMarker($response->getContent(), $foreignTag, "GET /{$route->uri()} in school {$inSchool->id}");

                $swept++;
            }
        }

        $this->assertGreaterThan(40, $swept, 'The sweep ran over almost no routes.');
    }

    // ---------------------------------------------------------------- helpers

    /** Fill a route URI: the masjid and class as given, every other placeholder with an id that exists nowhere. */
    private function fill(string $uri, int $masjidId, int $groupId): string
    {
        $uri = str_replace(['{masjid_id}', '{group_id}'], [(string) $masjidId, (string) $groupId], $uri);

        return '/'.preg_replace('/\{[a-z_]+\}/', '999999', $uri);
    }

    /** No `MARK-<tag>-…` / `Mark<tag>…` string anywhere in the body. */
    private function assertNoMarker(string $body, string $tag, string $context = ''): void
    {
        $this->assertStringNotContainsString("MARK-{$tag}-", $body, "{$context}: school {$tag}'s data is in the response");
        $this->assertStringNotContainsString("Mark{$tag}Student", $body, "{$context}: school {$tag}'s student is in the response");
    }

    private function lead(Group $class): void
    {
        $class->staff()->attach($this->teacher->id, [
            'masjid_id' => $class->masjid_id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
    }

    private function makeClass(Masjid $school, string $name): Group
    {
        return Group::factory()->create([
            'masjid_id' => $school->id, 'kind' => Group::KIND_CLASS,
            'name' => $name, 'slug' => strtolower($name),
        ]);
    }

    private function makeStudent(Group $class, string $firstName): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $class->masjid_id, 'first_name' => $firstName, 'last_name' => 'Child',
        ]);

        return GroupMembership::create([
            'masjid_id' => $class->masjid_id, 'group_id' => $class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    private function makeThread(Group $class, string $subject, string $body): GroupThread
    {
        $thread = GroupThread::factory()->create([
            'masjid_id' => $class->masjid_id, 'group_id' => $class->id, 'subject' => $subject,
        ]);
        GroupMessage::factory()->create([
            'masjid_id' => $class->masjid_id, 'group_thread_id' => $thread->id, 'body' => $body,
        ]);

        return $thread;
    }

    private function makeSchool(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name,
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }
}
