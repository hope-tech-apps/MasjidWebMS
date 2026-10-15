<?php

use App\Models\LessonPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One lesson plan per class, per day, per subject, PER GRADE.
 *
 * A combined-grade class (Pre-K with Kindergarten, say) teaches the same
 * subject to each of its grades on one day, from each grade's own page of the
 * pacing guide. Keyed by subject alone, the second grade's Mathematics plan
 * was refused when created, and the by-day address wrote it over the first
 * grade's plan with a 200 (2026-10-15).
 *
 * The grade is keyed the way the subject is (see
 * 2026_09_27_120000_key_lesson_plans_by_subject): a second column,
 * `grade_key`, NOT NULL with '' for "no grade", because a NULL never collides
 * in a MySQL unique index and two ungraded plans of one subject would both be
 * admitted. The model derives it on every save (LessonPlan::gradeKeyFor), so
 * "Pre-K" and "Pre-Kindergarten" are one grade, as everywhere else that
 * compares grades (App\Support\GradeLevel). A plan saved before grades
 * mattered, or by a school with no pacing guide, keys as '' and is its own
 * key in the index; in a class taught as one grade the controller still
 * treats it as that grade's plan (LessonPlanController::ofGrade).
 *
 * ## Order of operations
 *
 * The new index is created BEFORE the old one is dropped, for the reason the
 * subject migration gives: MySQL needs an index leading with `group_id` behind
 * that foreign key and refuses to drop the only one. The new index leads with
 * `group_id` too, so it takes the old one's place for the key and for reads.
 *
 * No pre-flight on the way up: the new key adds a column to the old one, so
 * no data that satisfied the old index can violate it.
 *
 * Blueprint only, no raw SQL, so there is no driver guard to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_plans', function (Blueprint $table) {
            // 32, the width of grade_label: the key only ever shortens a label.
            $table->string('grade_key', 32)->default('')->after('grade_label');
        });

        // The model's own key function, so the backfill and every later save
        // agree on what "the same grade" means. A handful of rows: a loop.
        DB::table('lesson_plans')->whereNotNull('grade_label')->orderBy('id')
            ->each(function (object $row): void {
                DB::table('lesson_plans')->where('id', $row->id)
                    ->update(['grade_key' => LessonPlan::gradeKeyFor($row->grade_label)]);
            });

        Schema::table('lesson_plans', function (Blueprint $table) {
            // 42 characters, hand-named: MySQL refuses over 64 and SQLite does
            // not, so a generated name is only ever checked in production.
            $table->unique(['group_id', 'session_date', 'subject_key', 'grade_key'], 'lesson_plan_class_day_subject_grade_unique');
        });

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropUnique('lesson_plan_class_day_subject_unique');
        });
    }

    /**
     * Back to one plan per subject per day, which REFUSES while any class has
     * the same subject planned for two grades on one day. Restoring the old
     * index over that data would abort half-way on a duplicate-key error;
     * which grade's plan survives is the school's decision, not a rollback's,
     * so this names the plans and stops before changing anything.
     */
    public function down(): void
    {
        $clashes = DB::table('lesson_plans')
            ->select('group_id', 'session_date', 'subject_key', DB::raw('COUNT(*) AS plans'))
            ->groupBy('group_id', 'session_date', 'subject_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($clashes->isNotEmpty()) {
            $ids = DB::table('lesson_plans')
                ->where(function ($q) use ($clashes) {
                    foreach ($clashes as $c) {
                        $q->orWhere(fn ($w) => $w->where('group_id', $c->group_id)
                            ->where('session_date', $c->session_date)
                            ->where('subject_key', $c->subject_key));
                    }
                })
                ->orderBy('id')->pluck('id')->implode(', ');

            throw new RuntimeException(
                "Cannot go back to one lesson plan per subject per day: {$clashes->count()} class-day subject(s) hold a plan for more than one grade "
                . "(lesson_plans ids {$ids}). Remove or move all but one plan of each of those subjects on those days first."
            );
        }

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->unique(['group_id', 'session_date', 'subject_key'], 'lesson_plan_class_day_subject_unique');
        });

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropUnique('lesson_plan_class_day_subject_grade_unique');
        });

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropColumn('grade_key');
        });
    }
};
