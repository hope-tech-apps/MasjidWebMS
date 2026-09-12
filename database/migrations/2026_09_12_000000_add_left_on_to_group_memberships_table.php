<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A child who has LEFT — the third state a roster row has always needed.
 *
 * ---------------------------------------------------------------------------
 * WHAT A SCHOOL COULD DO BEFORE THIS, AND WHY BOTH ANSWERS WERE WRONG
 * ---------------------------------------------------------------------------
 *
 * A student was either on a roster or their row was hard-deleted. There was no
 * third state, so a family withdrawing mid-year left the office choosing
 * between two bad options:
 *
 *   - LEAVE THE ROW. The child keeps appearing on tomorrow's register, in the
 *     gradebook, in the points and ḥifẓ screens and in the report-card list,
 *     because every one of those reads filters on ROLE alone
 *     (`GroupMembership::scopeParticipants`). A teacher marks a class of 21 for
 *     a child who left in October.
 *   - REMOVE THE ROW. Since 2026-09-09 that is refused outright for any child
 *     holding academic history, and rightly so — the same verb used to delete
 *     the register marks, the report cards and the ḥifẓ entries with it
 *     (`2026_09_09_040000_stop_roster_edits_destroying_academic_records`). For a
 *     child with no records it succeeds, and then it is irreversible and takes
 *     each parent's guardian edge, its consent date and its provenance with it.
 *
 * So the state that every school actually needs — "she left on this date; keep
 * everything" — could not be expressed at all.
 *
 * ---------------------------------------------------------------------------
 * A DATE, NOT A FLAG, AND NOT A SOFT DELETE
 * ---------------------------------------------------------------------------
 *
 * `left_on` is a DATE because a school's own question is "when did she leave?"
 * — it goes on transfer letters, it bounds a register, and it is what a parent
 * asks about. A boolean would answer none of that and would have to be
 * explained in prose somewhere else.
 *
 * NULL means still enrolled. The column is additive and every existing row
 * keeps its meaning, which obligation "additive, no destructive migration" in
 * .claude/rules/groups.md requires of anything built on these rosters.
 *
 * `SoftDeletes` is the wrong tool here for the reason the 2026-09-09 migration
 * already records: `group_memberships_edge_unique` spans
 * (group_id, contact_id, role, guardian_of_contact_id), and a nullable
 * `deleted_at` inside that index would destroy uniqueness for LIVE rows because
 * MySQL treats NULLs as distinct. `left_on` is deliberately NOT part of that
 * index, so a child who leaves and comes back is the SAME row returning — the
 * date is cleared — rather than a second edge colliding with a trashed one.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES NOT CHANGE
 * ---------------------------------------------------------------------------
 *
 * Every record keyed on the membership id stays exactly where it is: the row
 * survives, so attendance, marks, report cards, ḥifẓ entries, behaviour points
 * and letter progress all keep resolving. Deletion stays refused while records
 * exist; this column is the alternative to deleting, never a step towards it.
 *
 * `left_recorded_by_user_id` carries WHO, on the same reasoning as
 * `confirmed_by_user_id`: an administrator leaving the organisation must not
 * erase the rosters they kept, and a console or seeder path has no `users` row
 * to name.
 *
 * It is a PLAIN COLUMN with no foreign key, unlike its 2026-08-19 neighbour.
 * Adding an FK to an EXISTING table makes SQLite rebuild the whole table, and a
 * rebuild silently drops WHERE-clause indexes — this repo has already lost a
 * partial unique index that way and spent days on the seven unrelated tests it
 * broke. The newest migration in the tree states the same rule
 * (2026_09_11_120000). Nothing here reads the column as a relation; it is
 * evidence of who typed the date, and a deleted administrator leaves a dangling
 * id that reads as "an actor we can no longer name", which is exactly what
 * `nullOnDelete` would have produced anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->date('left_on')->nullable()->after('joined_at');

            $table->unsignedBigInteger('left_recorded_by_user_id')->nullable()
                ->after('left_on');

            // "The CURRENT roster of this class" — the read every teacher screen
            // makes, now that they all exclude the departed. Leading with
            // masjid_id keeps it usable by the tenant-scoped reads that are the
            // only ones this application makes, matching the provenance index
            // beside it. Named by hand: MySQL's identifier limit is 64
            // characters and a derived name here would be close to it
            // (.claude/rules/migrations.md).
            $table->index(['masjid_id', 'group_id', 'left_on'], 'group_memberships_left_on_idx');
        });
    }

    public function down(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            // The index first: SQLite refuses to drop a column an index names.
            $table->dropIndex('group_memberships_left_on_idx');
            $table->dropColumn(['left_recorded_by_user_id', 'left_on']);
        });
    }
};
