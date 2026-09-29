<?php

namespace Tests\Feature;

use App\Http\Middleware\EchoResolvedTenant;
use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\BehaviorSkill;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\Avatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TeacherRealmWorld;
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

    /**
     * T1.2, GENERATED FROM THE ROUTE LIST, over REAL ids.
     *
     * The first version of this sweep was GET-only and filled every placeholder
     * but the class with 999999, so most routes answered 404 because no such row
     * exists, not because anything checked anything. This one seeds a real row
     * behind every id (TeacherRealmWorld) in two schools the teacher belongs to
     * (A and B), and in a class of each that they do NOT lead (A2, B2), and runs
     * every teacher route, every verb, through these legs:
     *
     *   1, 3    school A (B) in the URL, school B's (A's) class and ids -> 403 or 404
     *   2u, 4u  its OWN class, the other school's ids in the URL, a valid own body
     *           -> 403 or 404
     *   2b, 4b  its OWN class and URL ids, the other school's ids in the BODY
     *           -> the refusal the route's spec names (a 422, or a no-op 200)
     *   5       a third school the teacher holds no membership in -> 403, the tenant gate
     *   6, 7    a class of the SAME school the teacher does not lead -> 403 "You do
     *           not lead this class", which is `teacher.leads` and nothing else
     *
     * One foreign thing per leg: a request with a foreign id in the URL and another
     * in the body is refused by whichever is read first, and a 422 from the body
     * would hide a missing check on the URL id.
     *
     * After every refused request the database (every table with a `masjid_id`,
     * plus `users`, hashed row by row, so an UPDATE in place shows, not just an
     * INSERT or DELETE) and the fake disk must be exactly as they were, and the
     * body must not carry the other school's data.
     *
     * NON-VACUITY: the same request with the school's OWN ids must succeed (2xx),
     * inside a savepoint that is rolled back. A payload that is merely invalid
     * would make every attack pass on a 422, so a route whose control fails is
     * reported as a defect in the sweep, not skipped. A non-GET route with no spec,
     * or a spec with no route, fails the sweep too: a route added tomorrow cannot
     * dodge it. And it has been seen to fail: a controller that looks a row up
     * without the tenant scope turns it red (mutants in the build report).
     */
    #[Test]
    #[DataProvider('gateStates')]
    public function a_route_list_sweep_finds_no_bleed_between_the_two_schools_in_either_direction(bool $gateOpen): void
    {
        config(['tenancy.multi_membership' => $gateOpen]);

        // Notifications and pushes a write would send; the sweep is about who may
        // touch what, not about delivery.
        Queue::fake();
        Storage::fake((string) config('groups.media.disk', 'local'));
        Storage::fake((string) config('groups.resources.disk', 'local'));

        $skillA = BehaviorSkill::withoutMasjidScope()->where('masjid_id', $this->schoolA->id)->firstOrFail();
        $skillB = BehaviorSkill::withoutMasjidScope()->where('masjid_id', $this->schoolB->id)->firstOrFail();

        // The class store is OFF for every organisation by default; the sweep must exercise its
        // routes for real (a control that answers 403 because the school never switched the
        // store on would prove nothing), so both schools have it on, and paper cash-out too so
        // that route and the hand-out are swept and not skipped (W6, T-003.4).
        foreach ([$this->schoolA, $this->schoolB] as $school) {
            $school->forceFill(['capability_overrides' => ['class_store' => true]])->save();
            \App\Models\MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $school->id, 'paper_bucks_enabled' => true]);
        }

        // ALL fixtures before the first request (see TeacherRealmWorld).
        $a = TeacherRealmWorld::seed($this->schoolA, $this->classA, $this->teacher, $skillA, 'A', true);
        $b = TeacherRealmWorld::seed($this->schoolB, $this->classB, $this->teacher, $skillB, 'B', true);
        $a2 = TeacherRealmWorld::seed($this->schoolA, $this->makeClass($this->schoolA, 'MARK-A2-CLASS'), $this->teacher, $skillA, 'A2', false);
        $b2 = TeacherRealmWorld::seed($this->schoolB, $this->makeClass($this->schoolB, 'MARK-B2-CLASS'), $this->teacher, $skillB, 'B2', false);
        $third = $this->makeSchool('Gamma School');

        // The two schools' own administrators, for the last phase (before any request).
        $adminA = $this->makeAdminOf($this->schoolA);
        $adminB = $this->makeAdminOf($this->schoolB);

        $this->assertSame(
            [$this->classA->id, $this->classB->id],
            GroupStaff::withoutMasjidScope()->where('user_id', $this->teacher->id)->orderBy('group_id')->pluck('group_id')->map(fn ($id): int => (int) $id)->all(),
            'the teacher leads exactly the two seeded classes and neither A2 nor B2'
        );

        $routes = $this->teacherRoutes();
        $specs = $this->sweepSpecs();

        $this->assertGreaterThan(70, count($routes), 'The sweep found too few teacher routes; it is not looking at the right list.');

        $keys = array_map(fn (array $r): string => "{$r['method']} {$r['suffix']}", $routes);
        $unspecced = array_values(array_filter($keys, fn (string $k): bool => ! str_starts_with($k, 'GET ') && ! isset($specs[$k])));
        $this->assertSame([], $unspecced, 'These write routes have no sweep payload; add one to sweepSpecs() so they are swept: '.implode(', ', $unspecced));
        $dead = array_values(array_diff(array_keys($specs), $keys));
        $this->assertSame([], $dead, 'These sweep payloads name no teacher route (renamed or removed?): '.implode(', ', $dead));

        $tables = $this->snapshotTables();
        $failures = [];
        $attacks = 0;
        $controls = 0;
        $legsRun = [];

        // ---- phase 1: the refused requests -------------------------------------
        //
        // ONE foreign thing per leg. A request that carries a foreign id in the URL
        // AND another in the body is refused by whichever is looked at first, and a
        // 422 from the body hides a missing check on the URL id (the first version
        // of this leg let a mutant that dropped the tenant scope on a plan lookup
        // through exactly that way). So the URL-id legs send the caller's OWN,
        // valid body, and the body-id legs send the caller's OWN URL ids.
        //
        // label, school in the URL, world for {group_id}, world for the other URL ids,
        // world for the body, the kind of refusal owed, the tag whose data must not appear
        $legs = [
            ['1: A url, B class and ids', $a->school->id, $b, $b, $b, 'class', 'B'],
            ['2u: A url, own class, B ids in the URL', $a->school->id, $a, $b, $a, 'url-ids', 'B'],
            ['2b: A url, own class and URL ids, B ids in the body', $a->school->id, $a, $a, $b, 'body-ids', 'B'],
            ['3: B url, A class and ids', $b->school->id, $a, $a, $a, 'class', 'A'],
            ['4u: B url, own class, A ids in the URL', $b->school->id, $b, $a, $b, 'url-ids', 'A'],
            ['4b: B url, own class and URL ids, A ids in the body', $b->school->id, $b, $b, $a, 'body-ids', 'A'],
            ['5: third school url', $third->id, $a, $a, $a, 'tenant', 'A'],
            ['6: A url, class A2 (not led)', $a->school->id, $a2, $a2, $a2, 'leader', 'A2'],
            ['7: B url, class B2 (not led)', $b->school->id, $b2, $b2, $b2, 'leader', 'B2'],
        ];

        foreach ($routes as $route) {
            $key = "{$route['method']} {$route['suffix']}";
            $spec = $specs[$key] ?? [];
            $hasGroup = in_array('group_id', $route['placeholders'], true);
            $hasForeignUrlSlot = array_diff($route['placeholders'], ['masjid_id', 'group_id']) !== [];
            $bodyCarriesIds = isset($spec['body']) && $spec['body']($a) !== $spec['body']($b);

            foreach ($legs as [$label, $urlSchool, $groupWorld, $otherWorld, $bodyWorld, $kind, $foreignTag]) {
                if ($kind !== 'tenant' && ! $hasGroup) {
                    continue; // a school-level route has no class or foreign id to swap; only leg 5 applies
                }
                if ($kind === 'url-ids' && ! $hasForeignUrlSlot) {
                    continue; // nothing in the URL but the school and the caller's own class
                }
                if ($kind === 'body-ids' && ! $bodyCarriesIds) {
                    continue; // the body names no row
                }

                $url = $this->sweepUrl($route, (int) $urlSchool, $groupWorld, $otherWorld, $spec);

                // Nothing is stamped going in, so anything stamped coming out was this request's.
                DB::table('masjid_user')->update(['last_seen_at' => null]);
                $before = $this->snapshot($tables);

                $response = $this->send($route['method'], $url, $spec, $bodyWorld);
                $status = $response->getStatusCode();
                $body = (string) ($response->getContent() ?: '');

                $attacks++;
                $legsRun[$label] = ($legsRun[$label] ?? 0) + 1;

                $where = "{$key} [leg {$label}] -> {$status}";

                $allowed = match ($kind) {
                    'class', 'url-ids' => [403, 404],
                    'body-ids' => $spec['refuse'] ?? [403, 404],
                    'tenant', 'leader' => [403],
                };

                if (! in_array($status, $allowed, true)) {
                    $failures[] = "{$where}: expected one of ".implode('/', $allowed);
                }

                if ($kind === 'tenant' && $this->messageOf($body) !== ResolveMasjidTenant::FORBIDDEN_MESSAGE) {
                    $failures[] = "{$where}: the third school was not refused by the tenant gate (message: ".json_encode($this->messageOf($body)).')';
                }

                if ($kind === 'leader' && $this->messageOf($body) !== 'You do not lead this class.') {
                    $failures[] = "{$where}: the not-led class was not refused by teacher.leads (message: ".json_encode($this->messageOf($body)).')';
                }

                if ($this->carriesMarker($body, $foreignTag)) {
                    $failures[] = "{$where}: the response carries school/class {$foreignTag}'s data";
                }

                $changed = $this->changedTables($before, $this->snapshot($tables));
                if ($changed !== []) {
                    $failures[] = "{$where}: a refused request WROTE (".implode(', ', $changed).')';
                }

                // The "last opened" stamp: never on a refusal, and a request that DID go
                // through (a no-op 200) may stamp only the URL school's own membership.
                $stamped = $this->stampedMemberships();
                if ($status >= 400 && $stamped !== []) {
                    $failures[] = "{$where}: a refused request STAMPED last_seen_at (".implode(', ', $stamped).')';
                }
                if ($status < 400 && array_diff($stamped, ["{$urlSchool}:{$this->teacher->id}"]) !== []) {
                    $failures[] = "{$where}: a request stamped a membership other than the URL school's (".implode(', ', $stamped).')';
                }
            }
        }

        // ---- phase 2: the controls, reads first (a write may delete a file a read needs) ----
        $ordered = $routes;
        usort($ordered, fn (array $x, array $y): int => [$x['method'] !== 'GET', $x['suffix']] <=> [$y['method'] !== 'GET', $y['suffix']]);

        foreach ([$a, $b] as $world) {
            foreach ($ordered as $route) {
                $key = "{$route['method']} {$route['suffix']}";
                $spec = $specs[$key] ?? [];

                $url = $this->sweepUrl($route, (int) $world->school->id, $world, $world, $spec);

                DB::beginTransaction();
                try {
                    DB::table('masjid_user')->update(['last_seen_at' => null]);
                    $response = $this->send($route['method'], $url, $spec, $world);
                    $stamped = $this->stampedMemberships();
                } finally {
                    DB::rollBack();
                }

                $status = $response->getStatusCode();
                $controls++;

                if ($status < 200 || $status >= 300) {
                    $failures[] = "CONTROL {$key} in school {$world->tag} answered {$status} for its OWN ids "
                        .'(the sweep payload is wrong, so the refusals above prove nothing for this route): '
                        .substr((string) ($response->getContent() ?: ''), 0, 200);

                    continue;
                }

                if ($response->headers->get(EchoResolvedTenant::TENANT_HEADER) !== (string) $world->school->id) {
                    $failures[] = "CONTROL {$key} in school {$world->tag} did not echo the school it bound";
                }

                // Every route that went through opened THIS school, and only this one.
                if ($stamped !== ["{$world->school->id}:{$this->teacher->id}"]) {
                    $failures[] = "CONTROL {$key} in school {$world->tag} stamped ".json_encode($stamped)." instead of exactly its own school's membership";
                }

                $body = (string) ($response->getContent() ?: '');
                foreach (array_diff(['A', 'B', 'A2', 'B2'], [$world->tag]) as $tag) {
                    if ($this->carriesMarker($body, $tag)) {
                        $failures[] = "CONTROL {$key} in school {$world->tag} carries {$tag}'s data";
                    }
                }
            }
        }

        // ---- phase 3: a school's admins see only their OWN school's "last opened" ----
        // The teacher is in both schools with different values. Each school's Teachers
        // list, Teachers edit read and Team & Access carry its own and never the other's.
        DB::table('masjid_user')->update(['last_seen_at' => null]);
        DB::table('masjid_user')->where('user_id', $this->teacher->id)->where('masjid_id', $this->schoolA->id)->update(['last_seen_at' => '2026-09-01 10:00:00']);
        DB::table('masjid_user')->where('user_id', $this->teacher->id)->where('masjid_id', $this->schoolB->id)->update(['last_seen_at' => '2026-09-02 11:00:00']);

        foreach ([[$adminA, $this->schoolA, '2026-09-01 10:00:00', '2026-09-02'], [$adminB, $this->schoolB, '2026-09-02 11:00:00', '2026-09-01']] as [$admin, $school, $ownValue, $otherDate]) {
            Sanctum::actingAs($admin, ['staff']);
            $base = "/api/admin/masjids/{$school->id}";
            $expected = \Illuminate\Support\Carbon::parse($ownValue)->toIso8601String();

            $list = $this->getJson("{$base}/teachers");
            $show = $this->getJson("{$base}/teachers/{$this->teacher->id}");
            $team = $this->getJson("{$base}/team");

            foreach (['teachers list' => $list, 'teacher show' => $show, 'team' => $team] as $what => $response) {
                if ($response->getStatusCode() !== 200) {
                    $failures[] = "ADMIN VIEW {$what} for school {$school->name} answered {$response->getStatusCode()}";

                    continue;
                }
                if (str_contains($response->getContent(), $otherDate)) {
                    $failures[] = "ADMIN VIEW {$what} for school {$school->name} carries the OTHER school's last-opened ({$otherDate})";
                }
            }

            $seen = [
                'teachers list' => collect($list->json('data'))->firstWhere('id', $this->teacher->id)['last_seen_at'] ?? null,
                'teacher show' => $show->json('data.last_seen_at'),
                'team' => collect($team->json('data.people'))->firstWhere('user_id', $this->teacher->id)['last_seen_at'] ?? null,
            ];
            foreach ($seen as $what => $value) {
                if ($value !== $expected) {
                    $failures[] = "ADMIN VIEW {$what} for school {$school->name} shows ".json_encode($value)." instead of its own {$expected}";
                }
            }
        }

        $this->assertSame(count($routes) * 2, $controls);
        $this->assertGreaterThan(300, $attacks, 'The sweep ran over almost no requests.');

        // Every leg must have visited about as many routes as it applies to (64 class
        // routes, 73 routes in all, roughly 40 with an id in the URL beyond the class,
        // 11 with an id in the body), so a leg that quietly skips everything fails here.
        foreach ($legs as [$label, , , , , $kind]) {
            $floor = match ($kind) {
                'body-ids' => 8,
                'url-ids' => 35,
                'tenant' => 70,
                default => 60,
            };

            $this->assertGreaterThanOrEqual($floor, $legsRun[$label] ?? 0, "leg {$label} ran over too few routes: ".json_encode($legsRun));
        }


        $this->assertSame([], $failures, count($failures)." sweep failure(s) over {$attacks} refused requests and {$controls} controls:\n".implode("\n", $failures));
    }

    /**
     * What each write route needs to be a VALID request, so that a refusal is the
     * tenant/leader check and not validation. `body` is built from the world that
     * supplies the ids (the caller's own for a control, the other school's for a
     * leg-2 attack); `refuse` widens leg 2 for a foreign id carried in the body;
     * `query` and `files` are what the route reads besides JSON.
     *
     * A route with no entry here fails the sweep (see above).
     *
     * @return array<string, array{body?: callable, refuse?: list<int>, query?: array<string, mixed>, files?: callable}>
     */
    private function sweepSpecs(): array
    {
        $today = now()->toDateString();
        $soon = now()->addDays(3)->toDateString();
        $card = TeacherRealmWorld::CARD_PERIOD;
        $bodyRefusal = [403, 404, 422];

        return [
            'POST /behavior-skills' => [
                'body' => fn (TeacherRealmWorld $w) => ['label' => "MARK-{$w->tag}-NEW-SKILL", 'polarity' => BehaviorSkill::POLARITY_POSITIVE, 'default_points' => 2],
            ],

            // -- Arabic
            'PUT /groups/{group_id}/letters/stage' => ['body' => fn () => ['stage' => ArabicCurriculum::STAGE_TANWEEN]],
            'PUT /groups/{group_id}/members/{membership_id}/letters' => ['body' => fn () => ['drill_id' => 'ba', 'status' => ArabicCurriculum::STATUS_MASTERED]],
            'PUT /groups/{group_id}/members/{membership_id}/letters/master-all' => ['body' => fn () => []],
            'PUT /groups/{group_id}/members/{membership_id}/arabic-notes' => ['body' => fn () => ['session_date' => $today, 'note' => 'Sweep note.']],
            'DELETE /groups/{group_id}/members/{membership_id}/arabic-notes/{note_id}' => [],

            // -- behaviour
            'POST /groups/{group_id}/awards' => [
                'body' => fn (TeacherRealmWorld $w) => ['membership_id' => $w->student->id, 'behavior_skill_id' => $w->skill->id, 'points' => 1],
                'refuse' => $bodyRefusal,
            ],
            'DELETE /groups/{group_id}/awards/{award_id}' => [],

            // -- how the class's points read (T-003.2): a view choice on the class, no row of a child
            'PUT /groups/{group_id}/points-period' => ['body' => fn () => ['points_period' => 'weekly']],

            // -- the class store (T-003.4, W6): the realm's +5 write verbs. A redemption and its
            // reversal carry a real prize and a real entry of the caller's own class for the
            // control; a foreign prize in the body must be a refusal (422), and a foreign entry or
            // student in the URL a 404. The two prize writes name no row in their body (their
            // title is the same in every world), so only the URL's school and class can be foreign.
            'POST /groups/{group_id}/prizes' => [
                'body' => fn () => ['title' => 'Sweep prize', 'cost_bucks' => 3],
            ],
            'PUT /groups/{group_id}/prizes/{prize_id}' => [
                'body' => fn () => ['title' => 'Sweep prize edited', 'cost_bucks' => 4, 'stock' => 9],
            ],
            'POST /groups/{group_id}/members/{membership_id}/prizes/redeem' => [
                'body' => fn (TeacherRealmWorld $w) => ['prize_id' => $w->prize->id],
                'refuse' => $bodyRefusal,
            ],
            'POST /groups/{group_id}/members/{membership_id}/prizes/cash-out' => ['body' => fn () => ['amount' => 1]],
            'POST /groups/{group_id}/prize-entries/{entry_id}/reverse' => ['body' => fn () => ['note' => 'Sweep.']],

            // -- attendance
            'PUT /groups/{group_id}/attendance' => [
                'body' => fn (TeacherRealmWorld $w) => ['session_date' => $today, 'marks' => [['membership_id' => $w->student->id, 'status' => 'present']]],
                'refuse' => $bodyRefusal,
            ],

            // -- lesson plans
            'POST /groups/{group_id}/lesson-plans' => [
                'body' => fn (TeacherRealmWorld $w) => ['session_date' => $soon, 'subject' => 'Sweep', 'body' => 'Sweep plan.', 'resource_ids' => [$w->resource->id]],
                'refuse' => $bodyRefusal,
            ],
            'PUT /groups/{group_id}/lesson-plans' => [
                'body' => fn (TeacherRealmWorld $w) => ['session_date' => now()->addDays(4)->toDateString(), 'body' => 'Sweep plan.', 'resource_ids' => [$w->resource->id]],
                'refuse' => $bodyRefusal,
            ],
            'DELETE /groups/{group_id}/lesson-plans' => ['query' => ['date' => $today]],
            'PUT /groups/{group_id}/lesson-plans/{plan_id}' => [
                // A free day: the plan moves there, so a foreign plan that got through would
                // be WRITTEN, not refused for clashing with the class's own plan of the day.
                'body' => fn (TeacherRealmWorld $w) => ['session_date' => now()->addDays(5)->toDateString(), 'body' => 'Sweep plan.', 'resource_ids' => [$w->resource->id]],
                'refuse' => $bodyRefusal,
            ],
            'DELETE /groups/{group_id}/lesson-plans/{plan_id}' => [],

            // -- gradebook
            'POST /groups/{group_id}/assignments' => [
                'body' => fn () => ['title' => 'Sweep work', 'scale' => ClassAssignment::SCALE_POINTS, 'points_possible' => 10, 'assigned_on' => $today],
            ],
            'PUT /groups/{group_id}/assignments/{assignment_id}' => [
                'body' => fn () => ['title' => 'Sweep work', 'scale' => ClassAssignment::SCALE_POINTS, 'points_possible' => 10, 'assigned_on' => $today],
            ],
            'DELETE /groups/{group_id}/assignments/{assignment_id}' => [],
            'PUT /groups/{group_id}/assignments/{assignment_id}/scores' => [
                'body' => fn (TeacherRealmWorld $w) => ['scores' => [['membership_id' => $w->student->id, 'status' => 'scored', 'points_earned' => 5]]],
                'refuse' => $bodyRefusal,
            ],
            // W3 (T-001.2): a class-level setting with no id in its body, so only the
            // URL's school and class can be foreign to it.
            'PUT /groups/{group_id}/grade-weights' => [
                'body' => fn () => ['weights' => ['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]],
            ],

            // -- report cards. The period is named so the fixture card is the one prepared.
            'PUT /groups/{group_id}/members/{membership_id}/report-card' => [
                'query' => $card,
                // A foreign MARK id in the body is skipped by saveMarks, not refused: a
                // 200 that must write nothing, which the snapshot enforces.
                'body' => fn (TeacherRealmWorld $w) => ['marks' => [['id' => $w->mark->id, 'level' => null, 'comment' => 'Sweep.']]],
                'refuse' => [200, 403, 404, 422],
            ],
            'POST /groups/{group_id}/members/{membership_id}/report-card/publish' => ['query' => $card],
            'DELETE /groups/{group_id}/members/{membership_id}/report-card/publish' => ['query' => $card],

            // -- class files
            'POST /groups/{group_id}/resources' => [
                'body' => fn (TeacherRealmWorld $w) => ['title' => 'Sweep file', 'visibility' => 'students', 'recipient_membership_ids' => [$w->student->id]],
                'files' => fn () => ['file' => UploadedFile::fake()->create('sweep.pdf', 5, 'application/pdf')],
                'refuse' => $bodyRefusal,
            ],
            'PUT /groups/{group_id}/resources/{resource_id}' => [
                'body' => fn (TeacherRealmWorld $w) => ['title' => 'Sweep file', 'visibility' => 'students', 'recipient_membership_ids' => [$w->student->id]],
                'refuse' => $bodyRefusal,
            ],
            'DELETE /groups/{group_id}/resources/{resource_id}' => [],

            // -- Hifdh
            'POST /groups/{group_id}/hifz' => [
                'body' => fn (TeacherRealmWorld $w) => [
                    'membership_id' => $w->student->id, 'kind' => HifzEntry::KIND_SABAK,
                    'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 5,
                    'quality' => HifzEntry::QUALITY_GOOD,
                ],
                'refuse' => $bodyRefusal,
            ],
            'DELETE /groups/{group_id}/hifz/{entry_id}' => [],

            // -- class story
            'POST /groups/{group_id}/posts' => ['body' => fn () => ['title' => 'Sweep', 'body' => 'Sweep post.']],
            'PUT /groups/{group_id}/posts/{post_id}' => ['body' => fn () => ['body' => 'Sweep post, edited.']],
            'DELETE /groups/{group_id}/posts/{post_id}' => [],
            'POST /groups/{group_id}/posts/{post_id}/attachments/{attachment_id}/playback' => [],

            // -- conversations
            'POST /groups/{group_id}/threads' => [
                'body' => fn (TeacherRealmWorld $w) => ['subject' => 'Sweep', 'scope' => 'participant', 'about_membership_id' => $w->student->id, 'body' => 'Sweep hello.'],
                'refuse' => $bodyRefusal,
            ],
            'POST /groups/{group_id}/threads/{thread_id}/messages' => ['body' => fn () => ['body' => 'Sweep reply.']],
            'PUT /groups/{group_id}/threads/{thread_id}/messages/{message_id}/reactions/{reaction}' => [],
            'DELETE /groups/{group_id}/threads/{thread_id}/messages/{message_id}/reactions/{reaction}' => [],
            'POST /groups/{group_id}/threads/{thread_id}/messages/{message_id}/attachments/{attachment_id}/playback' => [],
            // A reaction on a class STORY post (2026-09-29, T-002.1).
            'PUT /groups/{group_id}/posts/{post_id}/reactions/{reaction}' => [],
            'DELETE /groups/{group_id}/posts/{post_id}/reactions/{reaction}' => [],

            // -- avatars
            'PUT /groups/{group_id}/members/{membership_id}/avatar' => [
                'body' => fn () => ['character' => Avatar::CHARACTERS[0], 'tone' => Avatar::TONES[0], 'color' => Avatar::COLORS[0]],
            ],
            'DELETE /groups/{group_id}/members/{membership_id}/avatar/override' => [],
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every route the teacher realm serves under a school, each verb on its own.
     *
     * @return list<array{method: string, uri: string, suffix: string, placeholders: list<string>}>
     */
    private function teacherRoutes(): array
    {
        $prefix = 'api/teacher/masjids/{masjid_id}';
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }

            preg_match_all('/\{([a-z_]+)\}/', $route->uri(), $names);

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $found[] = [
                    'method' => $method,
                    'uri' => $route->uri(),
                    'suffix' => substr($route->uri(), strlen($prefix)),
                    'placeholders' => $names[1],
                ];
            }
        }

        usort($found, fn (array $x, array $y): int => [$x['suffix'], $x['method']] <=> [$y['suffix'], $y['method']]);

        return $found;
    }

    /**
     * A route's URL with the school as given, `{group_id}` from one world and every
     * other id from another (the same world for a plain control or a leg 1/3 attack).
     *
     * @param array{uri: string, placeholders: list<string>} $route
     */
    private function sweepUrl(array $route, int $schoolId, TeacherRealmWorld $groupWorld, TeacherRealmWorld $otherWorld, array $spec): string
    {
        $url = str_replace('{masjid_id}', (string) $schoolId, $route['uri']);

        $url = (string) preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use ($route, $groupWorld, $otherWorld): string {
            $world = $m[1] === 'group_id' ? $groupWorld : $otherWorld;

            return rawurlencode($world->idFor($m[1], $route['uri']));
        }, $url);

        $query = $spec['query'] ?? [];

        return '/'.$url.($query === [] ? '' : '?'.http_build_query($query));
    }

    /** One request, with the body the route's spec builds from the given world. */
    private function send(string $method, string $url, array $spec, TeacherRealmWorld $bodyWorld): \Illuminate\Testing\TestResponse
    {
        $data = isset($spec['body']) ? $spec['body']($bodyWorld) : [];
        $files = isset($spec['files']) ? $spec['files']($bodyWorld) : [];

        if ($files !== []) {
            return $this->call($method, $url, $data, [], $files, ['HTTP_ACCEPT' => 'application/json']);
        }

        return $this->json($method, $url, $method === 'GET' ? [] : $data);
    }

    /**
     * Every table a teacher request could write: everything that carries a
     * `masjid_id`, plus the shared `users` row. Discovered, so a table added
     * tomorrow is watched without anybody remembering to list it.
     *
     * @return list<string>
     */
    private function snapshotTables(): array
    {
        $tables = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if ($name === 'users' || Schema::hasColumn($name, 'masjid_id')) {
                $tables[] = $name;
            }
        }

        $this->assertGreaterThan(30, count($tables), 'the snapshot found almost no tenant tables');
        $this->assertContains('lesson_plans', $tables);
        $this->assertContains('group_messages', $tables);

        return $tables;
    }

    /**
     * A hash of every watched table's rows, and of the private disks' file list.
     * Rows, not counts: `PUT /lesson-plans/{plan_id}` on another school's plan
     * changes a row in place and leaves every count alone.
     *
     * @param list<string> $tables
     * @return array<string, string>
     */
    private function snapshot(array $tables): array
    {
        $state = [];

        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->all();

            // `masjid_user.last_seen_at` is the one column a successful request MAY write
            // (ResolveMasjidTenant, only below 400). It is judged on its own terms by
            // stampedMemberships(); every OTHER column of the row, `updated_at` included,
            // stays in the hash, so the stamp cannot be a way to slip a write past it.
            if ($table === 'masjid_user') {
                $rows = array_map(fn (object $row): array => Arr::except((array) $row, ['last_seen_at']), $rows);
            }

            $state[$table] = md5(json_encode($rows, JSON_THROW_ON_ERROR));
        }

        $files = [];
        foreach (array_unique([(string) config('groups.media.disk', 'local'), (string) config('groups.resources.disk', 'local')]) as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $file) {
                $files[] = $disk.':'.$file;
            }
        }
        sort($files);
        $state['(files on disk)'] = md5(json_encode($files, JSON_THROW_ON_ERROR));

        return $state;
    }

    /** @return list<string> */
    private function changedTables(array $before, array $after): array
    {
        return array_keys(array_filter($after, fn (string $hash, string $table): bool => ($before[$table] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Every membership carrying a `last_seen_at`, as "masjid:user" keys.
     *
     * @return list<string>
     */
    private function stampedMemberships(): array
    {
        return DB::table('masjid_user')->whereNotNull('last_seen_at')->get(['masjid_id', 'user_id'])
            ->map(fn (object $row): string => "{$row->masjid_id}:{$row->user_id}")->sort()->values()->all();
    }

    /** The JSON `message` of a body, or null for anything else (a streamed file, a PDF, HTML). */
    private function messageOf(string $body): ?string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : null;
    }

    private function carriesMarker(string $body, string $tag): bool
    {
        return str_contains($body, "MARK-{$tag}-") || str_contains($body, "Mark{$tag}Student");
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

    /** The school's owner and administrator, so the admin screens can be read as the school sees them. */
    private function makeAdminOf(Masjid $school): User
    {
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $school->user_id = $admin->id;
        $school->save();
        MasjidUser::ensureOwnerMembership((int) $school->id, (int) $admin->id);

        return $admin;
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
