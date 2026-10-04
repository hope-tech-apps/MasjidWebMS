<?php

/*
|--------------------------------------------------------------------------
| The locks a move takes, read from the engine and tried from a second connection
|--------------------------------------------------------------------------
|
| App\Support\RosterMove says "locks first": the student's contact row, the roster row, every
| roster row about the student in the two classes, all by primary key, then a shared lock on the
| two class rows. Two things about InnoDB are relied on and SQLite shows neither:
|
|   (i)  a locking read does not start the transaction's read view, so the first ORDINARY read
|        after the locks sees what was committed before them;
|   (ii) an insert takes a shared lock on the parent row of each foreign key until its transaction
|        ends, so nothing about the child can be inserted while the move holds its locks.
|
| WHY THIS FILE IS NOT IN tests/Mysql. tests/Pest.php binds RefreshDatabase to that directory, so
| each test there is ONE transaction: a lock on a row the transaction itself inserted is not
| visible in performance_schema.data_locks (an "every lock is PRIMARY" assertion passes over an
| empty set), the read view already exists before the move starts, and a second connection sees
| none of the fixtures. So this file commits its fixtures, deletes them itself, and opens a second
| connection with a one-second lock wait.
|
| Same guards as tests/Mysql (tests/Pest.php): MySQL only, and only a database named *_test.
| NOT RUN where it was written: there is no MySQL server there.
*/

use App\Models\GroupMembership;
use App\Support\AcademicRecordsHeld;
use App\Support\RosterMove;
use App\Support\RosterMovePlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSchoolRosters;
use Tests\Support\PlantsRosterRecords;

uses(BuildsSchoolRosters::class, PlantsRosterRecords::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-04 15:00:00');

    // Committed, because a second connection has to see them.
    $this->school = $this->makeSchool();
    $this->admin = $this->makeAdmin($this->school);
    $this->first = $this->makeClass('1st Grade');
    $this->second = $this->makeClass('2nd Grade');
    $this->student = $this->enrol($this->first, 'Maryam');
    $this->parent = $this->guardian($this->student, 'Huda');
    // A place and an entry in the other class that have left, so the move locks rows in both.
    $this->back = $this->enrol($this->second, $this->student->contact);
    $this->backEntry = $this->guardian($this->back, $this->parent->contact);
    $this->back->markLeftByStaff($this->admin, '2026-09-10')->save();

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

    // In an order the RESTRICT keys allow: the records, the roster, then everything else.
    $school = $this->school->id;

    foreach (array_keys(AcademicRecordsHeld::KEYS) as $table) {
        DB::table($table)->where('masjid_id', $school)->delete();
    }

    foreach (['class_assignments', 'group_resources', 'group_memberships', 'groups', 'contacts', 'masjid_user'] as $table) {
        DB::table($table)->where('masjid_id', $school)->delete();
    }

    DB::table('masjids')->where('id', $school)->delete();
    DB::table('users')->where('id', $this->admin->id)->delete();
});

/** This connection's transaction's record locks, as `table|index|mode|data`. */
function rosterMoveRecordLocks(): array
{
    return collect(DB::select(
        "SELECT OBJECT_NAME AS t, INDEX_NAME AS i, LOCK_MODE AS m, LOCK_DATA AS d FROM performance_schema.data_locks
         WHERE LOCK_TYPE = 'RECORD'
           AND ENGINE_TRANSACTION_ID = (SELECT trx_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID())"
    ))->map(fn ($r): string => "{$r->t}|{$r->i}|{$r->m}|{$r->d}")->sort()->values()->all();
}

/** Did this statement, on the second connection, give up waiting for a lock (1205)? */
function rosterMoveIsBlocked(Closure $statement): bool
{
    try {
        $statement();
    } catch (QueryException $e) {
        return (int) ($e->errorInfo[1] ?? 0) === 1205;
    }

    return false;
}

it('rests on two foreign keys to contacts and on REPEATABLE READ', function () {
    $toContacts = collect(DB::select(
        "SELECT k.COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'group_memberships' AND k.REFERENCED_TABLE_NAME = 'contacts'"
    ))->pluck('c')->sort()->values()->all();

    // Without these the contact row would be no mutex at all.
    expect($toContacts)->toBe(['contact_id', 'guardian_of_contact_id'])
        ->and(DB::selectOne('SELECT @@transaction_isolation AS level')->level)->toBe('REPEATABLE-READ');
});

it('holds exactly the primary-key locks it names, and everything about the child waits for them', function () {
    $test = $this;
    $seen = (object) ['locks' => null, 'blocked' => []];

    // The school and plantRecord() are protected members of the test's traits. A closure made
    // here runs in the test's scope and can reach them; the class below cannot.
    $schoolId = $this->school->id;
    $plant = fn (string $table) => $this->plantRecord($table, $this->student);

    $mover = new class($test, $seen, $schoolId, $plant) extends RosterMove {
        public function __construct(private $test, private object $seen, private int $schoolId, private Closure $plant)
        {
        }

        protected function locksTaken(): void
        {
            $this->seen->locks = rosterMoveRecordLocks();

            $t = $this->test;
            $other = $t->other;
            $plant = $this->plant;
            $base = ['masjid_id' => $this->schoolId, 'provenance' => 'confirmed'];

            // (ii) A roster row naming the child, as the person or as a guardian entry's child.
            $this->seen->blocked['a place for the child'] = rosterMoveIsBlocked(fn () => $other->table('group_memberships')->insert($base + [
                'group_id' => $t->second->id, 'contact_id' => $t->student->contact_id, 'role' => 'leader',
            ]));
            $this->seen->blocked['a guardian entry naming the child'] = rosterMoveIsBlocked(fn () => $other->table('group_memberships')->insert($base + [
                'group_id' => $t->first->id, 'contact_id' => $t->admin_contact_id, 'role' => 'guardian',
                'guardian_of_contact_id' => $t->student->contact_id,
            ]));

            // (ii) A record on each of the eleven keys naming the roster row.
            foreach (array_keys(AcademicRecordsHeld::KEYS) as $table) {
                $this->seen->blocked[$table] = rosterMoveIsBlocked(function () use ($plant, $table) {
                    $default = DB::getDefaultConnection();
                    DB::setDefaultConnection('mysql_other');

                    try {
                        $plant($table);
                    } finally {
                        DB::setDefaultConnection($default);
                    }
                });
            }
        }
    };

    // An adult with no entry anywhere, for the guardian insert above.
    $this->admin_contact_id = $this->makePerson('Other', 'Adult')->id;

    $plan = $mover->move($this->first, $this->student, $this->second->id, '2026-10-04', [], $this->admin);

    expect($plan->path)->toBe(RosterMovePlan::RETURNED);

    $roster = [$this->student->id, $this->parent->id, $this->back->id, $this->backEntry->id];
    sort($roster);

    $expected = collect([
        "contacts|PRIMARY|X,REC_NOT_GAP|{$this->student->contact_id}",
        ...array_map(fn (int $id): string => "group_memberships|PRIMARY|X,REC_NOT_GAP|{$id}", $roster),
        "groups|PRIMARY|S,REC_NOT_GAP|{$this->first->id}",
        "groups|PRIMARY|S,REC_NOT_GAP|{$this->second->id}",
    ])->sort()->values()->all();

    // EQUAL, not "every one of them is": no other index, no gap or next-key lock, no supremum,
    // and not an empty set either.
    expect($seen->locks)->toBe($expected);

    foreach ($seen->blocked as $what => $blocked) {
        expect($blocked)->toBeTrue("{$what} was inserted while the move held its locks");
    }

    // Nothing the second connection tried is there.
    expect(AcademicRecordsHeld::any(AcademicRecordsHeld::counts($this->student->fresh())))->toBeFalse()
        ->and(GroupMembership::where('contact_id', $this->admin_contact_id)->count())->toBe(0);
});

it('sees, after its locks, what was committed before them', function () {
    // (i) The move's own first three statements, by hand, with a commit from another connection
    // between the first lock and the second. Had the first locking read fixed the read view, the
    // ordinary read at the end would not see the mark.
    DB::beginTransaction();
    DB::table('contacts')->where('id', $this->student->contact_id)->lockForUpdate()->first();

    $this->other->table('attendance_records')->insert([
        'masjid_id' => $this->school->id, 'group_id' => $this->first->id,
        'group_membership_id' => $this->student->id, 'session_date' => '2026-10-04', 'status' => 'present',
    ]);

    DB::table('group_memberships')->where('id', $this->student->id)->lockForUpdate()->first();

    $held = AcademicRecordsHeld::counts($this->student);
    DB::rollBack();

    expect($held['register marks'])->toBe(1);

    // And the move itself, run now, keeps that day for the old class.
    $plan = app(RosterMove::class)->move($this->first, $this->student, $this->second->id, '2026-10-04', [], $this->admin);

    expect($plan->oldClassKeepsMoveDay)->toBeTrue()
        ->and($plan->firstDay)->toBe('2026-10-05');
});

it('answers a row somebody else holds as a changed roster, after a short wait', function () {
    // The second connection holds the student's roster row, as a class-store redemption does.
    $this->other->beginTransaction();
    $this->other->table('group_memberships')->where('id', $this->student->id)->lockForUpdate()->first();

    $started = microtime(true);

    try {
        app(RosterMove::class)->move($this->first, $this->student, $this->second->id, '2026-10-04', [], $this->admin);
        $refusal = null;
    } catch (App\Exceptions\RosterMoveRefused $e) {
        $refusal = $e;
    } finally {
        $this->other->rollBack();
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->status())->toBe(409)
        ->and($refusal->getMessage())->toBe(App\Exceptions\RosterMoveRefused::CHANGED)
        // Three attempts of three seconds at most, never the engine's default fifty.
        ->and(microtime(true) - $started)->toBeLessThan(RosterMove::LOCK_WAIT_SECONDS * RosterMove::ATTEMPTS + 3)
        ->and($this->student->fresh()->left_on)->toBeNull()
        // The session's own lock wait is put back.
        ->and((int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS s')->s)->toBeGreaterThan(RosterMove::LOCK_WAIT_SECONDS);
});
