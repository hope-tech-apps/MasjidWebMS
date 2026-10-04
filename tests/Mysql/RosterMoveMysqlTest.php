<?php

/*
|--------------------------------------------------------------------------
| Moving a student to another class, on the engine production runs
|--------------------------------------------------------------------------
|
| What SQLite cannot show about App\Support\RosterMove and the one list of what a roster row
| holds (App\Support\AcademicRecordsHeld::KEYS):
|
|   - the foreign keys into `group_memberships` and their real ON DELETE rules. On SQLite six of
|     them still cascade (the RESTRICT swap of 2026_09_09_040000 is MySQL only);
|   - a move leaving a record on EACH of the eleven keys where it was, under real RESTRICT keys;
|   - the two attendance reads. `session_date` is a DATE here and a midnight timestamp string on
|     SQLite, and "on that day" and "after the leaving day" compare differently on the two;
|   - the unique index over (class, person, role, child) when a student goes back to a class
|     where their row and their guardians' entries have left.
|
| The locks themselves are in tests/MysqlLocks/RosterMoveLocksTest.php: under RefreshDatabase this
| whole file is one transaction, where a lock on a row the transaction inserted is not visible
| and a second connection can see nothing.
*/

use App\Models\GroupMembership;
use App\Support\AcademicRecordsHeld;
use App\Support\RosterMove;
use App\Support\RosterMovePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSchoolRosters;
use Tests\Support\PlantsRosterRecords;

uses(BuildsSchoolRosters::class, PlantsRosterRecords::class);

beforeEach(function () {
    // 11:00 on Sunday 4 October 2026 on the school's clock.
    Carbon::setTestNow('2026-10-04 15:00:00');

    $this->school = $this->makeSchool();
    $this->admin = $this->makeAdmin($this->school);
    $this->first = $this->makeClass('1st Grade');
    $this->second = $this->makeClass('2nd Grade');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Move through the service itself: what is under test is the engine, not the route.
 *
 * `admin` is a protected property of BuildsSchoolRosters, so it is read from inside the test
 * (Closure::call binds the test's own scope); a plain function cannot read it.
 */
function rosterMoveOnMysql($test, GroupMembership $row, $to, string $on, array $options = []): RosterMovePlan
{
    $admin = (fn () => $this->admin)->call($test);

    return app(RosterMove::class)->move($test->first->is($row->group) ? $test->first : $row->group, $row, $to->id, $on, $options, $admin);
}

it('lists every foreign key into a roster row, with the rule the database really has', function () {
    $keys = collect(DB::select(
        "SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, r.DELETE_RULE AS d
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'group_memberships'"
    ))->mapWithKeys(fn ($row): array => [$row->t => [$row->c, strtolower($row->d)]])->all();

    $listed = collect(AcademicRecordsHeld::KEYS)->map(fn (array $key): array => [$key[0], $key[2]])->all();

    ksort($keys);
    ksort($listed);

    expect($keys)->toBe($listed);
    expect(collect($keys)->where(1, 'restrict'))->toHaveCount(7)
        ->and(collect($keys)->where(1, 'cascade'))->toHaveCount(2)
        ->and(collect($keys)->where(1, 'set null'))->toHaveCount(2);
});

it('leaves a record on each of the eleven keys with the old roster row and the old class', function (string $table) {
    $student = $this->enrol($this->first, 'Maryam');
    $record = $this->plantRecord($table, $student);
    $before = $this->rosterSnapshot();

    $plan = rosterMoveOnMysql($this, $student, $this->second, '2026-10-04');

    [$column] = AcademicRecordsHeld::KEYS[$table];

    expect($plan->path)->toBe(RosterMovePlan::LEFT_AND_STARTED)
        ->and((int) DB::table($table)->where('id', $record)->value($column))->toBe($student->id)
        ->and($this->classOfRecord($table, $record))->toBe($this->first->id)
        ->and((int) $student->fresh()->group_id)->toBe($this->first->id)
        ->and($plan->membershipId)->not->toBe($student->id);

    $this->assertNothingWasDestroyed($before);
})->with(array_keys(AcademicRecordsHeld::KEYS));

it('gives the days the old class marked to the old class, read from a DATE column', function () {
    $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
    $classmate = $this->enrol($this->second, 'Classmate');
    $this->plantRecord('attendance_records', $student, ['session_date' => '2026-09-27']);
    $this->plantRecord('attendance_records', $student, ['session_date' => '2026-10-04']);
    // The new class took its register on what will be the first day.
    $this->plantRecord('attendance_records', $classmate, ['session_date' => '2026-10-05']);

    $plan = rosterMoveOnMysql($this, $student, $this->second, '2026-09-27');

    expect($plan->oldClassKeepsMoveDay)->toBeTrue()
        ->and($plan->oldClassMarkedUpTo)->toBe('2026-10-04')
        ->and($plan->firstDay)->toBe('2026-10-05')
        ->and($plan->newClassTookRegisterOnFirstDay)->toBeTrue()
        ->and($student->fresh()->left_on->toDateString())->toBe('2026-10-04')
        ->and(GroupMembership::findOrFail($plan->membershipId)->joined_at->toDateString())->toBe('2026-10-05');
});

it('does not keep the move day for a mark dated the day before it', function () {
    $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
    $this->plantRecord('attendance_records', $student, ['session_date' => '2026-10-03']);

    $plan = rosterMoveOnMysql($this, $student, $this->second, '2026-10-04');

    expect($plan->oldClassKeepsMoveDay)->toBeFalse()
        ->and($plan->firstDay)->toBe('2026-10-04')
        ->and($student->fresh()->left_on->toDateString())->toBe('2026-10-03');
});

it('keeps the first joining day on a return unless the class took a register strictly after the leaving day', function () {
    $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
    $classmate = $this->enrol($this->first, 'Classmate');
    $there = GroupMembership::findOrFail(rosterMoveOnMysql($this, $student, $this->second, '2026-09-10')->membershipId);

    // A register on the LEAVING day itself (9 September) is not "while they were away".
    $this->plantRecord('attendance_records', $classmate, ['session_date' => '2026-09-09']);

    $kept = app(RosterMove::class)->preview($this->second, $there, $this->first, '2026-10-04');
    expect($kept->path)->toBe(RosterMovePlan::RETURNED)
        ->and($kept->registersMissed)->toBe(0)
        ->and($kept->joinedKept)->toBeTrue()
        ->and($kept->joinedOn)->toBe('2026-09-01');

    $this->plantRecord('attendance_records', $classmate, ['session_date' => '2026-09-13']);
    $this->plantRecord('attendance_records', $this->enrol($this->first, 'Another'), ['session_date' => '2026-09-13']);

    $plan = app(RosterMove::class)->move($this->second, $there, $this->first->id, '2026-10-04', [], $this->admin);

    // Two marks on one day are one register.
    expect($plan->registersMissed)->toBe(1)
        ->and($plan->joinedKept)->toBeFalse()
        ->and($student->fresh()->joined_at->toDateString())->toBe('2026-10-04')
        ->and($student->fresh()->left_on)->toBeNull();
});

it('goes back onto a place and entries that have left without a duplicate key', function () {
    $student = $this->enrol($this->first, 'Maryam');
    $parent = $this->guardian($student, 'Huda', consent: 'media');
    $claim = $this->guardian($student, 'Stranger', confirmed: false);
    $this->plantRecord('attendance_records', $student, ['session_date' => '2026-09-06']);

    $there = GroupMembership::findOrFail(rosterMoveOnMysql($this, $student, $this->second, '2026-10-04')->membershipId);
    $before = $this->rosterSnapshot();

    $plan = app(RosterMove::class)->move($this->second, $there, $this->first->id, '2026-10-04', [], $this->admin);

    expect($plan->path)->toBe(RosterMovePlan::RETURNED)
        ->and($plan->membershipId)->toBe($student->id)
        ->and(GroupMembership::where('group_id', $this->first->id)->where('contact_id', $student->contact_id)->whereNull('left_on')->count())->toBe(1)
        ->and($parent->fresh()->left_on)->toBeNull()
        ->and($parent->fresh()->hasConsent())->toBeTrue()
        ->and($claim->fresh()->left_on)->toBeNull()
        ->and($claim->fresh()->isConfirmed())->toBeFalse()
        // One entry per adult per class: nothing was inserted beside the ones that came back.
        ->and(GroupMembership::where('group_id', $this->first->id)->where('role', 'guardian')->count())->toBe(2)
        ->and(GroupMembership::where('group_id', $this->second->id)->where('role', 'guardian')->whereNull('left_on')->count())->toBe(0);

    $this->assertNothingWasDestroyed($before);
});

it('refuses a removal the database would refuse, before the database has to', function () {
    $student = $this->enrol($this->first, 'Maryam');
    $this->plantRecord('behavior_awards', $student, ['deleted_at' => now()]);

    $held = AcademicRecordsHeld::blocking(AcademicRecordsHeld::counts($student));

    expect(AcademicRecordsHeld::any($held))->toBeTrue()
        ->and(AcademicRecordsHeld::onlyDeleted($student))->toBeTrue();

    // The RESTRICT key is real: a delete that skipped the check is refused by the engine.
    expect(fn () => DB::table('group_memberships')->where('id', $student->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});
