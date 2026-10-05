<?php

namespace App\Support;

use App\Exceptions\RosterClassMoveChanged;
use App\Exceptions\RosterMoveRefused;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Offering;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MOVING A WHOLE CLASS: every chosen student of one class into another.
 *
 * At a move-up, a split or a merge the office has a list in its head: these
 * children are in this class today and in that one from Sunday. This is the
 * single move (App\Support\RosterMove) applied to that list. It decides
 * nothing about a student itself: every rule, every refusal and every sentence
 * about one student is the single move's.
 *
 *   R9. A class is moved ONE STUDENT AT A TIME, and the answer names every
 *       student. One transaction per student; nothing is locked across two
 *       students; the request names the roster rows the office was shown.
 *
 * ---------------------------------------------------------------------------
 * WHY PER STUDENT, AND NOT ONE TRANSACTION FOR THE CLASS
 * ---------------------------------------------------------------------------
 *
 * A single move takes its old row first and the rest ascending, which is not
 * globally ascending when the target row is older; the retention purge holds
 * many roster rows at once; and inside an outer transaction the framework does
 * not retry a deadlock, it rethrows at once. One held row would fail the whole
 * class, and a register save or a redemption about ANY of the students would
 * wait for all of them.
 *
 * So at any instant a run holds locks for ONE student, released at that
 * student's commit. A mover touches a roster row only while it holds that
 * child's contact row, so two movers of any kind meet first on a contact row
 * and queue there. The state between two students is always "these students
 * fully moved, those not touched": a crash, a timeout or a closed browser
 * leaves that state and nothing else, and the next preview shows it as it is.
 *
 * ---------------------------------------------------------------------------
 * WHAT CAN CHANGE BETWEEN TWO STUDENTS
 * ---------------------------------------------------------------------------
 *
 * Nothing is held across them, so: the target can be switched off, ended or
 * deleted (each later student is refused with the single move's sentence and
 * reported); a teacher can save that day's register in the old class (a later
 * student's first day differs from what was shown, and they are reported, not
 * moved); somebody else can move one of the run's students (it queues on the
 * contact row, and whichever comes second gets the shipped refusal).
 *
 * Not `final`: the suite subclasses it for the clock (`clock()`).
 */
class RosterClassMove
{
    /**
     * Students in one request. A class with more is moved this many at a time;
     * the dialog says so. Unknown, needs investigation: how long one move takes
     * on MySQL. Nobody has timed one, and this number and the budget below are
     * to be confirmed or lowered from that measurement.
     */
    public const MAX_STUDENTS = 60;

    /**
     * The run starts no student once this many seconds have passed since it
     * began, and reports the rest as not reached. That is the whole promise:
     * it says when the run stops STARTING students, not how long the request
     * takes.
     */
    public const BUDGET_SECONDS = 40;

    /**
     * The cache store the run's lock is taken on, NAMED, so the mutex does not
     * depend on what CACHE_STORE is set to anywhere: the suite runs with
     * `array`, which would prove an in-process lock only. The `cache_locks`
     * table is the framework's own migration.
     */
    public const LOCK_STORE = 'database';

    /** Longer than the budget plus one student. If the process is killed the lock lapses by itself. */
    public const LOCK_SECONDS = 120;

    public const GRADE_KEEP = 'keep';

    public const GRADE_SET = 'set';

    public const GRADE_UP = 'up';

    public const GRADE_MODES = [self::GRADE_KEEP, self::GRADE_SET, self::GRADE_UP];

    /** What the student in hand is told when something that is not a refusal stopped the run. */
    public const FAULT = 'A fault stopped this move. Nothing about this student was changed.';

    public function __construct(private readonly RosterMove $mover)
    {
    }

    // ------------------------------------------------------------ the read

    /**
     * What moving the class would do, without doing it. No lock, no write.
     *
     * The checks that are the same for every student are made once, and a
     * refusal about the class is one sentence with no students. Then the
     * single preview for every CURRENT student row, in roster order. It
     * promises nothing: the run decides each student again, and each move
     * decides again under its locks.
     *
     * No `standing_before_id` here: nothing of a run exists yet, so each
     * student is previewed against the class entered as it stands, which is
     * what the run then decides.
     *
     * @param  array{mode?: ?string, label?: ?string}  $gradeChoice
     */
    public function preview(Group $from, ?Group $to, string $on, array $gradeChoice): RosterClassMovePlan
    {
        $today = self::todayFor($from);
        $plan = $this->blank($from, $to, $on, $today, $gradeChoice);

        try {
            RosterMove::refuseUnlessReady();
            RosterMove::refuseUnlessClassesAndDayAllow($from, $to, $on, $today, null);
        } catch (RosterMoveRefused $refused) {
            $plan->refusal = $refused->getMessage();

            return $plan;
        }

        $rows = $from->memberships()
            ->where('role', GroupMembership::ROLE_MEMBER)
            ->whereNull('left_on')
            ->orderBy('id')
            ->get();

        $plan->students = $this->decideEach($from, $to, $rows, $on, $gradeChoice, ['today' => $today]);

        return $this->describe($plan, $from, $to);
    }

    // ----------------------------------------------------------- the write

    /**
     * Move the students the request names, one at a time.
     *
     * REFUSED BEFORE ANYTHING IS WRITTEN (a `RosterMoveRefused`): the deploy
     * window (409), a check about the class as a whole (422), another run out
     * of this class (409), and a pre-flight difference (409, a
     * `RosterClassMoveChanged` naming the students that differ).
     *
     * ONCE THE RUN HAS STARTED it does not throw. A student the single move
     * refuses is recorded with that refusal and the run goes on: the refusal
     * rolled back that student only. Anything else is a fault, as it is for a
     * single move, and it stops the run: the students already moved stay moved
     * (each committed), the one in hand was rolled back, the rest were not
     * reached, the exception is reported, and the caller still gets an answer,
     * because an error page would hide which students were already moved.
     *
     * `$expectedBucksRule` is handed to every move as it came. The rule for the
     * class is null until a move can carry Manara Bucks, so there is nothing
     * here to compare it with yet.
     *
     * @param  array{mode: string, label?: ?string}  $gradeChoice
     * @param  list<array{membership_id: int, expected_path: string, expected_first_day: string, expected_joined_on: ?string, expected_consent: string, expected_grade: ?string}>  $students  in the order they are moved
     *
     * @throws RosterMoveRefused
     */
    public function run(Group $from, int $toGroupId, string $on, array $gradeChoice, ?string $expectedBucksRule, array $students, ?User $actor): RosterClassMovePlan
    {
        // 1. The deploy window: nothing is read further.
        RosterMove::refuseUnlessReady();

        // 2. The checks that are the same for every student, once. The
        //    school's day is read here and nowhere else in the run: every move
        //    is handed it, so a run that crosses the school's midnight does
        //    not judge its first students on one day and its last on another.
        $to = Group::query()->find($toGroupId);
        $today = self::todayFor($from);

        RosterMove::refuseUnlessClassesAndDayAllow($from, $to, $on, $today, null);

        // 3. One run at a time per class being left, not blocking. Without it
        //    two requests on one class interleave student by student and, with
        //    different targets, split the class between them. Two runs INTO
        //    one class from two classes need no lock: they name different
        //    students.
        $lock = Cache::store(self::LOCK_STORE)->lock(self::lockName($from), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw RosterMoveRefused::conflict("A move out of {$from->name} is still running (it may be yours). Wait two "
                .'minutes, then reload this roster.');
        }

        try {
            // 4. Read once for the whole run: the highest roster row id (the
            //    entries this run creates are above it, so a brother's or
            //    sister's new entry never caps a carry; see
            //    RosterMove::standingForAnotherChild), and the run's id.
            $standingBefore = (int) DB::table('group_memberships')->max('id');
            $run = (string) Str::ulid();
            $started = $this->clock();

            $plan = $this->blank($from, $to, $on, $today, $gradeChoice);
            $plan->run = $run;

            // The named rows, looked up through THIS class only: a row of
            // another class, or of another organisation, is simply not found.
            $shown = array_column($students, null, 'membership_id');
            $rows = $from->memberships()->whereIn('id', array_keys($shown))->get()->keyBy('id');

            // 5. Pre-flight, no lock, no write: every named row decided again,
            //    against what the dialog showed for it.
            $plan->students = $this->preflight($plan, $from, $to, $on, $gradeChoice, $students, $rows, [
                'today' => $today,
                'standing_before_id' => $standingBefore,
            ]);

            $stopped = false;

            // 6. Each named student, in the order of the request.
            foreach ($plan->students as $i => $student) {
                // 7. The time budget, read before a student is started.
                if ($stopped || $this->clock() - $started >= self::BUDGET_SECONDS) {
                    $plan->students[$i]['outcome'] = RosterClassMovePlan::NOT_REACHED;

                    continue;
                }

                $echo = $shown[$student['membership_id']];

                try {
                    // The single verb's call, with what the office was shown
                    // for this student, and four options no request can
                    // supply. ONE attempt: a busy student is reported and
                    // offered again, so the single move's retries would only
                    // spend the budget.
                    $moved = $this->mover->move($from, $rows->get($student['membership_id']), $toGroupId, $on, [
                        'expected_path' => $echo['expected_path'],
                        'expected_first_day' => $echo['expected_first_day'],
                        'expected_joined_on' => $echo['expected_joined_on'],
                        'expected_consent' => $echo['expected_consent'],
                        'expected_bucks_rule' => $expectedBucksRule,
                        'run' => $run,
                        'standing_before_id' => $standingBefore,
                        'today' => $today,
                        'attempts' => 1,
                    ] + $this->gradeOptions($gradeChoice, $student['grade_after']), $actor);

                    $plan->students[$i]['outcome'] = RosterClassMovePlan::MOVED;
                    $plan->students[$i]['moved'] = $moved;
                } catch (RosterMoveRefused $refused) {
                    // A held row, a deadlock, or something saved about this
                    // student between the check and the move: worth offering
                    // again. Every other refusal is a decision about the
                    // rosters.
                    $plan->students[$i]['outcome'] = RosterClassMovePlan::NOT_MOVED;
                    $plan->students[$i]['reason'] = $refused->getMessage();
                    $plan->students[$i]['open_group'] = $refused->openGroup();
                    $plan->students[$i]['retry'] = in_array(
                        $refused->getMessage(),
                        [RosterMoveRefused::CHANGED, RosterMoveRefused::LOOK_AGAIN],
                        true,
                    );
                } catch (\Throwable $fault) {
                    report($fault);

                    $plan->students[$i]['outcome'] = RosterClassMovePlan::NOT_MOVED;
                    $plan->students[$i]['reason'] = self::FAULT;
                    $plan->students[$i]['open_group'] = null;
                    $plan->students[$i]['fault'] = true;
                    $plan->stoppedByFault = true;
                    $stopped = true;
                }
            }
        } finally {
            // 8. Whatever happened.
            $lock->release();
        }

        $plan->ran = true;
        $plan->siblingsLeftBehind = $this->siblingsLeftBehind($plan);
        $plan->oldClassIsEmpty = ! $from->memberships()
            ->where('role', GroupMembership::ROLE_MEMBER)
            ->whereNull('left_on')
            ->exists();

        return $this->describe($plan, $from, $to);
    }

    /** The name of the run's lock: one per class being left. */
    public static function lockName(Group $from): string
    {
        return 'roster-class-move:'.$from->getKey();
    }

    /**
     * Do these two labels name the same grade to the letter? Blank and absent
     * are the same: the tap's body is form-encoded, where "no grade" is an
     * empty string.
     */
    public static function sameGrade(?string $one, ?string $other): bool
    {
        return trim((string) $one) === trim((string) $other);
    }

    // ----------------------------------------------------------- the seams

    /**
     * Seconds, for the budget. The application's clock, so the suite can move
     * it between two students.
     */
    protected function clock(): float
    {
        return (float) now()->format('U.u');
    }

    // ------------------------------------------------------------- internals

    /**
     * THE PRE-FLIGHT. Every named row must be one the class preview would list
     * now, allowed to move, with the same path, first day, joining day on a
     * return, consent result and grade the request echoes. One difference, for
     * one student, refuses the request before anything is written, so in every
     * ordinary case the office gets "what I confirmed is what happened, or
     * nothing".
     *
     * A row that is not on this class's roster at all (removed since, or never
     * there) is a difference too, and is named by its id and nothing else.
     *
     * @param  array<string, mixed>  $gradeChoice
     * @param  list<array<string, mixed>>  $students
     * @param  Collection<int, GroupMembership>  $rows  the named rows this class holds, by id
     * @param  array{today: string, standing_before_id: int}  $options
     * @return list<array<string, mixed>> the rows, in the order of the request
     *
     * @throws RosterClassMoveChanged
     */
    private function preflight(RosterClassMovePlan $plan, Group $from, Group $to, string $on, array $gradeChoice, array $students, Collection $rows, array $options): array
    {
        $decided = collect($this->decideEach($from, $to, $rows->values(), $on, $gradeChoice, $options))->keyBy('membership_id');

        $inOrder = [];
        $differ = [];

        foreach ($students as $shown) {
            $id = (int) $shown['membership_id'];
            $row = $decided->get($id) ?? [
                'membership_id' => $id, 'name' => null, 'grade_label' => null, 'grade_after' => null, 'grade_note' => null,
                'came_from_target' => false, 'plan' => null, 'open_group' => null, 'held_back_for_consent' => false,
                'refusal' => "This student is no longer on {$from->name}'s roster.",
            ];

            $inOrder[] = $row;

            if (self::differs($row, $shown)) {
                $differ[] = $plan->listed($row);
            }
        }

        if ($differ !== []) {
            throw new RosterClassMoveChanged($differ);
        }

        return $inOrder;
    }

    /**
     * Is what would happen to this student now something other than what the
     * dialog showed? The joining day is compared on a return only: on the
     * other path it is the first day, which is compared already.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $shown
     */
    private static function differs(array $row, array $shown): bool
    {
        /** @var RosterMovePlan|null $plan */
        $plan = $row['plan'];

        if ($plan === null) {
            return true;
        }

        return $shown['expected_path'] !== $plan->path
            || $shown['expected_first_day'] !== $plan->firstDay
            || ($plan->path === RosterMovePlan::RETURNED && ($shown['expected_joined_on'] ?? null) !== $plan->joinedOn)
            || $shown['expected_consent'] !== $plan->consentFingerprint
            || ! self::sameGrade($shown['expected_grade'] ?? null, $row['grade_after']);
    }

    /**
     * The single preview for each of these roster rows, as rows of the class
     * plan. A student the single move would refuse has no plan: the refusal is
     * the single move's, word for word, with the class to open.
     *
     * @param  Collection<int, GroupMembership>  $rows
     * @param  array<string, mixed>  $gradeChoice
     * @param  array{today: string, standing_before_id?: int}  $options
     * @return list<array<string, mixed>>
     */
    private function decideEach(Group $from, Group $to, Collection $rows, string $on, array $gradeChoice, array $options): array
    {
        $names = Contact::query()
            ->whereIn('id', $rows->pluck('contact_id')->unique()->values())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Contact $c): array => [(int) $c->getKey() => trim("{$c->first_name} {$c->last_name}")])
            ->all();

        $students = [];

        foreach ($rows as $row) {
            $plan = null;
            $refused = null;

            try {
                $plan = $this->mover->preview($from, $row, $to, $on, $options);
            } catch (RosterMoveRefused $e) {
                $refused = $e;
            }

            $students[] = [
                'membership_id' => (int) $row->getKey(),
                // The single move's own fallbacks for a contact that was deleted, or has no name.
                'name' => $plan?->student ?? (($names[(int) $row->contact_id] ?? 'This student') ?: 'This person'),
                'grade_label' => $row->grade_label,
                'grade_after' => null,
                'grade_note' => null,
                'came_from_target' => $row->moved_from_group_id !== null
                    && (int) $row->moved_from_group_id === (int) $to->getKey(),
                'plan' => $plan,
                'refusal' => $refused?->getMessage(),
                'open_group' => $refused?->openGroup(),
                // Rule R10's refusal is the one that names a roster row to
                // bring into view: the entry whose consent would come back.
                'held_back_for_consent' => isset($refused?->openGroup()['membership_id']),
            ];
        }

        return $this->withGrades($students, $rows, $gradeChoice);
    }

    /**
     * THE GRADE EACH STUDENT WILL HOLD (`grade_after`), per path and mode. It
     * is what the row shows wherever it differs from the grade held now, what
     * the tap echoes, and for `set` and `up` what each move is given.
     *
     *   - `keep` (and no choice yet): on a NEW place, the grade the student
     *     holds in the class being left, because a new place copies the label.
     *     On a RETURN, the grade recorded on the place being re-opened: only a
     *     new place copies the label, and the run sends no grade. That is the
     *     right result for putting a class back (moved "up one" and put back
     *     with "keep", every student has their original grade again), and it
     *     differs from the single dialog, which always sends the grade typed.
     *   - `set`: one label for everyone.
     *   - `up`: one step along GradeLevel::LEVELS from the grade the student
     *     holds in the class being left, on both paths. A blank label, a label
     *     it cannot read and the last level are kept, and the row says so.
     *
     * @param  list<array<string, mixed>>  $students
     * @param  Collection<int, GroupMembership>  $rows
     * @param  array<string, mixed>  $gradeChoice
     * @return list<array<string, mixed>>
     */
    private function withGrades(array $students, Collection $rows, array $gradeChoice): array
    {
        $mode = $gradeChoice['mode'] ?? null;

        // The places being re-opened, for `keep`: one read for the class.
        $reopening = collect($students)
            ->filter(fn (array $s): bool => $s['plan']?->path === RosterMovePlan::RETURNED)
            ->map(fn (array $s): int => (int) $s['plan']->membershipId);

        $gradesThere = $reopening->isEmpty()
            ? collect()
            : GroupMembership::query()->whereIn('id', $reopening->values())->pluck('grade_label', 'id');

        foreach ($students as $i => $student) {
            $held = $student['grade_label'];

            if ($mode === self::GRADE_SET) {
                $students[$i]['grade_after'] = self::cleanGrade($gradeChoice['label'] ?? null);
            } elseif ($mode === self::GRADE_UP) {
                $next = GradeLevel::next($held);

                $students[$i]['grade_after'] = $next ?? $held;
                $students[$i]['grade_note'] = match (true) {
                    $next !== null => null,
                    self::cleanGrade($held) === null => 'No grade is recorded, so none is given.',
                    default => "{$held} cannot be moved up one, so it is kept.",
                };
            } elseif ($student['plan']?->path === RosterMovePlan::RETURNED) {
                $students[$i]['grade_after'] = $gradesThere->get((int) $student['plan']->membershipId);
            } else {
                $students[$i]['grade_after'] = $held;
            }
        }

        return $students;
    }

    /**
     * What one move is told about the grade. `keep` sends nothing: a new place
     * copies the label and a re-opened place keeps its own.
     *
     * @param  array<string, mixed>  $gradeChoice
     * @return array{grade_given?: bool, grade_label?: ?string}
     */
    private function gradeOptions(array $gradeChoice, ?string $gradeAfter): array
    {
        return in_array($gradeChoice['mode'] ?? null, [self::GRADE_SET, self::GRADE_UP], true)
            ? ['grade_given' => true, 'grade_label' => $gradeAfter]
            : [];
    }

    /**
     * A class plan with what is known before any student is looked at.
     *
     * @param  array<string, mixed>  $gradeChoice
     */
    private function blank(Group $from, ?Group $to, string $on, string $today, array $gradeChoice): RosterClassMovePlan
    {
        $plan = new RosterClassMovePlan();
        $plan->fromId = (int) $from->getKey();
        $plan->fromName = (string) $from->name;
        $plan->movedOn = $on;
        $plan->today = $today;
        $plan->gradeMode = in_array($gradeChoice['mode'] ?? null, self::GRADE_MODES, true) ? $gradeChoice['mode'] : null;
        $plan->gradeLabel = self::cleanGrade($gradeChoice['label'] ?? null);
        $plan->maxStudents = self::MAX_STUDENTS;

        // Another organisation's class is the same as a class that does not
        // exist: it is never named back.
        if ($to !== null && ! $to->trashed() && (int) $to->masjid_id === (int) $from->masjid_id) {
            $plan->toId = (int) $to->getKey();
            $plan->toName = (string) $to->name;
        }

        return $plan;
    }

    /**
     * What is true of the two classes, for the class's own sentences: who on
     * this roster is not part of the move, whether the class entered has a
     * teacher, and whether a program still points at the class being left.
     * None of it is carried by a move; each is something to check.
     */
    private function describe(RosterClassMovePlan $plan, Group $from, Group $to): RosterClassMovePlan
    {
        $others = $from->memberships()
            ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
            ->get(['id', 'role', 'left_on']);

        $plan->leftOrMoved = $others
            ->filter(fn (GroupMembership $m): bool => $m->role === GroupMembership::ROLE_MEMBER && $m->left_on !== null)
            ->count();
        $plan->leaders = $others
            ->filter(fn (GroupMembership $m): bool => $m->role === GroupMembership::ROLE_LEADER && $m->left_on === null)
            ->count();

        $plan->teachersInNewClass = $to->staff()->count();

        $programs = Offering::query()->where('group_id', $from->getKey())->get(['id', 'is_active']);
        $plan->programsEnrolling = $programs->filter(fn (Offering $o): bool => (bool) $o->is_active)->count();
        $plan->programsSwitchedOff = $programs->count() - $plan->programsEnrolling;

        // One value for the class: it is about the two classes and the clock,
        // never about a child, so every student's plan carries the same one.
        // Null until a move can carry Manara Bucks.
        foreach ($plan->students as $student) {
            if ($student['plan'] !== null) {
                $plan->bucksRule = $student['plan']->bucksRule;

                break;
            }
        }

        return $plan;
    }

    /**
     * Students who were not moved and share a guardian with one who was. The
     * next run reads a new `standing_before_id`, above the entry this run made
     * for their brother or sister, so the next check can show less consent
     * carried for them than this one did; the answer says so in a line.
     */
    private function siblingsLeftBehind(RosterClassMovePlan $plan): int
    {
        $adultsMoved = [];

        foreach ($plan->students as $student) {
            if (isset($student['moved'])) {
                $adultsMoved = array_merge($adultsMoved, $student['moved']->guardianContactIds);
            }
        }

        return count(array_filter(
            $plan->students,
            fn (array $student): bool => ! isset($student['moved'])
                && array_intersect($student['plan']->guardianContactIds, $adultsMoved) !== [],
        ));
    }

    /** A grade as it is stored: trimmed, and null when there is none. */
    private static function cleanGrade(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : $label;
    }

    /** Today on the school's clock, never the server's. */
    private static function todayFor(Group $group): string
    {
        return SchoolCalendar::for((int) $group->masjid_id)->today();
    }
}
