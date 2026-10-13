<?php

namespace Tests\Unit;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\OrganisationAccess;
use App\Support\StaffArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two small mechanisms "one login, a role at each organisation" stands on.
 *
 * 1. The ACTING KIND (User::actAs, getTypeAttribute). `$user->type` answers with the
 *    kind an area's gate admitted the request as, and the stored column is not
 *    touched by it in any way a save, a dirty check or a queue could see.
 * 2. The ROW RULE (MasjidUser::kindFor). The one place a row's role is read.
 *
 * Every refusal in OneLoginTwoRolesRealmTest is these two, composed. If one of the
 * properties here stopped holding, that file would still pass for a while (a test
 * request never saves the principal), and a login would be re-typed in production.
 */
class ActingKindTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function the_type_answers_with_the_acting_kind_and_the_column_stays_what_it_was(): void
    {
        $user = $this->login(User::TYPE_LUNCH_STAFF);

        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->type, 'with nothing set, the column');

        $user->actAs('Teacher');

        $this->assertSame('Teacher', $user->type);
        $this->assertSame('Teacher', $user->getAttribute('type'));
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->homeType());
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->getAttributes()['type'], 'not an attribute');
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->getOriginal('type'));
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->getRawOriginal('type'));

        $user->actAs(null);

        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->type, 'cleared');
    }

    #[Test]
    public function acting_is_never_dirty_and_a_save_never_writes_it(): void
    {
        $user = $this->login(User::TYPE_LUNCH_STAFF);
        $user->actAs('Teacher');

        $this->assertFalse($user->isDirty('type'));
        $this->assertFalse($user->isDirty());

        // A real save of the principal while it is acting: another column changes,
        // the kind does not.
        $user->name = 'Renamed While Acting';
        $this->assertSame(['name'], array_keys($user->getDirty()));
        $user->save();

        $this->assertSame(User::TYPE_LUNCH_STAFF, DB::table('users')->where('id', $user->id)->value('type'));
        $this->assertSame('Renamed While Acting', DB::table('users')->where('id', $user->id)->value('name'));
    }

    #[Test]
    public function a_save_while_acting_does_not_move_the_bridged_role(): void
    {
        // UserObserver runs syncRoleFromType() on every save. Reading the acting kind
        // there would hand a lunch login the `teacher` role (and take `lunch-staff`
        // away) the first time anything saved it inside the teacher area.
        $user = $this->login(User::TYPE_LUNCH_STAFF);
        $this->assertSame(['lunch-staff'], $user->getRoleNames()->all());

        $user->actAs('Teacher');
        $user->phone = '+15550009999';
        $user->save();
        $user->syncRoleFromType();

        $this->assertSame(['lunch-staff'], $user->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function the_serialized_model_speaks_in_the_acting_kind_and_a_model_without_the_column_gains_no_key(): void
    {
        $user = $this->login(User::TYPE_LUNCH_STAFF);
        $user->actAs('Teacher');

        $this->assertSame('Teacher', $user->toArray()['type'], 'the payload is in the voice of the area');
        $this->assertSame('Teacher', json_decode($user->toJson(), true)['type']);

        // A partial select (the doors read `id` and `email` only) must not sprout a
        // `type: null` key in whatever is built from it.
        $partial = User::query()->whereKey($user->id)->first(['id', 'email']);
        $this->assertArrayNotHasKey('type', $partial->toArray());
        $this->assertNull($partial->type);
    }

    #[Test]
    public function it_survives_the_clone_sign_in_asks_the_resolver_with_and_nothing_else(): void
    {
        $user = $this->login(User::TYPE_LUNCH_STAFF);
        $user->actAs('Teacher');

        // AuthController::attachMemberships asks the resolver about withoutRelations().
        $this->assertSame('Teacher', $user->withoutRelations()->type);

        // A model re-read by id is a different object and knows nothing of the request.
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->fresh()->type);
        $this->assertSame(User::TYPE_LUNCH_STAFF, User::findOrFail($user->id)->type);
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->replicate()->type);

        // refresh() reloads the SAME object: still this request, still acting.
        $this->assertSame('Teacher', $user->refresh()->type);
    }

    #[Test]
    public function it_does_not_cross_a_queue_or_a_cache(): void
    {
        // A job, a mail or a cached model is serialized. The acting kind belongs to
        // one request and must not be found on the other side.
        $user = $this->login(User::TYPE_LUNCH_STAFF);
        $user->actAs('Teacher');

        /** @var User $woken */
        $woken = unserialize(serialize($user));

        $this->assertSame(User::TYPE_LUNCH_STAFF, $woken->type);
    }

    #[Test]
    public function the_timetable_hook_still_sees_a_real_change_of_kind_and_only_a_real_one(): void
    {
        // AppServiceProvider refuses a change AWAY from Teacher while the account is
        // on a timetable. It reads isDirty('type') and getOriginal('type'); both must
        // be about the column.
        $teacher = $this->login('Teacher');
        $teacher->actAs(User::TYPE_LUNCH_STAFF);

        $this->assertFalse($teacher->isDirty('type'), 'acting is not a change of kind');
        $this->assertSame('Teacher', $teacher->getOriginal('type'));

        $teacher->actAs(null);
        $teacher->type = 'MasjidAdmin';

        $this->assertTrue($teacher->isDirty('type'), 'a real change still is');
        $this->assertSame('Teacher', $teacher->getOriginal('type'));
    }

    // ------------------------------------------------------------- the row rule

    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string}> home kind, role, the kind the row gives */
    public static function everyPair(): array
    {
        $cases = [];
        $roles = ['teacher', 'lunch-staff', 'masjid-admin', 'admin', 'owner', 'member', 'super-admin', '', null,
            // Not the role: a different string. Compared exactly, as PHP compares.
            'Teacher', 'teacher ', 'LUNCH-STAFF', 'lunch_staff'];

        foreach (['Teacher', User::TYPE_LUNCH_STAFF] as $home) {
            foreach ($roles as $role) {
                $expected = match ($role) {
                    'teacher' => 'Teacher',
                    'lunch-staff' => User::TYPE_LUNCH_STAFF,
                    default => $home,
                };
                $cases["{$home} with ".var_export($role, true)] = [$home, $role, $expected];
            }
        }

        // An administrator, a SuperAdmin, a member and no kind at all are never re-read.
        foreach (['MasjidAdmin', 'SuperAdmin', 'User', null] as $home) {
            foreach ($roles as $role) {
                $cases[var_export($home, true).' with '.var_export($role, true)] = [$home, $role, $home];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('everyPair')]
    public function a_row_gives_the_kind_of_its_scoped_role_only_to_a_scoped_login(?string $home, ?string $role, ?string $expected): void
    {
        $row = new MasjidUser(['role' => $role]);

        $this->assertSame($expected, $row->kindFor($home));
    }

    #[Test]
    public function the_two_lists_of_scoped_kinds_name_the_same_two_and_their_roles_are_the_bridged_ones(): void
    {
        $this->assertEqualsCanonicalizing(OrganisationAccess::SCOPED_TYPES, array_keys(MasjidUser::SCOPED_ROLES));
        $this->assertEqualsCanonicalizing(array_values(StaffArea::KIND_FOR_AREA), array_keys(MasjidUser::SCOPED_ROLES));

        foreach (MasjidUser::SCOPED_ROLES as $kind => $role) {
            $this->assertSame(User::TYPE_ROLE_MAP[$kind], $role, 'the row role is the role every door already writes for that kind');
        }
    }

    // ------------------------------------------------------------------ the gate

    #[Test]
    public function the_gate_clears_what_an_earlier_request_left_on_a_reused_model(): void
    {
        // Sanctum::actingAs() hands ONE instance to every request of a test. A lunch
        // login that was acting as a teacher must be lunch staff again the moment the
        // lunch gate looks at it.
        config(['tenancy.roles_per_organisation' => true]);

        $school = $this->organisation('school');
        $user = $this->login(User::TYPE_LUNCH_STAFF);
        MasjidUser::create(['masjid_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher', 'is_default' => true]);

        $this->assertTrue(StaffArea::admits($user, 'Teacher'));
        $this->assertSame('Teacher', $user->type);

        $this->assertTrue(StaffArea::admits($user, User::TYPE_LUNCH_STAFF));
        $this->assertSame(User::TYPE_LUNCH_STAFF, $user->type, 'its own area: nothing to act as');
    }

    #[Test]
    public function the_gate_never_sets_an_acting_kind_for_a_login_it_refuses_or_one_that_is_not_scoped(): void
    {
        config(['tenancy.roles_per_organisation' => true]);

        $school = $this->organisation('school');

        foreach (['MasjidAdmin', 'SuperAdmin', 'User'] as $kind) {
            $user = $this->login($kind);
            MasjidUser::create(['masjid_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher', 'is_default' => true]);

            $this->assertFalse(StaffArea::admits($user, 'Teacher'), "a {$kind} holding a teacher row is not a teacher");
            $this->assertFalse(StaffArea::admits($user, User::TYPE_LUNCH_STAFF));
            $this->assertSame($kind, $user->type);
        }

        $lunch = $this->login(User::TYPE_LUNCH_STAFF);
        $this->assertFalse(StaffArea::admits($lunch, 'Teacher'), 'no teacher row anywhere');
        $this->assertSame(User::TYPE_LUNCH_STAFF, $lunch->type);
    }

    #[Test]
    public function with_the_switch_off_the_gate_admits_the_home_kind_only_and_asks_nothing(): void
    {
        config(['tenancy.roles_per_organisation' => false]);

        $school = $this->organisation('school');
        $user = $this->login(User::TYPE_LUNCH_STAFF);
        MasjidUser::create(['masjid_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher', 'is_default' => true]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertFalse(StaffArea::admits($user, 'Teacher'));
        $this->assertTrue(StaffArea::admits($user, User::TYPE_LUNCH_STAFF));

        $this->assertSame([], DB::getQueryLog(), 'the switch off is today\'s comparison and no statement');
        DB::disableQueryLog();
    }

    // -------------------------------------------------------------------- helpers

    private function login(?string $type): User
    {
        return User::factory()->create(['type' => $type, 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    }

    private function organisation(string $orgType): Masjid
    {
        return Masjid::create([
            'name' => 'Unit '.uniqid(), 'email' => 'u'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
        ]);
    }
}
