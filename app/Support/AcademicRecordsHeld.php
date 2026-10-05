<?php

namespace App\Support;

use App\Models\GroupMembership;
use Illuminate\Support\Facades\DB;

/**
 * WHAT A CHILD'S ROSTER ROW IS HOLDING — one definition, for every caller that
 * might delete one.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLASS AND NOT A PRIVATE METHOD
 * ---------------------------------------------------------------------------
 *
 * Seven tables hung off `group_memberships.id` when this was written (eleven
 * keys are listed today, see the last section): the register, assignment scores,
 * report cards, ḥifẓ entries, behaviour awards, Arabic letter progress and (since T-003.4,
 * created RESTRICT from the start) the Manara Bucks ledger. Until
 * 2026_09_09_040000 those foreign keys were ON DELETE CASCADE, so any verb that
 * removed a roster row also destroyed a term of a child's academic history —
 * reproduced on a seeded child: one attendance row and one report card, one
 * delete, both gone. That migration made the six keys RESTRICT, and
 * `GroupMembershipsController::destroy()` grew a check that counts them first so
 * the office gets a sentence naming what is in the way rather than a constraint
 * violation.
 *
 * The migration returns early on SQLite (it cannot rebuild those tables safely),
 * so the CI suite runs against the ORIGINAL cascading keys. That is the whole
 * reason this list must exist exactly once: on the CI engine the database will
 * happily delete the history, so a second caller that forgot one of the six
 * tables — or that never checked at all — would pass every test and quietly
 * erase a child's marks on production MySQL, or die on a 1451 there. A private
 * method on one controller is an invitation to that second, forgetful copy.
 *
 * The counts are LABELLED because they are read out to a human. "42 register
 * marks, 2 report cards" is a sentence an office can act on; a boolean is not.
 *
 * ---------------------------------------------------------------------------
 * ELEVEN KEYS, ONE LIST, TWO QUESTIONS (2026-10-04)
 * ---------------------------------------------------------------------------
 *
 * The list above was seven tables. Four more foreign keys point at a roster row
 * and were not on it: Arabic daily notes and the recipients of an addressed
 * file (both CASCADE in the database), and a conversation or a scheduled
 * message ABOUT a student (both SET NULL).
 *
 * `KEYS` is now every foreign key into `group_memberships.id`, and it is the
 * one list. It answers two different questions, and each tuple says which:
 *
 *   1. WHAT STAYS WITH THE OLD CLASS WHEN A STUDENT IS MOVED: all eleven
 *      (`counts()`). `App\Support\RosterMove` reads them out to the office.
 *   2. WHAT A DELETE OF THE ROSTER ROW WOULD DESTROY: eight of them, the seven
 *      RESTRICT kinds plus Arabic daily notes (`blocking()`). Remove
 *      (`GroupMembershipsController::destroy`) and the undo of a roster import
 *      (`RosterImportService::rollback`), the two roster deleters, refuse on
 *      these and on nothing else.
 *
 * The other three do NOT refuse a removal, on purpose. A conversation survives
 * its roster row ("the record survives, the audience shrinks"), a scheduled
 * message loses its subject and is then not sent, and a recipient row of an
 * addressed file "cascades because its entire meaning is the roster row it
 * names" (.claude/rules/groups.md). Refusing on them would also block the
 * office behind things it cannot see or clear: a deleted conversation or a
 * cancelled message keeps its row for up to a year.
 *
 * Arabic daily notes ARE on the refusing side although their key cascades: a
 * removal used to delete a teacher's notes without a word. The database rule
 * stays as it is, as the net under any other deleter.
 *
 * A roster row NEVER changes class, whatever it holds: ten of these tables
 * carry their own `group_id` and the eleventh names its class through its file,
 * so a record whose class disagreed with its roster row could be opened from
 * neither. A move leaves the row where it is and opens another.
 *
 * `RosterMoveTest` walks the schema and fails when a twelfth key appears that
 * is not listed here.
 */
final class AcademicRecordsHeld
{
    /**
     * Every foreign key into `group_memberships.id`:
     * table => [column, the label read to a human, the on-delete rule on MySQL,
     *           whether deleting the roster row would DESTROY these records].
     *
     * The first seven keep their labels and their order: the refusal sentence
     * Remove has always printed is built from them.
     *
     * Ten of these tables carry their own `group_id`. `group_resource_recipients`
     * names its class through its file (`group_resources.group_id`).
     *
     * NOT EVERY REFERENCE TO A ROSTER ROW IS A FOREIGN KEY. Two live inside
     * strings, where no schema walk can see them, and neither needs a count:
     * the child-mode token's ability `student:{id}` (it lapses within the hour
     * and opens the child's name and avatar only), and the class store's
     * `dedupe_key` (`earned:{id}:{week}` and its siblings), which always sits
     * on a ledger row that IS counted here through its own key.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const KEYS = [
        'attendance_records' => ['group_membership_id', 'register marks', 'restrict', true],
        'assignment_scores' => ['group_membership_id', 'marks', 'restrict', true],
        'report_cards' => ['group_membership_id', 'report cards', 'restrict', true],
        'hifz_entries' => ['group_membership_id', 'ḥifẓ entries', 'restrict', true],
        'behavior_awards' => ['group_membership_id', 'behaviour points', 'restrict', true],
        'arabic_letter_progress' => ['group_membership_id', 'letter progress', 'restrict', true],
        // T-003.4: what a child earned and spent is theirs too.
        'prize_ledger_entries' => ['group_membership_id', 'Manara Bucks', 'restrict', true],
        // CASCADE in the database, and still a teacher's writing about a child.
        'arabic_daily_notes' => ['group_membership_id', 'Arabic daily notes', 'cascade', true],
        'group_resource_recipients' => ['group_membership_id', 'files addressed to them', 'cascade', false],
        'group_threads' => ['about_membership_id', 'conversations', 'set null', false],
        // RAW ON PURPOSE, hence "any state". This table keeps a row after its
        // message went out or was cancelled, and that row still points at the
        // roster row. How many messages will NOT go out is a narrower count,
        // and it is not this one (RosterMove::scheduledMessagesStopping).
        'group_message_schedules' => ['about_membership_id', 'scheduled messages (any state)', 'set null', false],
    ];

    /**
     * The tables in KEYS that soft-delete. Their rows are counted even when
     * deleted (the key still points at the roster row), so a refusal can be
     * about records nobody can see on a screen, and it has to say so.
     * `RosterMoveTest` pins this list against the schema.
     */
    public const SOFT_DELETED = ['hifz_entries', 'behavior_awards', 'group_threads'];

    /**
     * The ledger's label. The class store's money is the class's and the
     * family's to read; the office reads class totals only
     * (.claude/rules/groups.md, "Class store"). So the sentences a MOVE prints
     * leave this kind out, and what they say about Bucks is never counted from
     * one child's ledger.
     */
    public const LEDGER_LABEL = 'Manara Bucks';

    /**
     * What a REFUSAL prints for the ledger, with no number. The count of ledger
     * rows reads as a balance ("1 Manara Bucks"), and since a move writes one
     * row on the place a student is moved into, "1" there would say that this
     * child held Bucks when they were moved. The words say what is in the way
     * and nothing about how much.
     */
    public const LEDGER_HISTORY = 'Manara Bucks history';

    /** One of each, for a sentence that counts: "1 register mark". */
    private const SINGULAR = [
        'register marks' => 'register mark',
        'marks' => 'mark',
        'report cards' => 'report card',
        'ḥifẓ entries' => 'ḥifẓ entry',
        'behaviour points' => 'behaviour point',
        'Arabic daily notes' => 'Arabic daily note',
        'files addressed to them' => 'file addressed to them',
        'conversations' => 'conversation',
        'scheduled messages (any state)' => 'scheduled message (any state)',
    ];

    /**
     * How many records point at this roster row, by what they are: all eleven.
     *
     * DERIVED from KEYS, and counted on the RAW TABLES. A model query would hide
     * a soft-deleted award, ḥifẓ entry or conversation while its key still
     * points at the roster row, and a tenant scope could hide a row the
     * database will still refuse to orphan.
     *
     * @return array<string, int>
     */
    public static function counts(GroupMembership $membership): array
    {
        $id = $membership->id;
        $held = [];

        foreach (self::KEYS as $table => [$column, $label]) {
            $held[$label] = DB::table($table)->where($column, $id)->count();
        }

        return $held;
    }

    /**
     * The part of `counts()` a DELETE of the roster row would destroy: what the
     * two roster deleters refuse on. Same labels, same order, fewer kinds.
     *
     * @param  array<string, int>  $held
     * @return array<string, int>
     */
    public static function blocking(array $held): array
    {
        $blocking = [];

        foreach (self::KEYS as [, $label, , $destroyed]) {
            if ($destroyed && array_key_exists($label, $held)) {
                $blocking[$label] = $held[$label];
            }
        }

        return $blocking;
    }

    /**
     * Is every record that blocks a removal of this row a DELETED one?
     *
     * `counts()` is raw, so a behaviour point somebody took back or a ḥifẓ
     * entry that was corrected away still refuses a removal. The office cannot
     * see either on any screen, so the refusal has to say that this is what is
     * in the way.
     */
    public static function onlyDeleted(GroupMembership $membership): bool
    {
        $id = $membership->id;
        $deleted = 0;

        foreach (self::KEYS as $table => [$column, , , $destroyed]) {
            if (! $destroyed) {
                continue;
            }

            $query = DB::table($table)->where($column, $id);

            if (! in_array($table, self::SOFT_DELETED, true)) {
                if ($query->exists()) {
                    return false;
                }

                continue;
            }

            if ((clone $query)->whereNull('deleted_at')->exists()) {
                return false;
            }

            $deleted += $query->count();
        }

        return $deleted > 0;
    }

    /**
     * Why a removal is refused, and what to do instead: the one sentence the
     * two roster deleters print after the records in brackets.
     *
     * "Left the class" is the way out because it keeps the row, and the row is
     * what the records hang off.
     */
    public static function refusalAdvice(GroupMembership $membership): string
    {
        return self::onlyDeleted($membership)
            ? 'Those records were deleted and are no longer shown on any screen, but they are still kept, '
                . 'and removing the roster entry would destroy them. Use "Left the class" instead: it takes '
                . 'them off the class lists and keeps the history.'
            : 'Removing the roster entry would delete those records. Use "Left the class" instead: it takes '
                . 'them off the class lists and keeps the history.';
    }

    /** Whether this row holds any history at all. */
    public static function any(array $held): bool
    {
        return array_sum($held) > 0;
    }

    /**
     * "42 register marks, 2 report cards" — only the kinds that are non-zero.
     * The class store's ledger is named WITHOUT its count ("Manara Bucks
     * history"): see LEDGER_HISTORY.
     */
    public static function describe(array $held): string
    {
        $parts = [];

        foreach ($held as $label => $n) {
            if ($n > 0) {
                $parts[] = $label === self::LEDGER_LABEL ? self::LEDGER_HISTORY : "{$n} {$label}";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * The same phrase for the sentences a MOVE prints: one of a thing is
     * singular ("1 register mark"), and the class store's ledger is left out,
     * because the office is not shown a figure about one child's Bucks.
     * `describe()` above is what Remove prints; it names the ledger too, and
     * gives no number for it either.
     */
    public static function describeForPeople(array $held): string
    {
        $parts = [];

        foreach ($held as $label => $n) {
            if ($n <= 0 || $label === self::LEDGER_LABEL) {
                continue;
            }

            $parts[] = $n . ' ' . ($n === 1 ? (self::SINGULAR[$label] ?? $label) : $label);
        }

        return implode(', ', $parts);
    }
}
