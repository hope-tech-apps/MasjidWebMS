<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A roster edit must never be able to destroy a child's academic record.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FIXES, MEASURED
 * ---------------------------------------------------------------------------
 *
 * `group_memberships` does not soft-delete, and six academic tables carried
 * `ON DELETE CASCADE` against it. So `GroupMembershipsController::destroy()` —
 * the ordinary "remove from the roster" button — hard-deleted the row and took
 * with it every attendance record, every assignment score, every report card
 * and its marks, every ḥifẓ entry, every behaviour award and every Arabic
 * letter-progress row for that child in that class.
 *
 * Reproduced 2026-09-09 on a seeded child: one attendance record and one report
 * card, one `$membership->delete()`, both gone. The controller's own success
 * message says "Removed from the roster."
 *
 * What makes it worse is the care taken beside it: that method counts the
 * GUARDIAN EDGES it will cascade and warns about parent logins it would strand.
 * The attention was on the family graph, and nobody revisited it when
 * attendance, the gradebook and report cards were built on top of the same
 * foreign key.
 *
 * ---------------------------------------------------------------------------
 * RESTRICT, NOT CASCADE — and deliberately not soft deletes
 * ---------------------------------------------------------------------------
 *
 * The obvious fix is `SoftDeletes` on GroupMembership. It is the wrong tool
 * here: `group_memberships_edge_unique` spans
 * (group_id, contact_id, role, guardian_of_contact_id) and adding a nullable
 * `deleted_at` to that index would DESTROY the uniqueness guarantee for live
 * rows, because MySQL treats NULLs as distinct — two identical live edges would
 * both be admitted. Leaving the index alone instead makes re-enrolling a
 * previously-removed child collide with the trashed row.
 *
 * So the database simply refuses. A membership that holds academic history can
 * no longer be deleted by any code path — this one, a future one, or a console
 * command written at midnight. The controller checks first and explains; this
 * constraint is what makes the check impossible to bypass rather than merely
 * impolite to skip.
 *
 * A membership with NO records still deletes cleanly, which is the common
 * legitimate case: a mis-typed enrolment removed the same afternoon.
 *
 * `group_threads.about_membership_id` is deliberately untouched — it is already
 * SET NULL, which is right for a conversation that outlives the roster row it
 * was about.
 *
 * Constraint names are hardcoded from the LIVE production schema rather than
 * derived, because a name guessed wrong fails this migration halfway with the
 * first tables already altered. See .claude/rules/migrations.md.
 */
return new class extends Migration
{
    /**
     * table => foreign key constraint name, read from information_schema on
     * production 2026-09-09.
     */
    private const CASCADES = [
        'arabic_letter_progress' => 'arabic_letter_progress_group_membership_id_foreign',
        'assignment_scores' => 'assignment_scores_group_membership_id_foreign',
        'attendance_records' => 'attendance_records_group_membership_id_foreign',
        'behavior_awards' => 'behavior_awards_group_membership_id_foreign',
        'hifz_entries' => 'hifz_entries_group_membership_id_foreign',
        'report_cards' => 'report_cards_group_membership_id_foreign',
    ];

    public function up(): void
    {
        // SQLite cannot ALTER a foreign key, and rebuilding six tables under a
        // test database buys nothing: the suite asserts the CONTROLLER refuses,
        // which is the behaviour that protects a real school either way.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::CASCADES as $table => $constraint) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function ($t) use ($constraint) {
                $t->dropForeign($constraint);
            });

            Schema::table($table, function ($t) {
                $t->foreign('group_membership_id')
                    ->references('id')->on('group_memberships')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::CASCADES as $table => $constraint) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function ($t) {
                $t->dropForeign(['group_membership_id']);
            });

            Schema::table($table, function ($t) {
                $t->foreign('group_membership_id')
                    ->references('id')->on('group_memberships')
                    ->cascadeOnDelete();
            });
        }
    }
};
