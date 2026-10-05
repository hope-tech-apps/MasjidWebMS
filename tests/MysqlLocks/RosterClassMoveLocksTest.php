<?php

/*
|--------------------------------------------------------------------------
| A whole class moved, on the engine production runs, against a second connection
|--------------------------------------------------------------------------
|
| App\Support\RosterClassMove moves a class ONE STUDENT AT A TIME: one transaction per student,
| nothing held across two students, one attempt per student, and one run at a time per class being
| left (a lock on the `database` cache store). SQLite has no row locks and the ordinary suite's
| cache store is `array`, so three things are shown only here:
|
|   (i)   a row somebody else holds costs the run ONE lock wait for that student, who is reported
|         "not moved, try again", and every other student of the run is moved;
|   (ii)  the run's lock is a committed row another connection can see, which is what makes it a
|         mutex between two requests and not only inside one process;
|   (iii) a single move of one of the run's students, made while the run holds that student, waits
|         on the child's contact row and is answered by the single move's own refusal.
|
| Committed fixtures and a second connection, for the reasons tests/MysqlLocks/RosterMoveLocksTest.php
| gives; the same guards (tests/Pest.php): MySQL only, and only a database named *_test. This file
| declares its own helper under its own name and re-uses none of that file's.
| NOT RUN where it was written: there is no MySQL server there. Its cleanup's table names are
| pinned by tests/Feature/RosterClassMoveTest.php, which always runs.
|
| Two more cases belong beside these and are the integrator's, because they need the pieces of
| several slices together: two runs in OPPOSITE directions with two siblings who share a parent,
| and a registration against a move.
*/

use App\Exceptions\RosterMoveRefused;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\AcademicRecordsHeld;
use App\Support\RosterClassMove;
use App\Support\RosterClassMovePlan;
use App\Support\RosterMove;
use App\Support\RosterMovePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSchoolRosters;

uses(BuildsSchoolRosters::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-04 15:00:00');

    // Committed, because a second connection has to see them.
    $this->school = $this->makeSchool();
    $this->admin = $this->makeAdmin($this->school);
    $this->first = $this->makeClass('1st Grade');
    $this->second = $this->makeClass('2nd Grade');

    config(['database.connections.mysql_other' => config('database.connections.'.config('database.default'))]);
    $this->other = DB::connection('mysql_other');
    $this->other->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function () {
    Carbon::setTestNow();

    // Skipped (not MySQL): nothing was committed, so there is nothing to delete.
    if (! isset($this->school)) {
        return;
    }

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    DB::purge('mysql_other');

    // The run's lock, should a test have failed while it was held.
    if (isset($this->first)) {
        DB::table('cache_locks')->where('key', 'like', '%'.RosterClassMove::lockName($this->first))->delete();
    }

    // In an order the RESTRICT keys allow: the records, the roster, then everything else.
    $school = $this->school->id;

    foreach (array_keys(AcademicRecordsHeld::KEYS) as $table) {
        DB::table($table)->where('masjid_id', $school)->delete();
    }

    // `masjid_user`, singular: the pivot's own name. RosterClassMoveTest pins every name here.
    foreach (['group_memberships', 'groups', 'contacts', 'masjid_user'] as $table) {
        DB::table($table)->where('masjid_id', $school)->delete();
    }

    DB::table('masjids')->where('id', $school)->delete();
    DB::table('users')->where('id', $this->admin->id)->delete();
});

/**
 * What the dialog sends for every student of a class preview who can move: each roster row with
 * what the preview showed for it. Takes the plan, so it reads nothing off the test.
 *
 * @return list<array<string, mixed>>
 */
function rosterClassMoveTicked(RosterClassMovePlan $preview): array
{
    return array_values(array_map(fn (array $student): array => [
        'membership_id' => $student['membership_id'],
        'expected_path' => $student['path'],
        'expected_first_day' => $student['first_day_in_new_class'],
        'expected_joined_on' => $student['joined_on'],
        'expected_consent' => $student['expected_consent'],
        'expected_grade' => $student['grade_after'],
    ], array_filter($preview->toPreview()['students'], fn (array $student): bool => $student['can_move'])));
}

/**
 * A run whose single move times each student and, when every lock of one student's move is held,
 * calls `$atLocks(contact id)`. Both are handed in: this is another class, so it cannot read the
 * test's protected members, and the closure is made inside the test, where `$this` is the test.
 *
 * `$times->seconds` collects how long each student's move took, by roster row id.
 */
function rosterClassMoveTimedRun(object $times, ?Closure $atLocks = null): RosterClassMove
{
    return new RosterClassMove(new class($times, $atLocks) extends RosterMove {
        private ?int $contact = null;

        public function __construct(private object $times, private ?Closure $atLocks)
        {
        }

        public function move(Group $from, GroupMembership $row, int $toGroupId, string $on, array $options, ?User $actor): RosterMovePlan
        {
            $this->contact = (int) $row->contact_id;
            $started = microtime(true);

            try {
                return parent::move($from, $row, $toGroupId, $on, $options, $actor);
            } finally {
                $this->times->seconds[(int) $row->getKey()] = microtime(true) - $started;
            }
        }

        protected function locksTaken(): void
        {
            if ($this->atLocks !== null) {
                ($this->atLocks)($this->contact);
            }
        }
    });
}

it('moves nine of ten when one student\'s row is held, after one lock wait, and offers the tenth again', function () {
    $rows = collect(range(1, 10))->map(fn (int $i) => $this->enrol($this->first, 'Pupil'.$i));
    $held = $rows[4];

    $times = (object) ['seconds' => []];
    $run = rosterClassMoveTimedRun($times);
    $ticked = rosterClassMoveTicked($run->preview($this->first, $this->second, '2026-10-04', ['mode' => 'keep']));

    expect($ticked)->toHaveCount(10);

    // The second connection holds the fifth student's roster row for the whole run, as a
    // class-store redemption or a register save in flight does.
    $this->other->beginTransaction();
    $this->other->table('group_memberships')->where('id', $held->id)->lockForUpdate()->first();

    try {
        $answer = $run->run($this->first, $this->second->id, '2026-10-04', ['mode' => 'keep'], null, $ticked, $this->admin)->toAnswer();
    } finally {
        $this->other->rollBack();
    }

    $outcomes = array_column($answer['students'], 'outcome', 'membership_id');

    expect($answer['moved'])->toBe(9)
        ->and($answer['not_moved'])->toBe(1)
        ->and($answer['not_reached'])->toBe(0)
        ->and($answer['stopped_by_fault'])->toBeFalse()
        ->and($outcomes[$held->id])->toBe(RosterClassMovePlan::NOT_MOVED);

    $reported = collect($answer['students'])->firstWhere('membership_id', $held->id);

    // The single move's own answer for a held row, and worth offering again.
    expect($reported['reason'])->toBe(RosterMoveRefused::CHANGED)
        ->and($reported['retry'])->toBeTrue()
        // ONE attempt inside a run: it really waited for the lock, once, and not three times.
        ->and($times->seconds[$held->id])->toBeGreaterThan(RosterMove::LOCK_WAIT_SECONDS - 1)
        ->and($times->seconds[$held->id])->toBeLessThan(RosterMove::LOCK_WAIT_SECONDS * 2)
        // The session's own lock wait is put back.
        ->and((int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS s')->s)->toBeGreaterThan(RosterMove::LOCK_WAIT_SECONDS);

    // The nine are in the new class; nothing about the tenth changed, and nothing of theirs was half written.
    foreach ($rows as $row) {
        $there = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $row->contact_id)->count();

        if ($row->id === $held->id) {
            expect($row->fresh()->left_on)->toBeNull()->and($there)->toBe(0);
        } else {
            expect($row->fresh()->left_on)->not->toBeNull()->and($there)->toBe(1);
        }
    }

    // The run's lock was given back.
    expect(DB::table('cache_locks')->where('key', 'like', '%'.RosterClassMove::lockName($this->first))->count())->toBe(0);

    // HOW LONG ONE MOVE TAKES ON MYSQL: nobody had measured it, and it decides how many students a
    // request may carry and the run's time budget. Milliseconds only, no name and no id.
    $moves = collect($times->seconds)->except($held->id)->map(fn (float $s): int => (int) round($s * 1000))->sort()->values();
    fwrite(STDERR, sprintf(
        "\n[roster class move on MySQL] %d moves of a student with nothing recorded: median %d ms, slowest %d ms\n",
        $moves->count(), $moves[intdiv($moves->count(), 2)], $moves->last(),
    ));

    // "Move the rest": the row is free now, and a fresh check and a new run move the tenth.
    $rest = rosterClassMoveTicked($run->preview($this->first, $this->second, '2026-10-04', ['mode' => 'keep']));

    expect(array_column($rest, 'membership_id'))->toBe([$held->id]);

    $again = $run->run($this->first, $this->second->id, '2026-10-04', ['mode' => 'keep'], null, $rest, $this->admin)->toAnswer();

    expect($again['moved'])->toBe(1)
        ->and($held->fresh()->left_on)->not->toBeNull();
});

it('takes its lock as a committed row another connection sees, and refuses a second run out of the class while it is held', function () {
    $this->enrol($this->first, 'Pupil1');
    $this->enrol($this->first, 'Pupil2');

    $name = RosterClassMove::lockName($this->first);
    $run = app(RosterClassMove::class);
    $ticked = rosterClassMoveTicked($run->preview($this->first, $this->second, '2026-10-04', ['mode' => 'keep']));

    // Somebody else's run out of this class holds the lock. On the store the run names, whatever
    // the default store is: a lock of this name on the default store is not the run's lock.
    $theirs = Cache::store(RosterClassMove::LOCK_STORE)->lock($name, RosterClassMove::LOCK_SECONDS);

    expect($theirs->get())->toBeTrue()
        // Committed: the second connection sees it. That is what an in-process lock cannot give.
        ->and($this->other->table('cache_locks')->where('key', 'like', '%'.$name)->count())->toBe(1);

    $refused = null;

    try {
        $run->run($this->first, $this->second->id, '2026-10-04', ['mode' => 'keep'], null, $ticked, $this->admin);
    } catch (RosterMoveRefused $e) {
        $refused = $e;
    }

    expect($refused)->not->toBeNull()
        ->and($refused->status())->toBe(409)
        ->and($refused->getMessage())->toBe('A move out of 1st Grade is still running (it may be yours). Wait two minutes, then reload this roster.')
        // Refused before anything was written.
        ->and(GroupMembership::where('group_id', $this->second->id)->count())->toBe(0);

    $theirs->release();

    // While this run holds it, the second connection sees the row; afterwards it is gone.
    $seen = (object) ['held' => []];
    $other = $this->other;
    $watched = rosterClassMoveTimedRun((object) ['seconds' => []], function () use ($seen, $other, $name): void {
        $seen->held[] = $other->table('cache_locks')->where('key', 'like', '%'.$name)->count();
    });

    $answer = $watched->run($this->first, $this->second->id, '2026-10-04', ['mode' => 'keep'], null, $ticked, $this->admin)->toAnswer();

    expect($answer['moved'])->toBe(2)
        ->and($seen->held)->toBe([1, 1])
        ->and($this->other->table('cache_locks')->where('key', 'like', '%'.$name)->count())->toBe(0);
});

it('makes a single move of one of its students wait on the contact row, and that move gets the single move\'s own refusal', function () {
    $one = $this->enrol($this->first, 'Pupil1');
    $two = $this->enrol($this->first, 'Pupil2');

    // WHAT THE SECOND MOVER NEEDS, AS PLAIN VALUES. It runs on the second connection, from inside
    // the run's own move of the same student, while that move holds every one of its locks.
    $with = (object) [
        'contact' => (int) $two->contact_id,
        'row' => $two->id,
        'first' => $this->first->id,
        'second' => $this->second->id,
        'admin' => $this->admin->id,
        'refusal' => null,
        'seconds' => null,
    ];

    $single = function (int $contact) use ($with): void {
        if ($contact !== $with->contact) {
            return;
        }

        // The single verb, as another request makes it: its own connection, its own three attempts.
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_other');
        $started = microtime(true);

        try {
            (new RosterMove())->move(
                Group::query()->findOrFail($with->first),
                GroupMembership::query()->findOrFail($with->row),
                $with->second,
                '2026-10-04',
                [],
                User::query()->findOrFail($with->admin),
            );
        } catch (RosterMoveRefused $refused) {
            $with->refusal = [$refused->status(), $refused->getMessage()];
        } finally {
            $with->seconds = microtime(true) - $started;
            DB::setDefaultConnection($default);
        }
    };

    $run = rosterClassMoveTimedRun((object) ['seconds' => []], $single);
    $ticked = rosterClassMoveTicked($run->preview($this->first, $this->second, '2026-10-04', ['mode' => 'keep']));

    $answer = $run->run($this->first, $this->second->id, '2026-10-04', ['mode' => 'keep'], null, $ticked, $this->admin)->toAnswer();

    // The single move waited for the child's contact row, which the run held, and was answered
    // "this roster changed", never a 500 and never a second place for the child.
    expect($with->refusal)->toBe([409, RosterMoveRefused::CHANGED])
        ->and($with->seconds)->toBeGreaterThan(RosterMove::LOCK_WAIT_SECONDS - 1)
        // The run was not disturbed by it.
        ->and($answer['moved'])->toBe(2)
        ->and($answer['not_moved'])->toBe(0)
        ->and(GroupMembership::where('group_id', $this->second->id)->where('contact_id', $two->contact_id)->count())->toBe(1)
        ->and(GroupMembership::where('group_id', $this->second->id)->where('contact_id', $one->contact_id)->count())->toBe(1);

    // Whichever comes second is told where the student went: the same single move, made after the
    // run's commit, is refused with the shipped sentence and the class to open.
    $late = null;

    try {
        app(RosterMove::class)->move($this->first, $two->fresh(), $this->second->id, '2026-10-04', [], $this->admin);
    } catch (RosterMoveRefused $e) {
        $late = $e;
    }

    expect($late)->not->toBeNull()
        ->and($late->status())->toBe(422)
        ->and($late->getMessage())->toBe('Pupil2 Student was moved to 2nd Grade on 4 Oct 2026. Open 2nd Grade to move them again.')
        ->and($late->openGroup())->toBe(['id' => $this->second->id, 'name' => '2nd Grade']);
});
