<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * assignment_scores — one child's mark on one piece of work.
 *
 * ## NOT-YET-SCORED IS THE ABSENCE OF THE ROW
 *
 * There is no fourth status for it, and emphatically no 0. A blank cell and a
 * zero are different facts about a child: one says the teacher has not marked it
 * yet, the other says they got nothing right. The register makes the same
 * distinction for the same reason — an untaken day is not a room full of
 * absences — and conflating them is the kind of error a parent notices first.
 *
 * ## Keyed on group_membership_id, never contact_id
 *
 * GroupAudience decides who may read a record about a child by reading the
 * SUBJECT ROW: it checks the membership's role against PARTICIPANT_ROLES and its
 * group_id against the group, and the query half does `whereHas('membership')`.
 * Its contract is that every model handed to it exposes `membership()`. A
 * contact_id would make "a score about a GUARDIAN" expressible rather than
 * refusable, and would break the cascade that bounds a child's marks to their
 * enrolment.
 *
 * ## The dangling-reference rule, restated because it is load-bearing
 *
 * `class_assignments` soft-deletes, so this table's FK cascades only on a FORCE
 * delete. A withdrawn assignment therefore leaves live score rows pointing at a
 * row that no longer resolves. NO SCORE QUERY MAY START FROM THIS TABLE ALONE —
 * every read joins the assignment or uses `whereHas('assignment')` and inherits
 * its SoftDeletes scope. Pinned by a test.
 *
 * No soft deletes here: a cell is CORRECTED IN PLACE, exactly as a register mark
 * is. The unique index is what makes that correction idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Denormalised beside the membership so one class's marks scope
            // without joining through group_memberships — the same reasoning
            // that put group_id on behavior_awards, hifz_entries and
            // attendance_records.
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            $table->foreignId('class_assignment_id')
                ->constrained('class_assignments')->cascadeOnDelete();

            // THE STUDENT.
            $table->foreignId('group_membership_id')
                ->constrained('group_memberships')->cascadeOnDelete();

            $table->foreignId('scored_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // scored | missing | excused. PHP constants on AssignmentScore, not
            // a DB enum — adding a fourth must be a write, never an ALTER TABLE
            // on a live table (.claude/rules/migrations.md).
            //
            // The three are kept apart because they answer differently:
            // `missing` counts zero in the numerator and FULL in the
            // denominator; `excused` is excluded from BOTH. Collapsing them
            // would punish a child whose absence the office had accepted.
            $table->string('status', 16);

            // NULL unless status = scored. Decimal because half marks are
            // ordinary in a primary classroom and an integer column would round
            // 4.5 into an argument with a parent.
            $table->decimal('points_earned', 6, 2)->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            // One row per child per assignment: what makes Save idempotent when
            // a teacher taps twice on a bad connection.
            $table->unique(['class_assignment_id', 'group_membership_id'], 'gradebook_score_unique');

            // One child's marks across the term. The whole-class read is already
            // served by gradebook_score_unique, which leads on
            // class_assignment_id — so there is deliberately no third index.
            $table->index(['masjid_id', 'group_membership_id'], 'gradebook_student_scores_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_scores');
    }
};
