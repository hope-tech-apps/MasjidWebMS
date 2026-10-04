<?php

namespace App\Support;

use App\Exceptions\RosterMoveRefused;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessageSchedule;
use App\Models\Masjid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MOVING A STUDENT TO ANOTHER CLASS.
 *
 * ---------------------------------------------------------------------------
 * ONE PATH: LEFT HERE, STARTED THERE
 * ---------------------------------------------------------------------------
 *
 * A roster row NEVER changes class. The old place gets a leaving day and stays
 * where it is, with everything recorded on it; a new place opens in the new
 * class (`left_and_started`), or the place the student held there before opens
 * again (`returned`). There is no re-point, even for a row that holds nothing,
 * and the reason is every other writer in the application: each office roster
 * action and each record writer loads a roster row through its class and then
 * writes by primary key with no lock. A row that changed class would be written
 * to by requests that loaded it under the old one.
 *
 * Four rules, in the words the rules file uses (.claude/rules/groups.md,
 * "Moving a student to another class"):
 *
 *   R1. Records never change class.
 *   R2. A move never widens anybody's access. A closed guardian entry in the
 *       class being LEFT never travels. A confirmed entry in the class being
 *       ENTERED is re-opened only when the same adult holds a confirmed,
 *       current entry in the class being left; otherwise the move is refused.
 *       An unconfirmed entry may be re-opened and stays unconfirmed.
 *   R3. A move destroys nothing: no row is deleted, no consent column cleared.
 *   R4. Locks first: every lock is taken by primary key before the first
 *       ordinary read, and the decision is made from the locked rows.
 *
 * ---------------------------------------------------------------------------
 * WORDS
 * ---------------------------------------------------------------------------
 *
 * For the student S moving from class F to class T:
 *   - travelling entries: guardian entries in F naming S with NO leaving date.
 *   - vouching entries: travelling entries that are also confirmed.
 *   - closed entries: guardian entries in F naming S with a leaving date. They
 *     never travel and never count as "has an entry".
 *
 * Not `final`: the suite subclasses it at the two seams below (`locksTaken`,
 * `written`) to read the engine's locks and to prove the check after the
 * writes fails closed.
 */
class RosterMove
{
    /** Why a confirmed entry has nobody vouching for it. */
    public const NO_ENTRY = 'no_entry';

    public const ONLY_UNCONFIRMED = 'only_unconfirmed';

    /**
     * Seconds a move waits for a row somebody else holds, per attempt, before
     * it answers "this roster changed" (MySQL's default is 50, longer than the
     * web server waits). With ATTEMPTS that is the worst case: nine seconds.
     */
    public const LOCK_WAIT_SECONDS = 3;

    public const ATTEMPTS = 3;

    // ------------------------------------------------------------ the read

    /**
     * What a move would do, without doing it. No lock, no write. The same
     * decision the move makes under its locks.
     *
     * @throws RosterMoveRefused
     */
    public function preview(Group $from, GroupMembership $row, ?Group $to, string $on): RosterMovePlan
    {
        $rows = $this->rowsAbout([$from->id, $to?->id], (int) $row->contact_id)->get();

        return $this->decide($from, $to, $rows, $row, $on, $this->todayFor($from));
    }

    // ----------------------------------------------------------- the write

    /**
     * Move the student.
     *
     * @param  array{grade_given?: bool, grade_label?: ?string, expected_path?: ?string, expected_first_day?: ?string, expected_joined_on?: ?string}  $options
     *
     * @throws RosterMoveRefused
     */
    public function move(Group $from, GroupMembership $seen, int $toGroupId, string $on, array $options, ?User $actor): RosterMovePlan
    {
        $rowId = (int) $seen->getKey();
        $contactId = (int) $seen->contact_id;
        $fromId = (int) $from->getKey();
        $today = $this->todayFor($from);

        // The cheap refusals, answered before any lock is asked for. Every one
        // of them is decided again below, from the locked rows.
        $this->preview($from, $seen, Group::query()->find($toGroupId), $on);

        try {
            $plan = $this->withShortLockWait(fn (): RosterMovePlan => DB::transaction(
                function () use ($rowId, $contactId, $fromId, $toGroupId, $on, $today, $options, $actor): RosterMovePlan {
                    // 1. The student's contact row: the mutex for everything
                    //    about this child. On MySQL an insert of any roster row
                    //    naming this contact, as the person or as the child of
                    //    a guardian entry, needs a shared lock on it.
                    //    DELETED OR NOT: this is a lock, not a read of the
                    //    person. The Member Directory's delete is a soft one
                    //    that leaves the roster row, and the preview does not
                    //    load the contact at all, so a lock that skipped a
                    //    deleted contact would answer "this roster changed"
                    //    to a move the preview had just allowed, every time.
                    $contact = Contact::withTrashed()->whereKey($contactId)->lockForUpdate()->first();

                    // 2. The student's roster row. From here every insert on
                    //    the eleven keys that names it waits.
                    $row = GroupMembership::query()->whereKey($rowId)->lockForUpdate()->first();

                    if ($contact === null || $row === null
                        || (int) $row->group_id !== $fromId
                        || (int) $row->contact_id !== $contactId
                        || $row->left_on !== null) {
                        throw RosterMoveRefused::changed();
                    }

                    // 3. NOW the first ordinary read, which is where MySQL
                    //    fixes what this transaction sees: the ids of every
                    //    roster row about the student in the two classes. Then
                    //    exactly those, by primary key, ascending.
                    $ids = $this->rowsAbout([$fromId, $toGroupId], $contactId)->pluck('id');
                    $rows = GroupMembership::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

                    // 4. The two class rows, shared, so neither can be switched
                    //    off, ended or deleted under the move. A shared lock on
                    //    the class after the roster row is the order the class
                    //    store's inserts already take.
                    $classes = Group::withTrashed()->whereIn('id', [$fromId, $toGroupId])->orderBy('id')
                        ->sharedLock()->get()->keyBy('id');

                    $this->locksTaken();

                    $from = $classes->get($fromId);
                    $to = $classes->get($toGroupId);
                    $row = $rows->firstWhere('id', $rowId);

                    if ($from === null || $from->trashed() || $row === null) {
                        throw RosterMoveRefused::changed();
                    }

                    $plan = $this->decide($from, $to, $rows, $row, $on, $today);

                    $this->refuseWhenNotWhatWasShown($plan, $options);

                    $vouchers = $this->vouchers($rows, $fromId, $contactId);

                    $this->write($plan, $from, $to, $rows, $row, $options, $actor);

                    $this->written($plan);

                    $this->refuseUnlessTheRostersAreSound($plan, $contactId, $vouchers);

                    return $plan;
                },
                self::ATTEMPTS,
            ));
        } catch (RosterMoveRefused $refused) {
            throw $refused;
        } catch (UniqueConstraintViolationException|DeadlockException) {
            throw RosterMoveRefused::changed();
        } catch (QueryException $e) {
            // 1213 deadlock, 1205 lock wait timeout: what is left after the
            // attempts above. Anything else is a real fault and stays one.
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                throw RosterMoveRefused::changed();
            }

            throw $e;
        }

        // AFTER the commit, so a transaction that was retried logs once. At
        // WARNING because production runs LOG_LEVEL=warning: an info line is
        // dropped there, and this line is the only history of a move (the four
        // columns keep the latest move of a row; guardian entries carry none).
        // Ids only, never a name.
        Log::warning('roster.move', [
            'membership' => $plan->oldMembershipId,
            'new_membership' => $plan->membershipId,
            'contact' => $contactId,
            'from' => $plan->fromId,
            'to' => $plan->toId,
            'path' => $plan->path,
            'moved_on' => $plan->movedOn,
            'first_day' => $plan->firstDay,
            'by' => $actor?->getKey(),
            'guardian_entries_carried' => $plan->guardianEntriesCarried,
            'guardian_entries_reopened' => $plan->guardianEntriesReopened,
            'guardian_entries_reused' => $plan->guardianEntriesReused,
            'previous_joined_at' => $plan->previousJoinedOn,
        ]);

        return $plan;
    }

    // ----------------------------------------------------- the guardian rule

    /**
     * WHICH CONFIRMED ENTRIES HAVE NOBODY VOUCHING FOR THEM. One rule, read
     * from both sides:
     *
     *   - the move: the entries to open are the target class's; the student is
     *     in the class being left, and is current there;
     *   - "Put back" on a row that was moved: the entries to open are this
     *     class's; the student is wherever they are now.
     *
     * A confirmed entry is vouched for when the same adult holds a CONFIRMED
     * entry for the same child where the student is (and a current one, when
     * the student is current there). An unconfirmed entry needs no voucher: it
     * opens nothing for its holder, open or closed.
     *
     * A reason per entry, because the two are different situations with
     * different remedies: `no_entry` (the adult is not beside the student at
     * all: removed, or never there) and `only_unconfirmed` (the adult is there
     * as a registration form's claim nobody has confirmed).
     *
     * @param  Collection<int, GroupMembership>  $entriesToOpen
     * @param  Collection<int, GroupMembership>  $entriesWhereTheStudentIs
     * @return array<int, string> entry id => reason
     */
    public static function notVouched(Collection $entriesToOpen, Collection $entriesWhereTheStudentIs, bool $studentIsCurrentThere): array
    {
        $unvouched = [];

        foreach ($entriesToOpen as $entry) {
            if (! $entry->isGuardian() || ! $entry->isConfirmed()) {
                continue;
            }

            $theirs = $entriesWhereTheStudentIs->filter(
                fn (GroupMembership $e): bool => $e->isGuardian()
                    && (int) $e->contact_id === (int) $entry->contact_id
                    && (int) $e->guardian_of_contact_id === (int) $entry->guardian_of_contact_id
                    && (! $studentIsCurrentThere || $e->left_on === null),
            );

            if ($theirs->contains(fn (GroupMembership $e): bool => $e->isConfirmed())) {
                continue;
            }

            $unvouched[(int) $entry->getKey()] = $theirs->isEmpty() ? self::NO_ENTRY : self::ONLY_UNCONFIRMED;
        }

        return $unvouched;
    }

    /**
     * For the roster list: what "Put back" would mean on each student row that
     * was moved out of this class. Keyed by roster row id.
     *
     * `student_there` is about the class the row was moved TO (it decides the
     * badge and the wording). `guardians_not_vouched` is computed against where
     * the student is NOW, anywhere in the organisation: after two moves the
     * class they were moved to is not where they are, and an adult removed
     * where they are must not come back through this class. Only when the
     * student is current nowhere does it fall back to the class they were moved
     * to; with no row there either there is nothing to compare against, the
     * list is empty and the screen names every guardian instead.
     *
     * Two queries for the whole list, and only when a row carries "moved to".
     *
     * @param  Collection<int, GroupMembership>  $roster  this class's rows, with `contact` loaded
     * @return array<int, array{student_there: string, open_group: ?array{id:int,name:string}, guardians_not_vouched: list<array{membership_id:int, reason:string, sentence:string}>}>
     */
    public static function movedToStates(Group $group, Collection $roster): array
    {
        $moved = $roster->filter(
            fn (GroupMembership $m): bool => in_array($m->role, GroupMembership::PARTICIPANT_ROLES, true)
                && $m->moved_to_group_id !== null,
        );

        if ($moved->isEmpty()) {
            return [];
        }

        $students = $moved->pluck('contact_id')->unique()->values();

        $elsewhere = GroupMembership::query()
            ->where('group_id', '!=', $group->getKey())
            ->where(fn ($q) => $q->whereIn('contact_id', $students)->orWhereIn('guardian_of_contact_id', $students))
            ->get();

        $live = Group::query()
            ->whereIn('id', $elsewhere->pluck('group_id')->merge($moved->pluck('moved_to_group_id'))->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        // A deleted class is not somewhere a student is: its rows still exist
        // (a class is soft-deleted), and they are left out here.
        $about = $elsewhere->filter(fn (GroupMembership $m): bool => $live->has((int) $m->group_id));

        $states = [];

        foreach ($moved as $row) {
            $student = (int) $row->contact_id;
            $movedTo = (int) $row->moved_to_group_id;

            $places = $about->filter(
                fn (GroupMembership $m): bool => (int) $m->contact_id === $student
                    && in_array($m->role, GroupMembership::PARTICIPANT_ROLES, true),
            );
            $entries = $about->filter(
                fn (GroupMembership $m): bool => $m->isGuardian() && (int) $m->guardian_of_contact_id === $student,
            );

            $there = $places->where('group_id', $movedTo);
            $studentThere = match (true) {
                $there->isEmpty() => 'none',
                $there->contains(fn (GroupMembership $m): bool => $m->left_on === null) => 'current',
                default => 'left',
            };

            $currentIn = $places->filter(fn (GroupMembership $m): bool => $m->left_on === null)
                ->pluck('group_id')->map(fn ($id): int => (int) $id)->unique()->values();

            $entriesHere = $roster->filter(
                fn (GroupMembership $m): bool => $m->isGuardian() && (int) $m->guardian_of_contact_id === $student,
            );

            if ($currentIn->isNotEmpty()) {
                $compareWith = $currentIn;
                $unvouched = self::notVouched(
                    $entriesHere,
                    $entries->filter(fn (GroupMembership $m): bool => $currentIn->contains((int) $m->group_id)),
                    true,
                );
            } elseif ($studentThere === 'left') {
                $compareWith = collect([$movedTo]);
                $unvouched = self::notVouched($entriesHere, $entries->where('group_id', $movedTo), false);
            } else {
                $compareWith = collect();
                $unvouched = [];
            }

            $where = $compareWith->map(fn (int $id): string => (string) $live->get($id)?->name)->filter()->implode(' or ');
            $name = self::nameOf($row->contact);
            $openId = $compareWith->first() ?? ($live->has($movedTo) ? $movedTo : null);

            $states[(int) $row->getKey()] = [
                'student_there' => $studentThere,
                'open_group' => $openId === null ? null : ['id' => (int) $openId, 'name' => (string) $live->get($openId)?->name],
                'guardians_not_vouched' => collect($unvouched)->map(function (string $reason, int $id) use ($entriesHere, $name, $where): array {
                    $guardian = self::nameOf($entriesHere->firstWhere('id', $id)?->contact);

                    return [
                        'membership_id' => $id,
                        'reason' => $reason,
                        'sentence' => "Putting {$name} back would also give {$guardian} access to this class again. "
                            .($reason === self::ONLY_UNCONFIRMED
                                ? "{$guardian} is only listed from a registration form in {$where}, not confirmed there."
                                : "{$guardian} is not a confirmed guardian of {$name} in {$where}."),
                    ];
                })->values()->all(),
            ];
        }

        return $states;
    }

    // ----------------------------------------------------------- the seams

    /**
     * Every lock is held and nothing has been decided or written. Empty here;
     * the MySQL suite overrides it to read `performance_schema.data_locks`.
     */
    protected function locksTaken(): void
    {
    }

    /**
     * Every write is made and the check has not run yet. Empty here; the suite
     * overrides it to plant the row the check exists to catch.
     */
    protected function written(RosterMovePlan $plan): void
    {
    }

    // -------------------------------------------------------- the decision

    /**
     * THE DECISION: which path, the numbers the office is told, or a refusal.
     * No writes. The preview runs it on rows read without locks; the move runs
     * it again on the rows it locked.
     *
     * @param  Collection<int, GroupMembership>  $rows  every roster row about the student in the two classes
     *
     * @throws RosterMoveRefused
     */
    protected function decide(Group $from, ?Group $to, Collection $rows, GroupMembership $row, string $on, string $today): RosterMovePlan
    {
        $studentId = (int) $row->contact_id;
        $names = $this->namesFor($rows->pluck('contact_id')->push($studentId));
        $name = $names[$studentId] ?? 'This student';

        if ($row->role !== GroupMembership::ROLE_MEMBER) {
            throw new RosterMoveRefused('Only a student moves between classes. A guardian entry moves with the child it names.');
        }

        if ($row->left_on !== null) {
            $movedTo = $row->moved_to_group_id === null ? null : Group::withTrashed()->find($row->moved_to_group_id);

            throw $movedTo !== null && ! $movedTo->trashed()
                ? new RosterMoveRefused(
                    "{$name} was moved to {$movedTo->name}"
                        .($row->moved_on !== null ? ' on '.RosterMovePlan::day($row->moved_on->toDateString()) : '')
                        .". Open {$movedTo->name} to move them again.",
                    openGroup: ['id' => (int) $movedTo->getKey(), 'name' => (string) $movedTo->name],
                )
                : new RosterMoveRefused("{$name} has already left this class. To place them in another class, put them "
                    .'back on this roster first, then move them.');
        }

        if ($to === null || $to->trashed() || (int) $to->masjid_id !== (int) $from->masjid_id) {
            throw new RosterMoveRefused("Choose one of this school's classes.");
        }

        if ((int) $to->getKey() === (int) $from->getKey()) {
            throw new RosterMoveRefused("{$name} is already in this class.");
        }

        if (! $from->teachesStudents() || ! $to->teachesStudents()) {
            throw new RosterMoveRefused('Students can only be moved between classes.');
        }

        if (! $to->is_active || ($to->ends_on !== null && $to->ends_on->toDateString() < $on)) {
            throw new RosterMoveRefused("{$to->name} is not running: it is switched off or has ended. Choose a class that is running.");
        }

        if ($on > $today) {
            throw new RosterMoveRefused('The first day in the new class cannot be in the future.');
        }

        // A registration stamps `joined_at` on the server's UTC clock, so a
        // child confirmed in the evening is stored as joining tomorrow. Compared
        // with the EARLIER of that day and the school's today, there is always
        // a day that works. The stored day is not rewritten by this.
        $joined = $row->joined_at?->toDateString();

        if ($joined !== null && $on < min($joined, $today)) {
            throw new RosterMoveRefused("{$name} joined this class on ".RosterMovePlan::day($joined).'. Choose that day or a later one.');
        }

        $open = ['id' => (int) $to->getKey(), 'name' => (string) $to->name];

        $isPlace = fn (GroupMembership $m): bool => (int) $m->contact_id === $studentId
            && in_array($m->role, GroupMembership::PARTICIPANT_ROLES, true);
        $isEntry = fn (GroupMembership $m): bool => $m->isGuardian() && (int) $m->guardian_of_contact_id === $studentId;

        $inFrom = $rows->filter(fn (GroupMembership $m): bool => (int) $m->group_id === (int) $from->getKey());
        $inTo = $rows->filter(fn (GroupMembership $m): bool => (int) $m->group_id === (int) $to->getKey());

        if ($inFrom->filter($isPlace)->count() > 1) {
            throw RosterMoveRefused::conflict("{$name} appears twice on this roster. Remove the extra entry first; nothing was moved.");
        }

        $placesThere = $inTo->filter($isPlace)->values();

        if ($placesThere->count() > 1) {
            throw RosterMoveRefused::conflict(
                "{$name} appears twice on {$to->name}'s roster. Open {$to->name} and remove the extra entry, then move them again.",
                $open,
            );
        }

        /** @var GroupMembership|null $back */
        $back = $placesThere->first();

        if ($back !== null && $back->role === GroupMembership::ROLE_LEADER) {
            throw RosterMoveRefused::conflict(
                "{$name} is listed in {$to->name} as a leader. Remove that entry there first; nothing was moved.",
                $open,
            );
        }

        if ($back !== null && $back->left_on === null) {
            throw RosterMoveRefused::conflict("{$name} is already in {$to->name}. Nothing was moved. If they should no "
                .'longer be in this class, record them as having left it.');
        }

        $fromEntries = $inFrom->filter($isEntry)->values();
        $toEntries = $inTo->filter($isEntry)->values();

        // The guardian rule (R2). Every unvouched guardian is named in ONE
        // refusal, and each remedy is a thing the office can do from here:
        // confirm or add on this roster, or open the other class and remove.
        $unvouched = self::notVouched($toEntries, $fromEntries, true);

        if ($unvouched !== []) {
            $sentences = [];

            foreach ($unvouched as $entryId => $reason) {
                $guardian = $names[(int) $toEntries->firstWhere('id', $entryId)->contact_id] ?? 'A guardian';

                $sentences[] = $reason === self::ONLY_UNCONFIRMED
                    ? "{$guardian} is a confirmed guardian of {$name} in {$to->name}, but on this roster is only listed "
                        .'from a registration form. Check who filled in that form and confirm the entry on this roster, '
                        ."or open {$to->name} and remove {$guardian}'s entry there."
                    : "{$guardian} is a confirmed guardian of {$name} in {$to->name} but not a confirmed guardian here. "
                        ."If {$guardian} should still be a guardian, add them on this roster first. If not, open "
                        ."{$to->name} and remove their entry there.";
            }

            throw RosterMoveRefused::conflict(
                implode("\n", [...$sentences, "Nothing was moved. Then move {$name} again."]),
                $open,
            );
        }

        // ------------------------------------------------------ the numbers

        $plan = new RosterMovePlan();
        $plan->path = $back === null ? RosterMovePlan::LEFT_AND_STARTED : RosterMovePlan::RETURNED;
        $plan->student = $name;
        $plan->fromId = (int) $from->getKey();
        $plan->fromName = (string) $from->name;
        $plan->toId = (int) $to->getKey();
        $plan->toName = (string) $to->name;
        $plan->movedOn = $on;
        $plan->gradeLabel = $row->grade_label;
        $plan->oldMembershipId = (int) $row->getKey();

        $plan->held = AcademicRecordsHeld::counts($row);

        // THE MOVE DAY IS OWED TO ONE REGISTER. Decided from the LAST register
        // mark the old class holds on or after the chosen day: those days stay
        // with the old class, and the new class expects the student from the
        // day after. Half-open ranges on the raw column, as
        // AttendanceLogController::marksIn reads it: the `date` cast stores
        // 'Y-m-d 00:00:00' on SQLite, where an exact match would miss.
        $lastMark = DB::table('attendance_records')
            ->where('group_membership_id', $row->getKey())
            ->where('session_date', '>=', $on)
            ->max('session_date');

        if ($lastMark !== null) {
            $plan->oldClassKeepsMoveDay = true;
            $plan->oldClassMarkedUpTo = substr((string) $lastMark, 0, 10);
            $plan->firstDay = self::shift($plan->oldClassMarkedUpTo, 1);
        } else {
            $plan->firstDay = $on;
        }

        // "NOT MARKED THERE" IS SAID ONLY WHEN IT WILL BE TRUE. The class took
        // the register that day; on a return the student's own place there may
        // be among the rows it marked (moved out and back on one day: the old
        // class kept that day, and its mark). Then they ARE marked, and telling
        // the office to chase the teacher would be wrong. A new place can hold
        // no mark, so the other path needs no second look.
        $onFirstDay = fn () => DB::table('attendance_records')
            ->where('session_date', '>=', $plan->firstDay)
            ->where('session_date', '<', self::shift($plan->firstDay, 1));

        $plan->newClassTookRegisterOnFirstDay = $onFirstDay()->where('group_id', $to->getKey())->exists()
            && ! ($back !== null && $onFirstDay()->where('group_membership_id', $back->getKey())->exists());

        if ($back !== null) {
            // A RETURN KEEPS ITS FIRST JOINING DAY unless the class took a
            // register while the student was away. Then the place counts from
            // the first day back, so the days it met without them are not
            // "not marked"; marks already saved still count.
            $missed = DB::table('attendance_records')
                ->where('group_id', $to->getKey())
                ->where('session_date', '>=', self::shift($back->left_on->toDateString(), 1))
                ->where('session_date', '<', $plan->firstDay)
                ->distinct()
                ->count('session_date');

            $plan->registersMissed = $missed;
            $plan->joinedKept = $missed === 0;
            $plan->previousJoinedOn = $plan->joinedKept ? null : $back->joined_at?->toDateString();
            $plan->joinedOn = $plan->joinedKept ? $back->joined_at?->toDateString() : $plan->firstDay;
            $plan->studentUnconfirmed = ! $back->isConfirmed();
            $plan->membershipId = (int) $back->getKey();
        } else {
            $plan->joinedOn = $plan->firstDay;
            $plan->studentUnconfirmed = ! $row->isConfirmed();
        }

        // ---------------------------------------------------- the guardians

        $travelling = $fromEntries->filter(fn (GroupMembership $e): bool => $e->left_on === null)->values();
        $vouching = $travelling->filter(fn (GroupMembership $e): bool => $e->isConfirmed());
        $twin = fn (GroupMembership $e): ?GroupMembership => $toEntries
            ->first(fn (GroupMembership $t): bool => (int) $t->contact_id === (int) $e->contact_id);

        $plan->travelling = $travelling->count();

        // Unconfirmed entries that will stand open beside the student in the
        // new class: the ones it already holds, and the unconfirmed ones that
        // travel. Two situations, never added into one number.
        $plan->confirmedInOldClassOnly = $toEntries
            ->filter(fn (GroupMembership $t): bool => ! $t->isConfirmed()
                && $vouching->contains(fn (GroupMembership $v): bool => (int) $v->contact_id === (int) $t->contact_id))
            ->count();

        $plan->formClaims = $toEntries->filter(fn (GroupMembership $t): bool => ! $t->isConfirmed())->count()
            - $plan->confirmedInOldClassOnly
            + $travelling->filter(fn (GroupMembership $e): bool => ! $e->isConfirmed() && $twin($e) === null)->count();

        $plan->consentToRecordAgain = $vouching
            ->filter(fn (GroupMembership $e): bool => $e->hasConsent() && ! (bool) $twin($e)?->hasConsent())
            ->count();

        $plan->consentInForceAgain = $toEntries
            ->filter(fn (GroupMembership $t): bool => $t->hasConsent())
            ->map(fn (GroupMembership $t): array => [
                'guardian' => $names[(int) $t->contact_id] ?? 'A guardian',
                'scope' => (string) $t->consent_scope,
                'recorded_on' => $t->consent_granted_at?->toDateString(),
            ])->values()->all();

        // ------------------------------------------- what the old row holds

        $plan->consentRecordedHere = $fromEntries->contains(fn (GroupMembership $e): bool => $e->consentColumnsAreSet());

        // Remove takes the guardian entries beside a student row with it, and
        // whatever consent they carry. So the old entry is offered as removable
        // only when it holds nothing a delete would destroy AND no entry beside
        // it carries consent.
        $plan->oldEntryRemovable = ! AcademicRecordsHeld::any(AcademicRecordsHeld::blocking($plan->held))
            && ! $plan->consentRecordedHere;

        $plan->bucksStaying = $this->classStoreBucksStaying($from, $plan->held);
        $plan->scheduledMessagesStopping = $this->scheduledMessagesStopping($row);

        $plan->reportCardNotStarted = ($plan->held['report cards'] ?? 0) === 0
            && (($plan->held['register marks'] ?? 0) > 0 || ($plan->held['marks'] ?? 0) > 0);

        return $plan;
    }

    /**
     * How many messages scheduled ABOUT this student will not go out: the
     * codebase's own "still waiting" set (GroupsSweepHealth). A row that was
     * sent, failed or cancelled is not counted here; it still counts as a
     * record that stays (AcademicRecordsHeld, "any state").
     */
    protected function scheduledMessagesStopping(GroupMembership $row): int
    {
        return DB::table('group_message_schedules')
            ->where('about_membership_id', $row->getKey())
            ->whereIn('status', [GroupMessageSchedule::STATUS_SCHEDULED, GroupMessageSchedule::STATUS_SENDING])
            ->count();
    }

    /**
     * THE CLASS STORE SEAM (W6-C1, still the owner's question): does the old
     * row hold Manara Bucks that stay behind?
     *
     * A boolean, never a figure: the office reads class totals only. It
     * answers whether ledger rows exist, and only for a school that holds the
     * class store; it reads no balance and writes nothing.
     *
     * If the owner decides a balance should follow the student, the
     * `transfer_out` / `transfer_in` pair belongs HERE, under the roster-row
     * locks the move already holds, reading the balance with the ledger's own
     * locked read and never for display. Two things its builder must know:
     * `bucks:expire` writes off what is left on a row at the old class's end
     * or the school year's end, so a carry-over decided later has to give that
     * back first; and the ledger's `dedupe_key` embeds the roster row id, so a
     * transfer onto a new row starts a new namespace.
     *
     * @param  array<string, int>  $held
     */
    protected function classStoreBucksStaying(Group $from, array $held): bool
    {
        return ($held[AcademicRecordsHeld::LEDGER_LABEL] ?? 0) > 0
            && SchoolSettings::classStore(Masjid::find($from->masjid_id));
    }

    // ----------------------------------------------------------- the writes

    /**
     * What the dialog showed must be what happens: the path, the first day in
     * the new class and, on a return, the joining day. A register saved between
     * the read and the tap can change any of them.
     *
     * @param  array<string, mixed>  $options
     */
    private function refuseWhenNotWhatWasShown(RosterMovePlan $plan, array $options): void
    {
        $path = $options['expected_path'] ?? null;
        $firstDay = $options['expected_first_day'] ?? null;
        $joinedOn = $options['expected_joined_on'] ?? null;

        if (($path !== null && $path !== $plan->path)
            || ($firstDay !== null && $firstDay !== $plan->firstDay)
            || ($plan->path === RosterMovePlan::RETURNED && $joinedOn !== null && $joinedOn !== $plan->joinedOn)) {
            throw RosterMoveRefused::lookAgain();
        }
    }

    /**
     * The new class is written FIRST, from the rows as locked, and the old row
     * is closed LAST: once the old row is saved its hook gives every entry
     * beside it a leaving date, and travelling and closed entries could no
     * longer be told apart.
     *
     * @param  Collection<int, GroupMembership>  $rows
     * @param  array<string, mixed>  $options
     */
    private function write(RosterMovePlan $plan, Group $from, Group $to, Collection $rows, GroupMembership $row, array $options, ?User $actor): void
    {
        $studentId = (int) $row->contact_id;
        $isEntry = fn (GroupMembership $m): bool => $m->isGuardian() && (int) $m->guardian_of_contact_id === $studentId;

        $travelling = $rows->filter(fn (GroupMembership $m): bool => (int) $m->group_id === (int) $from->getKey()
            && $isEntry($m) && $m->left_on === null)->values();
        $toEntries = $rows->filter(fn (GroupMembership $m): bool => (int) $m->group_id === (int) $to->getKey() && $isEntry($m))->values();

        if ($plan->path === RosterMovePlan::RETURNED) {
            $place = $rows->firstWhere('id', $plan->membershipId);

            // The model's hook re-opens every entry beside the student there.
            // The guardian rule has already refused a confirmed one that
            // nobody vouches for.
            $place->returnToRoster();

            if (! $plan->joinedKept) {
                $place->joined_at = $plan->firstDay;
            }
        } else {
            $place = $this->carry($row, $to, $plan->firstDay);
            $place->grade_label = $row->grade_label;
        }

        if ($options['grade_given'] ?? false) {
            $place->grade_label = $options['grade_label'] ?? null;
        }

        $place->markMovedIn($actor, (int) $from->getKey(), $plan->movedOn)->save();
        $plan->membershipId = (int) $place->getKey();

        // Guardians. An entry the new class already holds for the same adult
        // and child is RE-USED as it is: its own provenance, its own consent,
        // nothing copied onto it, so nothing is upgraded. Every entry there is
        // open afterwards. A travelling entry with no twin is carried, with the
        // same first day as the student and no consent.
        foreach ($toEntries as $entry) {
            if ($entry->left_on === null) {
                continue;
            }

            // On a return the hook above has already re-opened it in the
            // database; the copy held here is the row as it was locked.
            if ($plan->path !== RosterMovePlan::RETURNED) {
                $entry->returnToRoster()->save();
            }

            $plan->guardianEntriesReopened[] = (int) $entry->getKey();
        }

        foreach ($travelling as $entry) {
            $twin = $toEntries->first(fn (GroupMembership $t): bool => (int) $t->contact_id === (int) $entry->contact_id);

            if ($twin !== null) {
                $plan->guardianEntriesReused[] = (int) $twin->getKey();

                continue;
            }

            $copy = $this->carry($entry, $to, $plan->firstDay);
            $copy->save();
            $plan->guardianEntriesCarried[] = (int) $copy->getKey();
        }

        // The old row, last. Its hook gives every entry beside it the same
        // leaving day. No clamp: a student placed and moved on one day gets a
        // leaving day before their joining day, so the old register expects
        // them on no day at all instead of on a day both registers would owe.
        $row->markLeftByStaff($actor, self::shift($plan->firstDay, -1))
            ->markMovedOut($actor, (int) $to->getKey(), $plan->movedOn)
            ->save();

        $plan->done = true;
    }

    /**
     * THE ONLY CODE IN A MOVE THAT CREATES A ROSTER ROW, and the one caller of
     * `GroupMembership::carriedFrom()`.
     *
     * It fails closed: the new row is stamped as an unconfirmed claim FIRST and
     * carries the old row's standing second, so a row that misses the second
     * call is a claim with no consent, never a grant. The organisation is
     * compared here, target class against old row, because the `creating` hook
     * overwrites whatever is typed onto an unsaved row; the real guard is that
     * the target class was found through the tenant scope.
     */
    protected function carry(GroupMembership $old, Group $to, string $on): GroupMembership
    {
        if ((int) $to->masjid_id !== (int) $old->masjid_id) {
            throw new \LogicException('A roster row is carried only into a class of the same organisation.');
        }

        $new = new GroupMembership([
            'masjid_id' => $old->masjid_id,
            'group_id' => $to->getKey(),
            'contact_id' => $old->contact_id,
            'role' => $old->role,
            'guardian_of_contact_id' => $old->guardian_of_contact_id,
            'joined_at' => $on,
        ]);

        $new->selfAssertedFrom(null);
        $new->carriedFrom($old);

        return $new;
    }

    /**
     * The adults who hold a vouching entry in the class being left, read from
     * the locked rows BEFORE anything is written.
     *
     * @param  Collection<int, GroupMembership>  $rows
     * @return list<int> contact ids
     */
    private function vouchers(Collection $rows, int $fromId, int $studentId): array
    {
        return $rows
            ->filter(fn (GroupMembership $m): bool => (int) $m->group_id === $fromId
                && $m->isGuardian()
                && (int) $m->guardian_of_contact_id === $studentId
                && $m->left_on === null
                && $m->isConfirmed())
            ->pluck('contact_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * A CHECK THAT FAILS CLOSED, after the writes and inside the transaction.
     *
     * It is a LOGIC assertion, not a race guard: on MySQL it sees this
     * transaction's snapshot and its own writes, so it cannot see what another
     * transaction committed (the contact lock and the foreign keys are the
     * concurrency guards). It catches the move's own mistakes, and it stands in
     * on SQLite, which has no row locks.
     *
     * @param  list<int>  $vouchers
     */
    private function refuseUnlessTheRostersAreSound(RosterMovePlan $plan, int $studentId, array $vouchers): void
    {
        $after = $this->rowsAbout([$plan->fromId, $plan->toId], $studentId)->get();

        $places = $after->filter(fn (GroupMembership $m): bool => (int) $m->contact_id === $studentId
            && in_array($m->role, GroupMembership::PARTICIPANT_ROLES, true)
            && $m->left_on === null);

        $sound = $places->where('group_id', $plan->toId)->count() === 1
            && $places->where('group_id', $plan->fromId)->count() === 0
            && ! $after->contains(fn (GroupMembership $m): bool => (int) $m->group_id === $plan->toId
                && $m->isGuardian()
                && (int) $m->guardian_of_contact_id === $studentId
                && $m->left_on === null
                && $m->isConfirmed()
                && ! in_array((int) $m->contact_id, $vouchers, true));

        if (! $sound) {
            throw RosterMoveRefused::changed();
        }
    }

    // ------------------------------------------------------------- internals

    /**
     * Every roster row about one student in these classes: their own places,
     * and the guardian entries that name them.
     *
     * @param  array<int, int|null>  $groupIds
     */
    private function rowsAbout(array $groupIds, int $studentId)
    {
        return GroupMembership::query()
            ->whereIn('group_id', array_values(array_filter($groupIds, fn ($id): bool => $id !== null)))
            ->where(fn ($q) => $q->where('contact_id', $studentId)->orWhere('guardian_of_contact_id', $studentId))
            ->orderBy('id');
    }

    /**
     * A short lock wait for the length of the move, so a held row answers
     * "this roster changed" instead of a gateway timeout. MySQL only; put back
     * afterwards because a connection can outlive the request.
     */
    private function withShortLockWait(callable $move): RosterMovePlan
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $move();
        }

        $before = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_SECONDS);

        try {
            return $move();
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$before);
        }
    }

    /** Today on the school's clock, never the server's. */
    private function todayFor(Group $group): string
    {
        return SchoolCalendar::for((int) $group->masjid_id)->today();
    }

    /**
     * @param  Collection<int, mixed>  $contactIds
     * @return array<int, string>
     */
    private function namesFor(Collection $contactIds): array
    {
        return Contact::query()
            ->whereIn('id', $contactIds->unique()->values())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Contact $c): array => [(int) $c->getKey() => self::nameOf($c)])
            ->all();
    }

    private static function nameOf(?Contact $contact): string
    {
        $name = trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''));

        return $name === '' ? 'This person' : $name;
    }

    private static function shift(string $day, int $days): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day)->addDays($days)->toDateString();
    }
}
