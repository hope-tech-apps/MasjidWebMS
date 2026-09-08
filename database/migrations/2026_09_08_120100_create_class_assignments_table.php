<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * class_assignments — the thing a score is a score OF.
 *
 * Named `class_assignments`, not `assignments`: "assignment" already means a
 * STAFF POSTING in this codebase (GroupStaff assigns a teacher to a class), and
 * two meanings of one word in one module is how a query gets written against
 * the wrong table.
 *
 * ## How a gradebook differs from the trackers beside it
 *
 * The Arabic letter tracker and the ḥifẓ log are MASTERY records: a child either
 * knows a letter or does not, and a position in the muṣḥaf is not a mark out of
 * ten. This table is the other kind — a discrete piece of work, given to the
 * whole class on a day, carrying a maximum. Neither replaces the other, and a
 * gradebook must not try to express mastery (that is the letter tracker's job)
 * nor a percentage of the Qur'an (which HifzProgress refuses on purpose).
 *
 * ## Soft deletes, and what they mean here
 *
 * `deleted_at` IS the withdrawal clock: a teacher who sets an assignment and
 * then drops it must not destroy the marks already entered against it, because
 * a parent conversation may already have happened about one. Withdrawn work
 * stops counting and stops showing; it does not evaporate.
 *
 * THE CONSEQUENCE, which the score table's docblock repeats: a soft-deleted
 * assignment leaves its `assignment_scores` rows pointing at a row that no
 * longer resolves. No score query may start from `assignment_scores` alone —
 * every read joins the assignment (or `whereHas('assignment')`) and inherits
 * this SoftDeletes scope. That is exactly the dangling-reference incident
 * .claude/rules/groups.md records for `offerings.group_id`.
 *
 * No `scored` / `is_published` / `average` column: all three are derived, and a
 * stored copy is a second source of truth that goes stale the first time a score
 * is corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 200);

            // The maximum. NOT NULL and > 0 at the request boundary — a mark out
            // of nothing has no meaning. Deliberately NOT snapshotted onto each
            // score: unlike behavior_awards (which snapshots because a
            // BehaviorSkill is reusable vocabulary that may be renamed later),
            // this maximum belongs to exactly ONE assignment, so correcting it
            // SHOULD move every score on it.
            $table->unsignedSmallInteger('points_possible');

            // The day the work was set. A DATE, not a TIMESTAMP: the same reason
            // attendance_records.session_date is one, and it keeps this table
            // free of the MariaDB explicit_defaults_for_timestamp trap.
            $table->date('assigned_on');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['masjid_id', 'group_id', 'assigned_on'], 'gradebook_class_assigned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_assignments');
    }
};
