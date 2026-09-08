<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lesson_plans — what a class is going to cover on a given day.
 *
 * ONE PLAN PER CLASS PER DAY, enforced by the unique index. That is what lets
 * every route address a plan by (class, date) and carry no {plan_id} at all: a
 * teacher opening Tuesday either finds Tuesday's plan or an empty form, and
 * saving is an upsert. There is no list of drafts to reconcile.
 *
 * ## Why this is NOT shaped like the register
 *
 * `session_date` here is normally in the FUTURE — the whole point is planning —
 * where attendance_records.session_date is bounded at today. The mirror-image
 * rule lives in SaveLessonPlanRequest, and copying the register's
 * `before_or_equal:today` into it would forbid the only thing this table is for.
 *
 * ## What is deliberately absent
 *
 *   - NO group_membership_id. A lesson plan is about a ROOM, never about a
 *     child. GroupAudience decides disclosure by reading a `membership`
 *     relation off the subject row; giving this table one would make "a plan
 *     about a child" expressible rather than refusable.
 *   - NO soft deletes. This holds no child's record and no bytes, so there is
 *     nothing to retain. Soft deletes would also collide with the per-day unique
 *     index — two plans for one day, one trashed, is a duplicate-key error on
 *     MySQL unless the index moves to a STORED generated column
 *     (.claude/rules/migrations.md). Not worth it for a teacher's own note.
 *   - NOT registered in `groups:purge-feed`. That sweep exists for records ABOUT
 *     children; this is the teacher's note about their own room.
 *   - NO second index. The unique key IS the read key
 *     (WHERE group_id = ? AND session_date BETWEEN ? AND ?). attendance_records
 *     needed a second one only because its unique key is (membership, date)
 *     while its read is (group, date); do not add one here by symmetry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // Who wrote it. nullOnDelete for the reason every other authored row
            // in this module uses: retiring a teacher's login must not erase a
            // term of planning.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('session_date');

            // Nullable for the same reason group_posts.title is: a plan is
            // often just the body, and forcing a heading produces headings
            // nobody reads.
            $table->string('title')->nullable();

            // NOT NULL: a plan with no body is not a plan. It is also why
            // destroy() exists — a plan typed on the wrong day cannot be
            // blanked, so it must be removable.
            $table->text('body');

            $table->timestamps();

            // Hand-named. Laravel's generated name would be
            // lesson_plans_group_id_session_date_unique (42 chars) which fits,
            // but every composite index in this branch is named explicitly:
            // MySQL refuses anything over 64 characters and SQLite does not,
            // so a generated name is only ever verified in production.
            $table->unique(['group_id', 'session_date'], 'lesson_plan_class_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_plans');
    }
};
