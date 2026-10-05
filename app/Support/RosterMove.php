<?php

namespace App\Support;

use App\Exceptions\RosterMoveRefused;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessageSchedule;
use App\Models\GroupPost;
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
 * The rules, in the words the rules file uses (.claude/rules/groups.md,
 * "Moving a student to another class"):
 *
 *   R1. Records never change class.
 *   R2. A move never gives an adult more than they held. A closed entry in
 *       the class being left never travels. A confirmed entry in the class
 *       being entered is re-opened only when the same adult holds a confirmed,
 *       current entry for the student in the class being left; otherwise the
 *       move is refused. An unconfirmed entry may be re-opened and stays
 *       unconfirmed. Consent is carried as it is (the owner, 2026-10-05) onto
 *       an entry the move creates, from a confirmed, current entry, and not
 *       when the adult already stands in the class being entered for another
 *       child with less. Consent on an entry the class already holds is never
 *       written by a move; when it is in force again the screen names each
 *       guardian with the scope and the date, and a move is refused while it
 *       would bring back a consent the family withdrew where it had been
 *       carried.
 *   R3. A move destroys nothing: no row is deleted, no consent column cleared.
 *   R4. Locks first: every lock is taken by primary key before the first
 *       ordinary read, and the decision is made from the locked rows.
 *   R8. A consent travels only as it was recorded. Same adult, same child,
 *       from a confirmed, current entry for which `hasConsent()` is true, onto
 *       an entry this move creates, both columns unchanged, marked with the
 *       class it came from, and not at all when it would widen what the adult
 *       already holds in the class entered. A blank stays blank.
 *   R10. Nothing withdrawn comes back by a move. An entry that a move or a
 *       "Put back" would re-open with consent is checked against the other
 *       side of every carry it took part in; when the family has since
 *       withdrawn or narrowed there, the act is refused with the remedy.
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
 *   - a twin: an entry T already holds for the same adult and child.
 *   - a carried consent is one a move copied; the copy is the entry that holds
 *     it and its source is the entry it was copied from.
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
     * Seconds a move waits for a row somebody else holds, per LOCK REQUEST,
     * before it answers "this roster changed" (MySQL's default is 50, longer
     * than the web server waits). One attempt asks for the contact row, the
     * roster row, the rows about the student and the two class rows in turn,
     * so three seconds times ATTEMPTS is the wait behind ONE held row, not a
     * bound on the move.
     */
    public const LOCK_WAIT_SECONDS = 3;

    /**
     * Attempts of a single move. A whole-class run asks for one per student
     * (the `attempts` option): a busy student is reported there and offered
     * again, so retries would only spend the run's time.
     */
    public const ATTEMPTS = 3;

    /** What a move is told between a deploy's new code and its migration. */
    public const UPDATING = 'Manara is being updated. Try this move again in a minute.';

    /** Why an entry that would re-open with consent must not, or cannot be checked (rule R10). */
    public const COPY_WITHDRAWN = 'copy_withdrawn';

    public const COPY_NARROWED = 'copy_narrowed';

    public const SOURCE_WITHDRAWN = 'source_withdrawn';

    public const SOURCE_NARROWED = 'source_narrowed';

    public const SOURCE_GONE = 'source_gone';

    /** How much a consent opens, for "does the adult already hold as much there". */
    private const CONSENT_RANK = ['none' => 0, GroupMembership::CONSENT_FEED => 1, GroupMembership::CONSENT_MEDIA => 2];

    /** @var array<int, array{others: int, stories: int, with_media: int}> what a class holds, by class id, for the previews of one request */
    private array $classHolds = [];

    // ----------------------------------------------------- the deploy window

    /**
     * MAY A MOVE RUN YET?
     *
     * bin/deploy serves the new code before it migrates. A move made then
     * would copy no consent and say that it had, so until the marker column
     * exists every preview and every move is refused with one sentence and
     * nothing is locked, read or written. One check per column, each asked of
     * the class that owns the column.
     */
    public static function ready(): bool
    {
        return self::columnsMissing() === [];
    }

    /**
     * The guard at the top of the preview and of the move, and the one a
     * whole-class preview or run asks once. A refusal is logged at WARNING
     * (production drops anything lower) with the column that is missing: a
     * migration that failed half-way would otherwise leave every move refused
     * "for a minute" for ever with nothing in the log.
     *
     * @throws RosterMoveRefused
     */
    public static function refuseUnlessReady(): void
    {
        $missing = self::columnsMissing();

        if ($missing === []) {
            return;
        }

        Log::warning('roster.move.not_ready', ['missing' => $missing]);

        throw RosterMoveRefused::conflict(self::UPDATING);
    }

    /** @return list<string> */
    private static function columnsMissing(): array
    {
        return array_keys(array_filter([
            'group_memberships.'.GroupMembership::CONSENT_CARRIED_FROM => ! GroupMembership::consentCarryReady(),
        ]));
    }

    // ------------------------------------------------------------ the read

    /**
     * What a move would do, without doing it. No lock, no write. The same
     * decision the move makes under its locks.
     *
     * `$options` are a whole-class preview's or run's, and no request can
     * supply them: `whole_class` and `standing_before_id` (see
     * `standingForAnotherChild`) and `today`, the school's day read once for
     * the run. A single preview passes none.
     *
     * @param  array{whole_class?: bool, standing_before_id?: ?int, today?: ?string}  $options
     *
     * @throws RosterMoveRefused
     */
    public function preview(Group $from, GroupMembership $row, ?Group $to, string $on, array $options = []): RosterMovePlan
    {
        self::refuseUnlessReady();

        $rows = $this->rowsAbout([$from->id, $to?->id], (int) $row->contact_id)->get();

        return $this->decide(
            $from, $to, $rows, $row, $on,
            $options['today'] ?? $this->todayFor($from),
            self::standingBefore($options),
            false,
            (bool) ($options['whole_class'] ?? false),
        );
    }

    // ----------------------------------------------------------- the write

    /**
     * Move the student.
     *
     * What the dialog showed travels in `expected_*`: the path, the first
     * day, the joining day on a return, the consent result
     * (`expected_consent`, the plan's fingerprint) and the rule for Manara
     * Bucks (`expected_bucks_rule`).
     *
     * `consent_must_be_echoed` is the single verb's (see
     * `refuseWhenNotWhatWasShown`): a request that does not say what it was
     * shown about consent may not carry any.
     *
     * Five more options are a whole-class run's. NO REQUEST CLASS HAS A RULE
     * FOR THEM, and the single verb's controller names every key it passes, so
     * none can arrive over HTTP:
     *
     *   - `run`: the run's id, for the log line (null for a single move);
     *   - `whole_class`: this student is one of a class being moved, so a
     *     brother or sister may be going back with them (see
     *     `standingForAnotherChild`);
     *   - `standing_before_id`: see `standingForAnotherChild`;
     *   - `today`: the school's day read ONCE for the run, so two students of
     *     one run are never judged on two days;
     *   - `attempts`: 1 inside a run (see ATTEMPTS).
     *
     * @param  array{grade_given?: bool, grade_label?: ?string, expected_path?: ?string, expected_first_day?: ?string, expected_joined_on?: ?string, expected_consent?: ?string, expected_bucks_rule?: ?string, consent_must_be_echoed?: bool, run?: ?string, whole_class?: bool, standing_before_id?: ?int, today?: ?string, attempts?: ?int}  $options
     *
     * @throws RosterMoveRefused
     */
    public function move(Group $from, GroupMembership $seen, int $toGroupId, string $on, array $options, ?User $actor): RosterMovePlan
    {
        self::refuseUnlessReady();

        $rowId = (int) $seen->getKey();
        $contactId = (int) $seen->contact_id;
        $fromId = (int) $from->getKey();
        $today = $options['today'] ?? $this->todayFor($from);
        $standingBefore = self::standingBefore($options);
        $wholeClass = (bool) ($options['whole_class'] ?? false);
        $attempts = max(1, (int) ($options['attempts'] ?? self::ATTEMPTS));

        // The cheap refusals, answered before any lock is asked for. Every one
        // of them is decided again below, from the locked rows.
        $this->preview($from, $seen, Group::query()->find($toGroupId), $on, [
            'today' => $today,
            'standing_before_id' => $standingBefore,
            'whole_class' => $wholeClass,
        ]);

        try {
            $plan = $this->withShortLockWait(fn (): RosterMovePlan => DB::transaction(
                function () use ($rowId, $contactId, $fromId, $toGroupId, $on, $today, $standingBefore, $wholeClass, $options, $actor): RosterMovePlan {
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

                    $plan = $this->decide($from, $to, $rows, $row, $on, $today, $standingBefore, true, $wholeClass);

                    $this->refuseWhenNotWhatWasShown($plan, $options);

                    $vouchers = $this->vouchers($rows, $fromId, $contactId);

                    $this->write($plan, $from, $to, $rows, $row, $options, $actor);

                    $this->written($plan);

                    $this->refuseUnlessTheRostersAreSound($plan, $contactId, $vouchers);

                    return $plan;
                },
                $attempts,
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
        // Ids only, never a name. `consent_carried` is pairs of the new entry
        // and the entry it was copied from; the marker column is the record of
        // a carry and this line its history.
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
            'consent_carried' => $plan->consentEntriesCarried,
            'consent_not_carried' => $plan->consentEntriesNotCarried,
            'previous_joined_at' => $plan->previousJoinedOn,
            'run' => isset($options['run']) ? (string) $options['run'] : null,
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

    // ------------------------------------------ nothing withdrawn comes back

    /**
     * WHICH ENTRIES WOULD RE-OPEN WITH A CONSENT THE FAMILY HAS SINCE TAKEN
     * BACK ON THE OTHER SIDE OF A CARRY (rule R10). One rule, read from both
     * sides, as `notVouched` is:
     *
     *   - the move: the entries to open are the target class's;
     *   - "Put back" on a row that was moved: they are this class's.
     *
     * After a carry there are two entries for one adult and child, a source
     * and a copy, and the office can since have withdrawn or narrowed on
     * either one. No move writes a consent column of an existing entry (R3),
     * so the only way not to bring the other one back into force is to refuse.
     *
     * Only an entry that is CLOSED now and has consent is looked at: it is the
     * one that would be open, and in force, afterwards. For each, a verdict:
     *
     *   - `copy_withdrawn`: another entry of the same adult and child carries
     *     the marker naming THIS entry's class and holds no consent. This entry
     *     is the source (or an earlier record) and its copy was withdrawn where
     *     it had been carried.
     *   - `copy_narrowed`: the same, where the marked entry holds LESS than
     *     this one (the class story where this one holds photographs). The
     *     office recorded less on the copy after the carry, and that record
     *     kept the marker (`GroupConsentController::update`). It is a
     *     comparison of the two rows as they are: a copy nobody touched whose
     *     source was recorded wider afterwards reads the same and is refused
     *     the same, so the sentence says what each class holds, never who
     *     changed what.
     *   - `source_withdrawn` / `source_narrowed`: this entry's own marker names
     *     a class, that class holds an entry for the same adult and child, and
     *     that entry now holds nothing, or the class story where this one holds
     *     photographs. This entry is the copy. The source's two columns are
     *     read as they are, whether or not it has left: a closed source still
     *     holds its record.
     *   - `source_gone`: the marker names a class that holds no entry for them
     *     any more (the place there was removed, or a merge re-issued the row).
     *     There is nothing to compare with, so the act goes ahead and the
     *     guardian is NAMED.
     *
     * An entry with none of these is absent from the answer.
     *
     * WHAT THIS CANNOT SEE. `copy_withdrawn` and `copy_narrowed` are row
     * states, "marker set, and less than the other side holds", and four
     * ordinary acts erase them: Remove on the student's place where the copy
     * sits; Remove on the guardian's own entry there (after which the guardian
     * rule tells the office to add them again, and the entry it adds is
     * unmarked); a merge that re-issues the row; and the office recording, on
     * the copy, as much as the other class holds (which clears the marker on
     * purpose) before withdrawing or reducing. Down a chain (carried on to a
     * third class and withdrawn there) the first class's own copy is not
     * blank, so nothing fires. And it has no memory: after the remedy and a
     * fresh record, the marked copy still exists and refuses again. Each
     * fails towards asking the family, never towards more than was recorded.
     *
     * @param  Collection<int, GroupMembership>  $entriesToOpen  guardian entries naming one child, in one class
     * @param  Collection<int, GroupMembership>  $elsewhere  guardian entries naming that child in the organisation's other classes
     * @return array<int, array{0: string, 1: int}> entry id => [verdict, the other class's id]
     */
    public static function consentComingBack(Collection $entriesToOpen, Collection $elsewhere): array
    {
        $verdicts = [];

        foreach ($entriesToOpen as $entry) {
            if (! $entry->isGuardian() || $entry->left_on === null || ! $entry->hasConsent()) {
                continue;
            }

            $theirs = $elsewhere->filter(
                fn (GroupMembership $e): bool => $e->isGuardian()
                    && (int) $e->contact_id === (int) $entry->contact_id
                    && (int) $e->guardian_of_contact_id === (int) $entry->guardian_of_contact_id
                    && (int) $e->group_id !== (int) $entry->group_id,
            );

            $withdrawnCopy = $theirs->first(
                fn (GroupMembership $e): bool => $e->{GroupMembership::CONSENT_CARRIED_FROM} !== null
                    && (int) $e->{GroupMembership::CONSENT_CARRIED_FROM} === (int) $entry->group_id
                    && ! $e->consentColumnsAreSet(),
            );

            if ($withdrawnCopy !== null) {
                $verdicts[(int) $entry->getKey()] = [self::COPY_WITHDRAWN, (int) $withdrawnCopy->group_id];

                continue;
            }

            $narrowedCopy = $theirs->first(
                fn (GroupMembership $e): bool => (int) $e->{GroupMembership::CONSENT_CARRIED_FROM} === (int) $entry->group_id
                    && $e->holdsLessThanCarriedFrom($entry),
            );

            if ($narrowedCopy !== null) {
                $verdicts[(int) $entry->getKey()] = [self::COPY_NARROWED, (int) $narrowedCopy->group_id];

                continue;
            }

            $carriedFrom = $entry->{GroupMembership::CONSENT_CARRIED_FROM};

            if ($carriedFrom === null) {
                continue;
            }

            $source = $theirs->first(fn (GroupMembership $e): bool => (int) $e->group_id === (int) $carriedFrom);

            $verdict = match (true) {
                $source === null => self::SOURCE_GONE,
                ! $source->hasConsent() => self::SOURCE_WITHDRAWN,
                self::CONSENT_RANK[$source->consent_scope] < self::CONSENT_RANK[$entry->consent_scope] => self::SOURCE_NARROWED,
                default => null,
            };

            if ($verdict !== null) {
                $verdicts[(int) $entry->getKey()] = [$verdict, (int) $carriedFrom];
            }
        }

        return $verdicts;
    }

    /**
     * For the roster list: what "Put back" would mean on each student row that
     * was moved out of this class, or that has left it. Keyed by roster row id.
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
     * THREE MORE FIELDS since consent is carried (2026-10-05), each a list of
     * sentences built here and printed as they come:
     *
     *   - `consent_blocks`: rule R10 read from this side. One sentence for each
     *     guardian entry beside this row that "Put back" would re-open with a
     *     consent the family has since withdrawn or narrowed on the other side
     *     of a carry (`consentComingBack`), then once what to do. While it is
     *     not empty the screen offers no "Put back", as it does for
     *     `guardians_not_vouched`. The verb itself stays ungated.
     *   - `consent_lines`: one sentence for every other entry here that holds
     *     consent and would be in force again.
     *   - `bucks_line`: null. It is filled from the commit that lets a move
     *     carry Manara Bucks.
     *
     * A ROW THAT SIMPLY LEFT GETS THE TWO CONSENT LISTS TOO. "Put back" re-opens
     * the entries beside it whichever way the student left, and a carried copy
     * beside a row that left is the same copy: its source may have been
     * withdrawn while the child was in neither class. Such a row has no "moved
     * to", so `student_there` is `none`, `open_group` is null and
     * `guardians_not_vouched` is empty; and it is listed only when one of the
     * two consent lists has something to say.
     *
     * The two consent lists are computed only once the marker column exists
     * (`GroupMembership::consentCarryReady()`); until then both are empty, a
     * row that simply left is not listed at all, and this list, which every
     * roster read goes through, never names the column.
     *
     * Two queries for the whole list, and only when a student row has left or
     * carries "moved to".
     *
     * @param  Collection<int, GroupMembership>  $roster  this class's rows, with `contact` loaded
     * @return array<int, array{student_there: string, open_group: ?array{id:int,name:string}, guardians_not_vouched: list<array{membership_id:int, reason:string, sentence:string}>, consent_blocks: list<string>, consent_lines: list<string>, bucks_line: ?string}>
     */
    public static function movedToStates(Group $group, Collection $roster): array
    {
        $carryReady = GroupMembership::consentCarryReady();

        $listed = $roster->filter(
            fn (GroupMembership $m): bool => in_array($m->role, GroupMembership::PARTICIPANT_ROLES, true)
                && ($m->moved_to_group_id !== null || ($carryReady && $m->left_on !== null)),
        );

        if ($listed->isEmpty()) {
            return [];
        }

        $students = $listed->pluck('contact_id')->unique()->values();

        $elsewhere = GroupMembership::query()
            ->where('group_id', '!=', $group->getKey())
            ->where(fn ($q) => $q->whereIn('contact_id', $students)->orWhereIn('guardian_of_contact_id', $students))
            ->get();

        $live = Group::query()
            ->whereIn('id', $elsewhere->pluck('group_id')->merge($listed->pluck('moved_to_group_id')->filter())->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        // A deleted class is not somewhere a student is: its rows still exist
        // (a class is soft-deleted), and they are left out here.
        $about = $elsewhere->filter(fn (GroupMembership $m): bool => $live->has((int) $m->group_id));

        $states = [];

        foreach ($listed as $row) {
            $student = (int) $row->contact_id;
            $wasMoved = $row->moved_to_group_id !== null;
            $movedTo = (int) $row->moved_to_group_id;
            $name = self::nameOf($row->contact);

            $entriesHere = $roster->filter(
                fn (GroupMembership $m): bool => $m->isGuardian() && (int) $m->guardian_of_contact_id === $student,
            );

            $studentThere = 'none';
            $compareWith = collect();
            $unvouched = [];

            if ($wasMoved) {
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
                }
            }

            $where = $compareWith->map(fn (int $id): string => (string) $live->get($id)?->name)->filter()->implode(' or ');
            $openId = $compareWith->first() ?? ($wasMoved && $live->has($movedTo) ? $movedTo : null);

            // Rule R10 from this side. Read against EVERY other class of the
            // organisation, a deleted one included: a family's withdrawal
            // there is still their withdrawal.
            $consentBlocks = [];
            $consentLines = [];

            if ($carryReady && $row->left_on !== null) {
                $comingBack = self::consentComingBack($entriesHere, $elsewhere->filter(
                    fn (GroupMembership $m): bool => (int) $m->masjid_id === (int) $group->masjid_id
                        && $m->isGuardian() && (int) $m->guardian_of_contact_id === $student,
                ));

                foreach ($entriesHere as $entry) {
                    if ($entry->left_on === null || ! $entry->hasConsent()) {
                        continue;
                    }

                    $guardian = self::nameOf($entry->contact);
                    $held = RosterMovePlan::consentWords((string) $entry->consent_scope, $entry->consent_granted_at?->toDateString());
                    [$verdict, $classId] = $comingBack[(int) $entry->getKey()] ?? [null, null];
                    $class = $classId === null ? '' : ($live->get($classId)?->name ?? RosterMovePlan::CLASS_REMOVED);

                    match ($verdict) {
                        self::COPY_WITHDRAWN => $consentBlocks[] = "{$guardian} withdrew consent in {$class} after it had been "
                            ."carried there from this class. Putting {$name} back would bring the consent recorded here "
                            ."({$held}) into force again.",
                        self::COPY_NARROWED => $consentBlocks[] = "{$guardian}'s consent in {$class} was carried there from "
                            ."this class and is now for the class story only. Putting {$name} back would bring the consent "
                            ."recorded here ({$held}) into force again.",
                        self::SOURCE_WITHDRAWN, self::SOURCE_NARROWED => $consentBlocks[] = "{$guardian}'s consent here "
                            ."({$held}) was carried from {$class}, and consent in {$class} "
                            .($verdict === self::SOURCE_NARROWED ? 'is now for the class story only' : 'has since been withdrawn')
                            .". Putting {$name} back would bring it into force again.",
                        default => $consentLines[] = "Putting {$name} back brings {$guardian}'s consent in this class into "
                            ."force again ({$held}).",
                    };
                }

                if ($consentBlocks !== []) {
                    $consentBlocks[] = "Withdraw it on this roster first (the Consent button on the guardian's row), "
                        ."then put {$name} back.";
                }
            }

            // A row that simply left and brings no consent back has nothing
            // to say: it stays absent, as it always was.
            if (! $wasMoved && $consentBlocks === [] && $consentLines === []) {
                continue;
            }

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
                'consent_blocks' => $consentBlocks,
                'consent_lines' => $consentLines,
                'bucks_line' => null,
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
     * THE CHECKS THAT ARE THE SAME FOR EVERY STUDENT OF A CLASS: the target
     * exists in this school, is not this class, both are classes, the target is
     * switched on and has not ended before the chosen day, and the day is not
     * in the future.
     *
     * One copy of each rule. `decide()` calls it for one student, with the
     * student's name; a whole-class preview and run call it ONCE, with null,
     * so the office reads one refusal and not one per student.
     *
     * @throws RosterMoveRefused
     */
    public static function refuseUnlessClassesAndDayAllow(Group $from, ?Group $to, string $on, string $today, ?string $name): void
    {
        if ($to === null || $to->trashed() || (int) $to->masjid_id !== (int) $from->masjid_id) {
            throw new RosterMoveRefused("Choose one of this school's classes.");
        }

        if ((int) $to->getKey() === (int) $from->getKey()) {
            throw new RosterMoveRefused($name !== null
                ? "{$name} is already in this class."
                : "{$from->name} cannot be moved into itself.");
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
    }

    /**
     * THE DECISION: which path, the numbers the office is told, or a refusal.
     * No writes. The preview runs it on rows read without locks; the move runs
     * it again on the rows it locked.
     *
     * @param  Collection<int, GroupMembership>  $rows  every roster row about the student in the two classes
     * @param  ?int  $standingBeforeId  a whole-class run's: see `standingForAnotherChild`
     * @param  bool  $underLocks  the move's own call; the preview's is false
     * @param  bool  $wholeClass  one student of a class being moved: see `standingForAnotherChild`
     *
     * @throws RosterMoveRefused
     */
    protected function decide(Group $from, ?Group $to, Collection $rows, GroupMembership $row, string $on, string $today, ?int $standingBeforeId = null, bool $underLocks = false, bool $wholeClass = false): RosterMovePlan
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

        self::refuseUnlessClassesAndDayAllow($from, $to, $on, $today, $name);

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

        // Nothing withdrawn comes back by a move (R10). Every closed entry the
        // target holds for the student is open afterwards, on both paths, with
        // the consent it holds. Decided from the locked rows, plus ONE ordinary
        // read of the same adults' entries for this child in the
        // organisation's other classes, made only when such an entry exists.
        // That read is not locked: a withdrawal landing between it and the
        // commit gives the state "moved, then withdrew", which no lock can
        // forbid, and the withdrawal's own answer names what still stands.
        $reopening = $toEntries->filter(fn (GroupMembership $t): bool => $t->left_on !== null && $t->hasConsent())->values();
        $comingBack = [];
        $classNames = [];

        if ($reopening->isNotEmpty()) {
            $comingBack = self::consentComingBack($reopening, $fromEntries->concat(
                GroupMembership::query()
                    ->where('masjid_id', $from->masjid_id)
                    ->where('role', GroupMembership::ROLE_GUARDIAN)
                    ->where('guardian_of_contact_id', $studentId)
                    ->whereIn('contact_id', $reopening->pluck('contact_id')->unique()->values())
                    ->whereNotIn('group_id', [$from->getKey(), $to->getKey()])
                    ->orderBy('id')
                    ->get(),
            ));

            if ($comingBack !== []) {
                $classNames = Group::query()->whereIn('id', array_column($comingBack, 1))->pluck('name', 'id')->all();
            }
        }

        $sentences = [];
        $firstHeldBack = null;

        foreach ($comingBack as $entryId => [$verdict, $classId]) {
            if ($verdict === self::SOURCE_GONE) {
                continue;
            }

            $entry = $reopening->firstWhere('id', $entryId);
            $guardian = $names[(int) $entry->contact_id] ?? 'A guardian';
            $class = $classNames[$classId] ?? RosterMovePlan::CLASS_REMOVED;
            $held = RosterMovePlan::consentWords((string) $entry->consent_scope, $entry->consent_granted_at?->toDateString());
            $firstHeldBack ??= (int) $entryId;

            $sentences[] = match ($verdict) {
                self::COPY_WITHDRAWN => "{$guardian} withdrew consent in {$class} after it had been carried there from "
                    ."{$to->name}. The consent recorded in {$to->name} ({$held}) would come back into force.",
                self::COPY_NARROWED => "{$guardian}'s consent in {$class} was carried there from {$to->name} and is now for "
                    ."the class story only. The consent recorded in {$to->name} ({$held}) would come back into force.",
                self::SOURCE_WITHDRAWN => "{$guardian}'s consent in {$to->name} ({$held}) was carried there from {$class}, "
                    ."and consent in {$class} has since been withdrawn. It would come back into force in {$to->name}.",
                self::SOURCE_NARROWED => "{$guardian}'s consent in {$to->name} for the class story and photographs was "
                    ."carried there from {$class}, and consent in {$class} is now for the class story only. The "
                    ."photograph consent would come back into force in {$to->name}.",
            };
        }

        if ($sentences !== []) {
            // `membership_id` is the first entry the remedy is about, so
            // "Open {class}" can bring that row into view.
            throw RosterMoveRefused::conflict(
                implode("\n", [...$sentences, "Nothing was moved. Open {$to->name} and withdraw that consent on its roster "
                    ."first (the Consent button on the guardian's row), then move {$name} again. If the family still agrees "
                    ."for {$to->name}, record it there after the move."]),
                $open + ['membership_id' => $firstHeldBack],
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
        $plan->guardianContactIds = $travelling->pluck('contact_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

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

        // CONSENT IS CARRIED AS IT IS, OR NOT AT ALL (R8). Only a vouching
        // entry with no twin gets a new entry, so only it can be carried onto.
        // An adult is admitted to a class's story on ANY one of their current
        // entries there that covers it, so a consent carried for this child
        // would open the class to an adult whose entry there for a brother or
        // sister holds less, and "less" may be a withdrawal made for that
        // class. So: carried when the adult holds no other entry there, or
        // one that already covers the same scope; otherwise nothing is
        // carried, the new entry is blank and unmarked, and the guardian is
        // named. Never a narrowed copy: that would be a record nobody gave.
        $untwinned = $vouching->filter(fn (GroupMembership $e): bool => $twin($e) === null)->values();

        $heldThere = $untwinned->isEmpty()
            ? []
            : $this->standingForAnotherChild(
                $to, $studentId, $untwinned->pluck('contact_id'), $standingBeforeId,
                $wholeClass ? (int) $from->getKey() : null,
            );

        foreach ($untwinned as $entry) {
            $adult = (int) $entry->contact_id;
            [$holds, $whenTheyReturn] = $heldThere[$adult] ?? [null, false];

            if (! $entry->hasConsent()) {
                // Nothing to carry. Whether they already receive the class's
                // story through another child decides which sentence is true;
                // an entry that is closed today delivers nothing yet.
                if ($holds !== null && $holds !== 'none' && ! $whenTheyReturn) {
                    $plan->consentNoneButReceives++;
                } else {
                    $plan->consentNoneRecorded++;
                    $plan->consentNoneRecordedFor[] = $adult;
                }

                continue;
            }

            $scope = (string) $entry->consent_scope;

            if ($holds === null || self::CONSENT_RANK[$holds] >= self::CONSENT_RANK[$scope]) {
                $plan->consentToCarry[] = (int) $entry->getKey();
                $plan->consentCarried[$scope]++;
                $plan->consentCarriedFor[] = $adult;

                continue;
            }

            // `returning` is present only when it is true, so what a single
            // move answers for a guardian who IS in the class has not changed.
            $plan->consentNotCarriedForSibling[] = ['guardian' => $names[$adult] ?? 'A guardian', 'holds' => $holds]
                + ($whenTheyReturn ? ['returning' => true] : []);
            $plan->consentEntriesNotCarried[] = (int) $entry->getKey();
        }

        // A twin is never written, whatever it holds and whatever the entry
        // in the class being left holds.
        $plan->consentLeftAsItWas = $vouching
            ->filter(fn (GroupMembership $e): bool => $e->hasConsent() && $twin($e) !== null && ! $twin($e)->hasConsent())
            ->count();

        $plan->consentInForceAgain = $toEntries
            ->filter(fn (GroupMembership $t): bool => $t->hasConsent())
            ->map(fn (GroupMembership $t): array => [
                'guardian' => $names[(int) $t->contact_id] ?? 'A guardian',
                'scope' => (string) $t->consent_scope,
                'recorded_on' => $t->consent_granted_at?->toDateString(),
                'reopens' => $t->left_on !== null,
                // Carried here from a class that no longer holds an entry for
                // them: R10 had nothing to compare with, so they are named.
                'source_gone_in' => ($comingBack[(int) $t->getKey()][0] ?? null) === self::SOURCE_GONE
                    ? ($classNames[$comingBack[(int) $t->getKey()][1]] ?? RosterMovePlan::CLASS_REMOVED)
                    : null,
            ])->values()->all();

        $plan->consentFingerprint = $plan->fingerprintOfConsent();

        // What a carried consent opens is everything the class still keeps:
        // no family read compares anything with the day a family joined.
        $kept = $this->whatTheClassHolds($to, ! $underLocks);
        $plan->othersInNewClass = $kept['others'];
        $plan->newClassHolds = ['stories' => $kept['stories'], 'with_media' => $kept['with_media']];

        // ------------------------------------------- what the old row holds

        $plan->consentRecordedHere = $fromEntries->contains(fn (GroupMembership $e): bool => $e->consentColumnsAreSet());

        // A blank entry that still carries THE MARKER is a record too: a
        // family withdrew here a consent that had been carried here. Remove
        // would take it, and with it the one thing rule R10 reads to refuse a
        // later move that would bring the other class's consent back.
        $plan->carriedConsentWithdrawnHere = $fromEntries->contains(
            fn (GroupMembership $e): bool => $e->{GroupMembership::CONSENT_CARRIED_FROM} !== null && ! $e->consentColumnsAreSet(),
        );

        // Remove takes the guardian entries beside a student row with it, and
        // whatever consent they carry. So the old entry is offered as removable
        // only when it holds nothing a delete would destroy AND no entry beside
        // it carries consent or the record of a carried consent withdrawn.
        $plan->oldEntryRemovable = ! AcademicRecordsHeld::any(AcademicRecordsHeld::blocking($plan->held))
            && ! $plan->consentRecordedHere
            && ! $plan->carriedConsentWithdrawnHere;

        $plan->bucksStaying = $this->classStoreBucksStaying($from, $plan->held);
        $plan->scheduledMessagesStopping = $this->scheduledMessagesStopping($row);

        $plan->reportCardNotStarted = ($plan->held['report cards'] ?? 0) === 0
            && (($plan->held['register marks'] ?? 0) > 0 || ($plan->held['marks'] ?? 0) > 0);

        return $plan;
    }

    /**
     * WHAT EACH OF THESE ADULTS ALREADY HOLDS IN THE CLASS BEING ENTERED, FOR
     * ANOTHER CHILD: the widest consent among their current, confirmed
     * guardian entries there that name a child other than this student. An
     * adult with no such entry is absent from the answer.
     *
     * Unconfirmed entries are not counted (they hold no consent by rule and
     * give no standing), nor are entries that have left. A blank entry counts
     * as `none`, and that is the case this exists for: blank may be a consent
     * the family withdrew for that class.
     *
     * ONE ORDINARY READ, NOT LOCKED. The other child's entries are outside
     * this move's locks, and a consent write is one UPDATE of one row. If the
     * office withdraws on the sibling's entry after this read and before the
     * commit, the end state is the one the system reaches with no race when
     * the two acts happen in the other order (move, then withdraw there). No
     * lock can forbid that order, so a lock here would buy nothing and would
     * add a deadlock between two siblings moved in opposite directions.
     *
     * `$standingBeforeId` is a whole-class run's, read once before the run:
     * the highest roster row id at that moment. Each student of a run is their
     * own transaction, so without it the second child of a family would find
     * the entry the first child's move made a moment earlier, and the family
     * would keep or lose the story by the order of a list. Entries above it
     * did not stand in the class before the run and are ignored, with one
     * exception: an entry the family has taken back since it was carried
     * always counts, whatever its id. That is a marked entry that is blank
     * (withdrawn), or that holds less than the class it was carried from
     * (reduced). A single move passes null and counts every entry.
     *
     * `$classBeingLeft` is a whole-class preview's or run's too, and null for
     * a single move. WHEN THE ADULT HAS NO CURRENT ENTRY THERE, an entry of
     * theirs that has LEFT counts after all, if the child it names is in the
     * class being left today: that brother or sister may go back in the same
     * act, and their entry opens again with whatever it holds. Without this
     * the order of the list decided it: with the returning child first the
     * re-opened blank entry capped the carry, and with the other child first
     * the consent was carried and the blank entry opened beside it a moment
     * later, with nothing said. Now both orders, and the preview before them,
     * give the same answer. Among several such entries the NARROWEST counts,
     * because any one of them may be the only one that comes back; and the
     * second value of the answer says the standing is of this kind, so the
     * sentence does not say the adult "is already in" a class they are not in.
     * It errs towards less: a brother who is not ticked, or cannot move,
     * still caps.
     *
     * @param  Collection<int, mixed>  $adultContactIds
     * @return array<int, array{0: string, 1: bool}> contact id => [`none`, `feed` or `media`, held on an entry that would re-open]
     */
    protected function standingForAnotherChild(Group $to, int $studentId, Collection $adultContactIds, ?int $standingBeforeId, ?int $classBeingLeft = null): array
    {
        $entries = GroupMembership::query()
            ->where('group_id', $to->getKey())
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->whereIn('contact_id', $adultContactIds->unique()->values())
            ->where('guardian_of_contact_id', '!=', $studentId)
            ->confirmed()
            ->where(fn ($counted) => $counted
                ->whereNull('left_on')
                ->when($classBeingLeft !== null, fn ($query) => $query->orWhereIn(
                    'guardian_of_contact_id',
                    GroupMembership::query()->select('contact_id')
                        ->where('group_id', $classBeingLeft)
                        ->where('role', GroupMembership::ROLE_MEMBER)
                        ->whereNull('left_on'),
                )))
            ->orderBy('id')
            ->get();

        $current = $entries->filter(fn (GroupMembership $e): bool => $e->left_on === null);

        if ($standingBeforeId !== null) {
            $late = $current->filter(fn (GroupMembership $e): bool => (int) $e->getKey() > $standingBeforeId);
            $takenBack = self::takenBackSinceCarried($late);

            $current = $current->reject(
                fn (GroupMembership $e): bool => (int) $e->getKey() > $standingBeforeId && ! in_array((int) $e->getKey(), $takenBack, true),
            );
        }

        $level = fn (GroupMembership $e): string => $e->hasConsent() ? (string) $e->consent_scope : 'none';
        $holds = [];

        foreach ($current as $entry) {
            $adult = (int) $entry->contact_id;

            if (! isset($holds[$adult]) || self::CONSENT_RANK[$level($entry)] > self::CONSENT_RANK[$holds[$adult][0]]) {
                $holds[$adult] = [$level($entry), false];
            }
        }

        foreach ($entries as $entry) {
            $adult = (int) $entry->contact_id;

            // Only for an adult with no current entry there: one who has, is
            // judged on what they hold today.
            if ($entry->left_on === null || ($holds[$adult][1] ?? true) === false) {
                continue;
            }

            if (! isset($holds[$adult]) || self::CONSENT_RANK[$level($entry)] < self::CONSENT_RANK[$holds[$adult][0]]) {
                $holds[$adult] = [$level($entry), true];
            }
        }

        return $holds;
    }

    /**
     * WHICH OF THESE ENTRIES HOLD A CARRIED CONSENT THE FAMILY HAS SINCE TAKEN
     * BACK, wholly or in part: marked, and either blank (withdrawn here) or in
     * force for less than the entry in the marked class holds
     * (`GroupMembership::holdsLessThanCarriedFrom`). One query for the entries
     * they were carried from, and only when a marked entry holds something.
     *
     * Read by the sibling rule above (such an entry caps whatever its id) and
     * by the roster list and the consent answer, which say it on the screen.
     *
     * @param  Collection<int, GroupMembership>  $entries
     * @return list<int> roster row ids
     */
    public static function takenBackSinceCarried(Collection $entries): array
    {
        $marked = $entries->filter(
            fn (GroupMembership $e): bool => $e->isGuardian() && $e->{GroupMembership::CONSENT_CARRIED_FROM} !== null,
        );

        $withdrawn = $marked->reject(fn (GroupMembership $e): bool => $e->consentColumnsAreSet());

        return $withdrawn->concat(self::holdingLessThanCarried($marked))
            ->map(fn (GroupMembership $e): int => (int) $e->getKey())->unique()->values()->all();
    }

    /**
     * Of these entries, the ones whose carried consent is in force for LESS
     * than the entry it was carried from holds now. The sources are found by
     * class, adult and child (one row each by the unique index), in one query,
     * with the organisation named so it holds where no tenant is bound.
     *
     * @param  Collection<int, GroupMembership>  $entries
     * @return Collection<int, GroupMembership>
     */
    public static function holdingLessThanCarried(Collection $entries): Collection
    {
        $carried = $entries->filter(
            fn (GroupMembership $e): bool => $e->isGuardian()
                && $e->{GroupMembership::CONSENT_CARRIED_FROM} !== null
                && $e->hasConsent(),
        )->values();

        if ($carried->isEmpty()) {
            return $carried;
        }

        $key = fn (int $class, GroupMembership $e): string => $class.':'.(int) $e->contact_id.':'.(int) $e->guardian_of_contact_id;

        $sources = GroupMembership::query()
            ->whereIn('masjid_id', $carried->pluck('masjid_id')->unique()->values())
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->whereIn('group_id', $carried->pluck(GroupMembership::CONSENT_CARRIED_FROM)->unique()->values())
            ->whereIn('contact_id', $carried->pluck('contact_id')->unique()->values())
            ->whereIn('guardian_of_contact_id', $carried->pluck('guardian_of_contact_id')->unique()->values())
            ->get()
            ->keyBy(fn (GroupMembership $e): string => $key((int) $e->group_id, $e));

        return $carried->filter(fn (GroupMembership $e): bool => $e->holdsLessThanCarriedFrom(
            $sources->get($key((int) $e->{GroupMembership::CONSENT_CARRIED_FROM}, $e)),
        ))->values();
    }

    /**
     * WHAT THE CLASS BEING ENTERED HOLDS, for the sentences that say what a
     * carried consent opens: its other current students, the published
     * stories it still keeps, and how many of those carry a photograph or a
     * video (a story's attachments are nothing else).
     *
     * The stories, not only the students: a class that was just emptied by its
     * own move-up has no current student and up to a year of story, of
     * children whose families are no longer in it to notice.
     *
     * Three counts that are the same for every student moving into one class,
     * so a preview remembers them for the length of the request (a whole-class
     * preview asks once, not once per student). The move's own decision, under
     * its locks, always counts again.
     *
     * @return array{others: int, stories: int, with_media: int}
     */
    protected function whatTheClassHolds(Group $to, bool $remembered): array
    {
        $id = (int) $to->getKey();

        if ($remembered && isset($this->classHolds[$id])) {
            return $this->classHolds[$id];
        }

        $stories = fn () => GroupPost::query()->where('group_id', $id)->published();

        return $this->classHolds[$id] = [
            'others' => GroupMembership::query()
                ->where('group_id', $id)
                ->where('role', GroupMembership::ROLE_MEMBER)
                ->whereNull('left_on')
                ->count(),
            'stories' => $stories()->count(),
            'with_media' => $stories()->whereHas('attachments')->count(),
        ];
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
     * THE CLASS STORE SEAM: does the old row hold Manara Bucks that stay
     * behind?
     *
     * A boolean, never a figure: the office reads class totals only. It
     * answers whether ledger rows exist, and only for a school that holds the
     * class store; it reads no balance and writes nothing.
     *
     * STILL TRUE TODAY, AND DUE TO GO. The owner has decided that a balance
     * follows the student (2026-10-04), and the writer for it exists:
     * `App\Support\ClassStore::carryBalance`, which nothing calls yet. The
     * commit that makes `write()` call it removes this method, `bucksStaying`
     * and the sentence "They stay there for now", sets the plan's `bucksRule`,
     * and widens `ready()` to the ledger's own column
     * (`ClassStore::carryReady()`). Until then a move leaves the balance on
     * the old row and says so, and this boolean differs child by child, which
     * a whole-class preview prints for every child at once: one more reason
     * the class store stays off until that commit.
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
     * the new class, on a return the joining day, and the consent result
     * (`RosterMovePlan::fingerprintOfConsent()`). The preview is not locked: a
     * register saved, or a consent recorded or withdrawn, between the read and
     * the tap can change any of them. An expectation that is absent is not
     * checked.
     *
     * WITH ONE EXCEPTION, the single verb's (`consent_must_be_echoed`): a
     * request that says nothing about consent may not CARRY any. The dialog
     * always echoes what it showed, so a body without it comes from a page
     * that was opened before a move carried consent. That page's sentence was
     * "Consent ... does not move. Record it again ...", and a tap on it that
     * carried a consent would do what the screen said it would not. It is
     * told to reload, in its own sentence: "look again" would loop, because
     * that page can never send the echo. A move that carries nothing is not
     * held up.
     *
     * `expected_bucks_rule` arrives here too and is not compared yet: the
     * plan's `bucksRule` is null until a move can carry Manara Bucks.
     *
     * @param  array<string, mixed>  $options
     */
    private function refuseWhenNotWhatWasShown(RosterMovePlan $plan, array $options): void
    {
        $path = $options['expected_path'] ?? null;
        $firstDay = $options['expected_first_day'] ?? null;
        $joinedOn = $options['expected_joined_on'] ?? null;
        $consent = $options['expected_consent'] ?? null;

        if ($consent === null && ($options['consent_must_be_echoed'] ?? false) && $plan->consentToCarry !== []) {
            throw RosterMoveRefused::conflict(self::pageIsOlderThanTheCarry($plan->student));
        }

        if (($path !== null && $path !== $plan->path)
            || ($firstDay !== null && $firstDay !== $plan->firstDay)
            || ($plan->path === RosterMovePlan::RETURNED && $joinedOn !== null && $joinedOn !== $plan->joinedOn)
            || ($consent !== null && $consent !== $plan->consentFingerprint)) {
            throw RosterMoveRefused::lookAgain();
        }
    }

    /** What a page that cannot say what it showed about consent is told. */
    public static function pageIsOlderThanTheCarry(string $student): string
    {
        return "Manara has been updated since this page was opened, and a move now carries each guardian's consent "
            ."with the student. Nothing was moved. Reload this page, read what will happen, then move {$student} again.";
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
        // same first day as the student, and with its consent as it was
        // recorded only when the decision put it in the plan's `consentToCarry`.
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

            $copy = $this->carry($entry, $to, $plan->firstDay, in_array((int) $entry->getKey(), $plan->consentToCarry, true));
            $copy->save();
            $plan->guardianEntriesCarried[] = (int) $copy->getKey();

            if ($copy->consentColumnsAreSet()) {
                $plan->consentEntriesCarried[] = [(int) $copy->getKey(), (int) $entry->getKey()];
            }
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
     *
     * `$withConsent` is false for the student's own place and for every
     * guardian entry the decision did not name. The row is built from its six
     * named attributes and never from the old row's: both consent columns are
     * fillable, and consent passed in that array would stay on a row that
     * never reached the copy.
     */
    protected function carry(GroupMembership $old, Group $to, string $on, bool $withConsent = false): GroupMembership
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
        $new->carriedFrom($old, $withConsent);

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

    /**
     * A run's `standing_before_id`, or null.
     *
     * @param  array<string, mixed>  $options
     */
    private static function standingBefore(array $options): ?int
    {
        return isset($options['standing_before_id']) ? (int) $options['standing_before_id'] : null;
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
