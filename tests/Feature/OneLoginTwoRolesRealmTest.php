<?php

namespace Tests\Feature;

use App\Http\Middleware\EchoResolvedTenant;
use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\OneLoginWorld;
use Tests\TestCase;

/**
 * One login, a different role at each organisation: who is refused where.
 *
 * The valuable half of this file is the refusals, as it is in LunchStaffRealmTest,
 * which it is modelled on. A person who runs Friday lunch at a masjid and teaches at
 * a school now signs in once. The claim is that this opens exactly two things, each
 * through a row an office deliberately wrote: the lunch area AT THE MASJID and the
 * teacher area AT THE SCHOOL. Everything else stays shut: the lunch area at the
 * school where she teaches, the teacher area at the masjid where she runs lunch,
 * both areas anywhere else, and every administrator's surface everywhere.
 *
 * The refusals inside an area are generated from the ROUTE LIST, not from a hand
 * list, so a route added tomorrow on another branch is covered without anybody
 * remembering this feature exists: every route under `/api/teacher/masjids/{id}`
 * with the masjid's id, every route under `/api/lunch/masjids/{id}` with the
 * school's, every `auth:sanctum` route that carries the `admin` gate.
 *
 * Every test that says "her" runs twice (OneLoginWorld::bothPeople): for the login
 * created as lunch staff, the real case, and for its mirror created as a teacher.
 * The two differ only in `users.type`, which is the column that used to decide.
 *
 * Friday lunch is ON at both organisations, so the role is the only thing standing
 * between her and the school's board.
 */
class OneLoginTwoRolesRealmTest extends TestCase
{
    use OneLoginWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        config(['tenancy.roles_per_organisation' => true]);

        $this->buildOneLoginWorld();
    }

    // ------------------------------------------------------- what she CAN do

    #[Test]
    #[DataProvider('bothPeople')]
    public function the_lunch_area_admits_her_where_she_is_lunch_staff(string $who): void
    {
        $this->actingAsPerson($who);

        $this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->masjidMenu->id);

        $this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus/'.$this->masjidMenu->id.'/orders')
            ->assertOk()
            ->assertJsonPath('data.menu.id', $this->masjidMenu->id);
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function the_teacher_area_admits_her_at_the_school_where_she_teaches(string $who): void
    {
        $this->actingAsPerson($who);

        $this->getJson($this->teacherBase($this->school).'/school')
            ->assertOk()
            ->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $this->school->id);

        $classes = $this->getJson($this->teacherBase($this->school).'/groups')->assertOk();
        $this->assertSame([$this->class->id], collect($classes->json('data'))->pluck('id')->all(), 'the one class she leads, and not the other');

        $this->getJson($this->teacherBase($this->school).'/groups/'.$this->class->id)->assertOk();
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function each_area_hands_her_its_own_guide_book(string $who): void
    {
        // The guide books are chosen by kind (GuidesController::allowed). A lunch
        // login in the teacher area that still read as LunchStaff would be handed the
        // lunch book there; a teacher on the board, the teacher's.
        Storage::fake('local');
        $this->artisan('guides:install', ['folder' => base_path('tests/fixtures/guides/d1-1234abcd')])->assertExitCode(0);

        $this->actingAsPerson($who);

        $teacherBooks = $this->getJson($this->teacherBase($this->school).'/guides')->assertOk()->json('data');
        $this->assertSame(['teacher'], array_column($teacherBooks, 'book'));
        $this->getJson($this->teacherBase($this->school).'/guides/lunch')->assertNotFound();

        $lunchBooks = $this->getJson($this->lunchBase($this->masjid).'/guides')->assertOk()->json('data');
        $this->assertSame(['lunch'], array_column($lunchBooks, 'book'));
        $this->getJson($this->lunchBase($this->masjid).'/guides/teacher')->assertNotFound();
    }

    // ---------------------------------------------------- what she CANNOT do

    #[Test]
    #[DataProvider('bothPeople')]
    public function the_lunch_area_refuses_her_at_the_school_where_she_teaches(string $who): void
    {
        $this->actingAsPerson($who);

        // By hand first, the two requests the recon named: the board (the school HAS
        // Friday lunch, and a menu) and the guides, which carry no capability check.
        $this->assertTenantRefused($this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus'), 'the school\'s board');
        $this->assertTenantRefused($this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus/'.$this->schoolMenu->id), 'the school\'s menu by id');
        $this->assertTenantRefused($this->getJson($this->lunchBase($this->school).'/guides'), 'the school\'s lunch guides');

        // Then every route the lunch area has.
        $swept = $this->sweep('api/lunch/masjids/{masjid_id}', $this->school, fn ($response, $label) => $this->assertTenantRefused($response, $label));

        $this->assertGreaterThan(15, $swept, 'The sweep found almost no lunch routes; it is not looking at the right list.');
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function the_teacher_area_refuses_her_at_the_masjid_where_she_is_lunch_staff(string $who): void
    {
        $this->actingAsPerson($who);

        $this->assertTenantRefused($this->getJson($this->teacherBase($this->masjid).'/school'), 'the masjid as a school');
        $this->assertTenantRefused($this->getJson($this->teacherBase($this->masjid).'/groups'), 'the masjid\'s classes');
        $this->assertTenantRefused($this->getJson($this->teacherBase($this->masjid).'/guides'), 'the masjid\'s teacher guides');

        $swept = $this->sweep('api/teacher/masjids/{masjid_id}', $this->masjid, function ($response, $label) {
            $this->assertTenantRefused($response, $label);

            // A refusal bound nothing, so it must not name the masjid as the school
            // the server served (the teacher shell believes the echo).
            $this->assertContains(
                $response->headers->get(EchoResolvedTenant::TENANT_HEADER),
                [null, EchoResolvedTenant::UNBOUND],
                "{$label} echoed a bound tenant on a refusal"
            );
        });

        $this->assertGreaterThan(100, $swept, 'The sweep found too few teacher routes; it is not looking at the right list.');
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function the_teacher_area_still_refuses_a_class_she_does_not_lead(string $who): void
    {
        $this->actingAsPerson($who);

        $this->getJson($this->teacherBase($this->school).'/groups/'.$this->otherClass->id)->assertForbidden();
        $this->getJson($this->teacherBase($this->school).'/groups/'.$this->otherClass->id.'/threads')->assertForbidden();
        $this->getJson($this->teacherBase($this->school).'/groups/'.$this->otherClass->id.'/awards')->assertForbidden();
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function every_admin_endpoint_refuses_her_at_both_organisations(string $who): void
    {
        $this->actingAsPerson($who);

        // The list LunchStaffRealmTest holds, at the organisation where she is lunch
        // staff AND at the one where she teaches.
        foreach ([$this->masjid, $this->school] as $organisation) {
            foreach (['/contacts', '/donations', '/funds', '/broadcasts', '/teachers', '/administrators', '/events',
                '/announcements', '/jummah-lunch/menus', '/jummah-lunch/staff', '/team', ''] as $path) {
                $this->assertGateRefused(
                    $this->getJson($this->adminBase($organisation).$path),
                    "GET {$this->adminBase($organisation)}{$path}"
                );
            }

            $this->assertGateRefused(
                $this->postJson($this->adminBase($organisation).'/jummah-lunch/staff', ['name' => 'A Friend', 'email' => 'friend@example.invalid']),
                'creating lunch staff'
            );
            $this->assertGateRefused(
                $this->postJson($this->adminBase($organisation).'/teachers', ['name' => 'A Friend', 'email' => 'friend@example.invalid', 'class_ids' => [$this->class->id]]),
                'creating a teacher'
            );
        }

        $this->assertGateRefused($this->getJson('/api/admin/masjids'), 'the organisation list');
        $this->assertGateRefused($this->getJson('/api/admin/user'), 'the administrator\'s own /user');

        // Then every route behind the administrator's gate, with the id of the school
        // where an office made her a teacher, the place a "role" might be thought to
        // reach furthest. SuperAdmin-only routes are among them: `admin` refuses
        // before `super` is asked.
        $swept = 0;
        $superOnly = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true) || ! in_array('admin', $middleware, true)) {
                continue;
            }

            $superOnly += in_array('super', $middleware, true) ? 1 : 0;

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $url = $this->urlFor($route, $this->school);
                $this->assertGateRefused($this->json($method, $url), "{$method} {$url}");
                $swept++;
            }
        }

        $this->assertGreaterThan(450, $swept, 'The sweep found too few administrator routes; it is not looking at the right list.');
        $this->assertGreaterThan(40, $superOnly, 'The sweep should include the SuperAdmin-only routes.');
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function an_organisation_where_she_holds_no_row_refuses_her_in_both_areas(string $who): void
    {
        $this->actingAsPerson($who);

        $teacher = $this->sweep('api/teacher/masjids/{masjid_id}', $this->elsewhere, fn ($response, $label) => $this->assertTenantRefused($response, $label));
        $lunch = $this->sweep('api/lunch/masjids/{masjid_id}', $this->elsewhere, fn ($response, $label) => $this->assertTenantRefused($response, $label));

        $this->assertGreaterThan(100, $teacher);
        $this->assertGreaterThan(15, $lunch);
    }

    #[Test]
    public function a_refusal_says_the_same_thing_outside_debug_as_production_runs(): void
    {
        // The sentence in ResolveMasjidTenant::FORBIDDEN_MESSAGE reaches a client only
        // while app.debug is true, which is how the suite runs. Production's renderer
        // answers every HTTP exception with "Request failed." So nothing may hang an
        // area move on the sentence; this pins what a browser really receives.
        config(['app.debug' => false]);

        $this->actingAsPerson('shamina');

        foreach ([
            $this->lunchBase($this->school).'/jummah-lunch/menus',
            $this->teacherBase($this->masjid).'/school',
        ] as $url) {
            $response = $this->getJson($url);

            $this->assertSame(403, $response->getStatusCode(), $url);
            $this->assertSame(['status' => 'error', 'message' => 'Request failed.'], $response->json(), $url);
        }

        // The gate's refusal is a returned response, not an exception: identical in
        // and out of debug.
        $this->assertGateRefused($this->getJson($this->adminBase($this->school).'/teachers'));
    }

    // ------------------------------------------------- who she is told she is

    #[Test]
    #[DataProvider('bothPeople')]
    public function each_area_re_identifies_her_in_its_own_voice(string $who): void
    {
        $this->actingAsPerson($who);

        $areas = [
            ['area' => 'lunch', 'masjid_id' => $this->masjid->id, 'masjid' => ['id' => $this->masjid->id, 'name' => 'Burlington Masjid', 'org_type' => 'masjid']],
            ['area' => 'teacher', 'masjid_id' => $this->school->id, 'masjid' => ['id' => $this->school->id, 'name' => 'Al-Razi School', 'org_type' => 'school']],
        ];

        // The lunch voice: lunch staff, of the masjid, and the masjid is the only
        // organisation the lunch shell may put in a URL.
        $lunch = $this->getJson('/api/lunch/user')->assertOk();
        $lunch->assertJsonPath('data.type', User::TYPE_LUNCH_STAFF)
            ->assertJsonPath('data.masjid.id', $this->masjid->id)
            ->assertJsonPath('data.areas', $areas);
        $this->assertSame([[$this->masjid->id, 'lunch-staff']], $this->membershipPairs($lunch->json('data.memberships')));
        $this->assertArrayNotHasKey('school_calendar_published', $lunch->json('data'), 'the teacher extras are not in the lunch voice');
        $this->assertArrayNotHasKey('logo_url', $lunch->json('data.masjid'));

        // The teacher voice: a teacher, of the school, with the teacher shell's extras
        // about THAT school, and the school is the only one its picker is offered.
        $this->actingAsPerson($who);
        $teacher = $this->getJson('/api/teacher/user')->assertOk();
        $teacher->assertJsonPath('data.type', 'Teacher')
            ->assertJsonPath('data.masjid.id', $this->school->id)
            ->assertJsonPath('data.areas', $areas);
        $this->assertSame([[$this->school->id, 'teacher']], $this->membershipPairs($teacher->json('data.memberships')));
        $this->assertArrayHasKey('school_calendar_published', $teacher->json('data'));
        $this->assertArrayHasKey('logo_url', $teacher->json('data.masjid'));

        // `areas` is the last attribute, straight after `memberships`, with only the
        // `masjid` relation behind it: no key a one-role payload has was moved.
        foreach ([$teacher, $lunch] as $response) {
            $this->assertSame(['memberships', 'areas', 'masjid'], array_slice(array_keys($response->json('data')), -3));
        }

        // And the stored kind did not move.
        $this->assertSame($who === 'shamina' ? User::TYPE_LUNCH_STAFF : 'Teacher', DB::table('users')->where('id', $this->{$who}->id)->value('type'));
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function she_signs_in_once_and_lands_in_the_area_of_the_kind_her_login_was_created_as(string $who): void
    {
        $response = $this->postJson('/api/admin/login', ['email' => $this->{$who}->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        if ($who === 'shamina') {
            $response->assertJsonPath('data.user.type', User::TYPE_LUNCH_STAFF)->assertJsonPath('data.user.masjid.id', $this->masjid->id);
            $this->assertSame([[$this->masjid->id, 'lunch-staff']], $this->membershipPairs($response->json('data.user.memberships')));
        } else {
            $response->assertJsonPath('data.user.type', 'Teacher')->assertJsonPath('data.user.masjid.id', $this->school->id);
            $this->assertSame([[$this->school->id, 'teacher']], $this->membershipPairs($response->json('data.user.memberships')));
        }

        $this->assertSame(['lunch', 'teacher'], array_column($response->json('data.user.areas'), 'area'));

        // ONE token, and it works in both areas: no second sign-in.
        $token = $response->json('data.token');

        $this->withBearer($token)->getJson('/api/lunch/user')->assertOk()->assertJsonPath('data.type', User::TYPE_LUNCH_STAFF);
        $this->withBearer($token)->getJson('/api/teacher/user')->assertOk()->assertJsonPath('data.type', 'Teacher');
        $this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->assertOk();
        $this->withBearer($token)->getJson($this->teacherBase($this->school).'/groups')->assertOk();
        $this->withBearer($token)->getJson($this->lunchBase($this->school).'/jummah-lunch/menus')->assertForbidden();
        $this->withBearer($token)->getJson($this->teacherBase($this->masjid).'/groups')->assertForbidden();
        $this->assertGateRefused($this->withBearer($token)->getJson('/api/admin/user'));
    }

    // ------------------------------------------------------- the switch, off

    #[Test]
    #[DataProvider('bothPeople')]
    public function with_the_switch_off_the_second_area_is_closed_and_the_first_still_works(string $who): void
    {
        config(['tenancy.roles_per_organisation' => false]);

        $this->actingAsPerson($who);

        [$homeUser, $otherUser] = $who === 'shamina' ? ['/api/lunch/user', '/api/teacher/user'] : ['/api/teacher/user', '/api/lunch/user'];
        [$homeUrl, $otherUrl] = $who === 'shamina'
            ? [$this->lunchBase($this->masjid).'/jummah-lunch/menus', $this->teacherBase($this->school).'/groups']
            : [$this->teacherBase($this->school).'/groups', $this->lunchBase($this->masjid).'/jummah-lunch/menus'];

        // The other area: the gate refuses, as it does for any login of this kind.
        $this->assertGateRefused($this->getJson($otherUser), 'the other area\'s /user');
        $this->assertGateRefused($this->getJson($otherUrl), 'the other area');

        // The home area: unchanged, and it says nothing of a second one.
        $this->getJson($homeUrl)->assertOk();
        $home = $this->getJson($homeUser)->assertOk();
        $this->assertArrayNotHasKey('areas', $home->json('data'));
        $this->assertCount(1, $home->json('data.memberships'), 'only the home kind\'s organisation is offered');

        // And sign-in lands at home with no `areas`.
        $login = $this->postJson('/api/admin/login', ['email' => $this->{$who}->email, 'password' => 'password'])->assertOk();
        $login->assertJsonPath('data.user.type', $who === 'shamina' ? User::TYPE_LUNCH_STAFF : 'Teacher');
        $this->assertArrayNotHasKey('areas', $login->json('data.user'));
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function with_the_switch_off_the_other_kinds_row_still_does_not_bind_in_the_home_area(string $who): void
    {
        // THE TRAP THIS FEATURE HAD TO CLOSE FIRST. The gate read the kind and the
        // binder read "any live row": a lunch login given a teacher's row at a school
        // was bound by the LUNCH area there. The row filter is therefore not behind
        // the switch; this is the state after the switch has been turned off again
        // with such a row still in the table.
        config(['tenancy.roles_per_organisation' => false]);

        $this->actingAsPerson($who);

        if ($who === 'shamina') {
            $this->assertTenantRefused($this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus'), 'the school\'s board');
            $this->assertTenantRefused($this->getJson($this->lunchBase($this->school).'/guides'), 'the school\'s lunch guides');
            $this->sweep('api/lunch/masjids/{masjid_id}', $this->school, fn ($response, $label) => $this->assertTenantRefused($response, $label));
        } else {
            $this->assertTenantRefused($this->getJson($this->teacherBase($this->masjid).'/school'), 'the masjid as a school');
            $this->sweep('api/teacher/masjids/{masjid_id}', $this->masjid, fn ($response, $label) => $this->assertTenantRefused($response, $label));
        }
    }

    // --------------------------------------------- who gains nothing from a row

    #[Test]
    public function a_one_role_login_is_still_refused_by_the_other_area_with_the_switch_on(): void
    {
        $lunchOnly = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'Lunch Only');
        $this->giveRole($lunchOnly, $this->masjid, 'lunch-staff', true);
        $teacherOnly = $this->makeLogin('Teacher', 'Teacher Only');
        $this->giveRole($teacherOnly, $this->school, 'teacher', true);
        app(TenantContext::class)->forgetTenant();

        $this->actingAsPerson($lunchOnly);
        $this->assertGateRefused($this->getJson('/api/teacher/user'));
        $this->assertGateRefused($this->getJson($this->teacherBase($this->masjid).'/behavior-skills'), 'her own masjid through the teacher prefix');
        $this->assertGateRefused($this->getJson($this->teacherBase($this->school).'/groups'));
        $this->assertArrayNotHasKey('areas', $this->getJson('/api/lunch/user')->assertOk()->json('data'));

        $this->actingAsPerson($teacherOnly);
        $this->assertGateRefused($this->getJson('/api/lunch/user'));
        $this->assertGateRefused($this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus'), 'his own school through the lunch prefix');
        $this->assertGateRefused($this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus'));
        $this->assertArrayNotHasKey('areas', $this->getJson('/api/teacher/user')->assertOk()->json('data'));
    }

    #[Test]
    public function an_administrator_gains_nothing_from_a_scoped_row(): void
    {
        // No door writes this (both refuse an administrator's address); a hand edit
        // could. An administrator has ONE global set of permissions, so being made a
        // "teacher" somewhere must not carry them into a classroom, and it must not
        // cost them the administration they already had there either.
        $admin = $this->makeLogin('MasjidAdmin', 'Office With Odd Rows');
        $this->giveRole($admin, $this->school, 'teacher', true);
        $this->giveRole($admin, $this->masjid, 'lunch-staff', false);
        $this->lead($admin, $this->class);
        app(TenantContext::class)->forgetTenant();

        $this->actingAsPerson($admin);

        foreach ([$this->school, $this->masjid, $this->elsewhere] as $organisation) {
            $this->assertGateRefused($this->getJson($this->teacherBase($organisation).'/groups'), 'teacher area');
            $this->assertGateRefused($this->getJson($this->lunchBase($organisation).'/jummah-lunch/menus'), 'lunch area');
        }
        $this->assertGateRefused($this->getJson('/api/teacher/user'));
        $this->assertGateRefused($this->getJson('/api/lunch/user'));

        // The administrator's own area reads those rows exactly as it did before the
        // feature: a row binds, whatever its role says.
        $this->getJson($this->adminBase($this->school))->assertOk();
        $this->getJson($this->adminBase($this->elsewhere))->assertForbidden();

        $user = $this->getJson('/api/admin/user')->assertOk();
        $user->assertJsonPath('data.type', 'MasjidAdmin');
        $this->assertArrayNotHasKey('areas', $user->json('data'));
    }

    #[Test]
    public function a_member_login_and_a_superadmin_gain_nothing_from_a_scoped_row(): void
    {
        $member = $this->makeLogin('User', 'A Member');
        $this->giveRole($member, $this->school, 'teacher', true);
        $this->giveRole($member, $this->masjid, 'lunch-staff', false);

        $super = $this->makeLogin('SuperAdmin', 'A Super');
        $this->giveRole($super, $this->school, 'teacher', true);
        app(TenantContext::class)->forgetTenant();

        $this->actingAsPerson($member);
        $this->assertGateRefused($this->getJson('/api/teacher/user'));
        $this->assertGateRefused($this->getJson('/api/lunch/user'));
        $this->assertGateRefused($this->getJson('/api/admin/user'));
        $this->assertGateRefused($this->getJson($this->teacherBase($this->school).'/groups'));
        $this->assertGateRefused($this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus'));
        $this->assertGateRefused($this->getJson($this->adminBase($this->school)));

        $this->actingAsPerson($super);
        $this->assertGateRefused($this->getJson('/api/teacher/user'));
        $this->assertGateRefused($this->getJson($this->teacherBase($this->school).'/groups'));
        $this->assertGateRefused($this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus'));
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function a_row_at_an_archived_organisation_gives_no_area(string $who): void
    {
        // The organisation of her SECOND role is archived. Its row stays in the table
        // (restoring the organisation must bring its people back) and gives nothing.
        $second = $who === 'shamina' ? $this->school : $this->masjid;
        $second->delete();
        app(TenantContext::class)->forgetTenant();

        $this->actingAsPerson($who);

        [$homeUser, $otherUser] = $who === 'shamina' ? ['/api/lunch/user', '/api/teacher/user'] : ['/api/teacher/user', '/api/lunch/user'];

        $this->assertGateRefused($this->getJson($otherUser), 'the area of a role held only at an archived organisation');
        $this->assertGateRefused(
            $this->getJson($who === 'shamina' ? $this->teacherBase($second).'/groups' : $this->lunchBase($second).'/jummah-lunch/menus'),
            'the archived organisation itself'
        );

        $home = $this->getJson($homeUser)->assertOk();
        $this->assertArrayNotHasKey('areas', $home->json('data'), 'one live role is one area: nothing to switch to');
        $this->assertCount(1, $home->json('data.memberships'));
    }

    // -------------------------------------- a login that lost the role it began with

    #[Test]
    #[DataProvider('bothPeople')]
    public function a_login_that_lost_its_home_role_signs_in_to_the_area_it_still_has(string $who): void
    {
        // Her lunch access at the masjid is taken away; she still teaches. `users.type`
        // still says LunchStaff (no door rewrites it), so sign-in must not answer
        // "not linked to a masjid yet", and the lunch area must send the SPA on to
        // the area she has rather than sign her out.
        $home = $who === 'shamina' ? $this->masjid : $this->school;
        $kept = $who === 'shamina' ? $this->school : $this->masjid;
        $keptKind = $who === 'shamina' ? 'Teacher' : User::TYPE_LUNCH_STAFF;
        [$homeUser, $keptUser] = $who === 'shamina' ? ['/api/lunch/user', '/api/teacher/user'] : ['/api/teacher/user', '/api/lunch/user'];

        MasjidUser::where('masjid_id', $home->id)->where('user_id', $this->{$who}->id)->delete();
        MasjidUser::where('masjid_id', $kept->id)->where('user_id', $this->{$who}->id)->update(['is_default' => true]);

        $login = $this->postJson('/api/admin/login', ['email' => $this->{$who}->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.type', $keptKind)
            ->assertJsonPath('data.user.masjid.id', $kept->id);
        $this->assertArrayNotHasKey('areas', $login->json('data.user'), 'one kind of role left: one area');
        $this->assertCount(1, $login->json('data.user.memberships'));

        $token = $login->json('data.token');

        // The area she still has re-identifies her...
        $this->withBearer($token)->getJson($keptUser)->assertOk()->assertJsonPath('data.type', $keptKind)->assertJsonPath('data.masjid.id', $kept->id);

        // ...and the area of the role she lost answers as its own gate does: the SPA
        // reads 401 as "not this area" and asks the next one. Not a 200 "not linked".
        $this->assertGateRefused($this->withBearer($token)->getJson($homeUser), 'the /user of the area she lost');

        // Nothing in the lost area binds, at either organisation.
        $lostBase = $who === 'shamina' ? fn (Masjid $o) => $this->lunchBase($o).'/jummah-lunch/menus' : fn (Masjid $o) => $this->teacherBase($o).'/groups';
        $this->assertTenantRefused($this->withBearer($token)->getJson($lostBase($home)), 'where the role was');
        $this->assertTenantRefused($this->withBearer($token)->getJson($lostBase($kept)), 'where the other role is');

        // The stored kind was not rewritten to make this work.
        $this->assertSame($who === 'shamina' ? User::TYPE_LUNCH_STAFF : 'Teacher', DB::table('users')->where('id', $this->{$who}->id)->value('type'));
    }

    #[Test]
    public function with_the_switch_off_a_login_left_with_only_the_other_kinds_row_cannot_sign_in(): void
    {
        config(['tenancy.roles_per_organisation' => false]);

        MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->shamina->id)->delete();
        MasjidUser::where('masjid_id', $this->school->id)->where('user_id', $this->shamina->id)->update(['is_default' => true]);

        // Today's sentence for a lunch login with nowhere to go, and no user.
        $this->postJson('/api/admin/login', ['email' => $this->shamina->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Sorry, your account is not linked to a masjid yet.')
            ->assertJsonMissingPath('data');

        $this->actingAsPerson('shamina');
        $this->assertGateRefused($this->getJson('/api/teacher/user'));
        $this->assertGateRefused($this->getJson('/api/lunch/user'));
        $this->assertTenantRefused($this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus'));
    }

    // -------------------------------------------------------------- sessions

    #[Test]
    #[DataProvider('bothPeople')]
    public function signing_out_of_either_area_ends_both(string $who): void
    {
        // One login, one password, one sign-in: one sign-out.
        foreach (['teacher', 'lunch'] as $area) {
            $token = $this->{$who}->createToken('login-token', ['staff'])->plainTextToken;

            $this->withBearer($token)->postJson("/api/{$area}/logout")->assertOk();

            $this->assertSame(0, $this->{$who}->tokens()->count(), "signing out of the {$area} area left a token alive");
            $this->withBearer($token)->getJson('/api/lunch/user')->assertUnauthorized();
            $this->withBearer($token)->getJson('/api/teacher/user')->assertUnauthorized();
        }
    }

    // ------------------------------------------------------------ invariants

    #[Test]
    public function the_permission_count_is_still_eight_and_she_holds_none(): void
    {
        $this->assertSame(8, Permission::count());

        foreach (['shamina' => 'lunch-staff', 'mirror' => 'teacher'] as $who => $role) {
            $user = User::findOrFail($this->{$who}->id);

            $this->assertSame([$role], $user->getRoleNames()->all(), 'one bridged role, from the stored kind, and it did not gain a second');
            $this->assertCount(0, $user->getAllPermissions());

            // Being admitted to the other area does not move it either.
            $this->actingAsPerson($who);
            $this->getJson('/api/teacher/user')->assertOk();
            $this->getJson('/api/lunch/user')->assertOk();
            $this->assertSame([$role], User::findOrFail($user->id)->getRoleNames()->all());
        }
    }

    #[Test]
    public function the_switch_ships_off_and_is_read_in_one_place(): void
    {
        // Asserted against the file, as the multi-membership gate's default is: this
        // test class turns the switch ON in setUp, and a suite that only ever saw it
        // on could not notice the shipped default changing.
        $source = (string) file_get_contents(config_path('tenancy.php'));

        $this->assertMatchesRegularExpression(
            "/'roles_per_organisation'\s*=>\s*\(bool\)\s*env\(\s*'TENANCY_ROLES_PER_ORGANISATION'\s*,\s*false\s*\)/",
            $source,
            'config/tenancy.php must default roles_per_organisation to false.'
        );

        if (env('TENANCY_ROLES_PER_ORGANISATION') === null) {
            $this->assertFalse((require config_path('tenancy.php'))['roles_per_organisation']);
        }

        // One reader at run time, so that "off" cannot be half off: everything else
        // asks StaffArea::open(). (The one data migration reads it too, once, at the
        // deploy that ships the feature, to refuse to run after the switch is on;
        // a migration is a record and imports no application class.)
        $readers = [];

        foreach (['app', 'routes', 'bootstrap'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), "config('tenancy.roles_per_organisation'")) {
                    $readers[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame(['app/Support/StaffArea.php'], $readers);

        $migrations = array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $path): bool => str_contains((string) file_get_contents($path), 'tenancy.roles_per_organisation')
        ));
        $this->assertSame(['2026_10_14_120000_normalise_scoped_roles_on_masjid_user.php'], array_map('basename', $migrations));
    }

    #[Test]
    public function no_route_was_added_for_switching_and_every_staff_route_is_gated_and_cacheable(): void
    {
        // Moving between areas is the SPA asking the other area's existing /user, as
        // the administrators' organisation switcher has no endpoint either. So the
        // route cache holds nothing new for this feature (production serves from one;
        // a closure route once made a whole area answer 404 there).
        $idLess = ['teacher' => [], 'lunch' => []];
        $closures = [];
        $notExactlyOneGate = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            foreach (['teacher', 'lunch'] as $area) {
                if (str_starts_with($uri, "api/{$area}/") && ! str_contains($uri, '{masjid_id}')) {
                    $idLess[$area][] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$uri;
                }
            }

            if (preg_match('#^api/(admin|teacher|lunch)(/|$)#', $uri) && $route->getActionName() === 'Closure') {
                $closures[] = $uri;
            }

            // Every staff request passes exactly ONE gate, and the two scoped gates are
            // what set (or clear) the kind a request acts as.
            if (in_array('auth:sanctum', $middleware, true) && count(array_intersect(['admin', 'teacher', 'lunch'], $middleware)) !== 1) {
                $notExactlyOneGate[] = $uri;
            }
        }

        sort($idLess['teacher']);
        sort($idLess['lunch']);

        $this->assertSame(['GET api/teacher/user', 'POST api/teacher/logout'], $idLess['teacher']);
        $this->assertSame(['GET api/lunch/user', 'POST api/lunch/logout'], $idLess['lunch']);
        $this->assertSame([], $closures, 'A closure route cannot be relied on under the route cache.');
        $this->assertSame([], $notExactlyOneGate, 'A staff route outside the three gates would run with no area decided.');
    }

    // ---------------------------------------------------------------- helpers

    /** The tenant binder's refusal: 403 and the one sentence every fail-closed branch answers. */
    private function assertTenantRefused($response, string $what = ''): void
    {
        $this->assertSame(403, $response->getStatusCode(), "{$what} was not refused by the tenant binding (got {$response->getStatusCode()})");
        $this->assertSame(
            ['status' => 'error', 'message' => ResolveMasjidTenant::FORBIDDEN_MESSAGE],
            $response->json(),
            "{$what} was refused, but not by the tenant binding"
        );
    }

    /**
     * Every route under $prefix, each verb, with $organisation's id in the URL.
     *
     * @param  \Closure(\Illuminate\Testing\TestResponse, string): void  $assert
     * @return int how many requests were made
     */
    private function sweep(string $prefix, Masjid $organisation, \Closure $assert): int
    {
        $made = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $url = $this->urlFor($route, $organisation);
                $assert($this->json($method, $url), "{$method} {$url}");
                $made++;
            }
        }

        return $made;
    }

    /**
     * The route's URL with the organisation as given and every other id filled with
     * something its pattern accepts. Every refusal swept here comes BEFORE a
     * controller runs, so no other id is ever looked at; a real one would add nothing.
     */
    private function urlFor(RoutingRoute $route, Masjid $organisation): string
    {
        $url = str_replace('{masjid_id}', (string) $organisation->id, $route->uri());

        return '/'.preg_replace_callback('/\{([a-zA-Z_]+)\??\}/', function (array $m) use ($route): string {
            $pattern = $route->wheres[$m[1]] ?? null;

            return $pattern === null || preg_match('#^(?:'.$pattern.')$#', '1') ? '1' : 'x';
        }, $url);
    }

    /** @return list<array{0: int, 1: string}> */
    private function membershipPairs(array $memberships): array
    {
        return array_map(fn (array $m): array => [(int) $m['masjid_id'], (string) $m['role']], $memberships);
    }
}
