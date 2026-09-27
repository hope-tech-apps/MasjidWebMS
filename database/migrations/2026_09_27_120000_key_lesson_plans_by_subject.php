<?php

use App\Models\LessonPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One lesson plan per class, per day, PER SUBJECT.
 *
 * Al-Razi's two classes are combined-grade homerooms that teach several
 * subjects a day, and a teacher asked for a plan for each (2026-09-27). The
 * one-per-day index (`lesson_plan_class_day_unique`) made the second subject
 * impossible to write: saving it replaced the first.
 *
 * ## How a plan with no subject is keyed
 *
 * `subject` stays nullable — a school with no imported pacing guide writes one
 * general plan a day and never picks a subject. A unique index on
 * (group_id, session_date, subject) would enforce nothing for those rows: a
 * NULL never collides with another NULL on MySQL, so two "general" plans for
 * one day would both be admitted.
 *
 * So the key is a second column, `subject_key`: NOT NULL, '' for "no subject",
 * otherwise the subject lower-cased with its whitespace collapsed. The model
 * computes it in `saving` (LessonPlan::subjectKeyFor), the same shape as
 * `contact_tags.name_key`, and for the same two reasons:
 *
 *   - An index on `subject` itself would mean whatever the column's collation
 *     says. SQLite (the suite) compares bytes; MySQL compares under
 *     DB_COLLATION — config/database.php defaults to the case-insensitive
 *     utf8mb4_unicode_ci, and the contact_tags migration records production as
 *     the byte-exact utf8mb4_bin. Either way "Math" and "math " would be one
 *     subject on one driver and two on the other. A normalised key makes the
 *     rule identical everywhere and independent of any collation.
 *   - A STORED generated column (.claude/rules/migrations.md) would express
 *     COALESCE but not the lower-casing and whitespace rule without a second,
 *     MySQL-only expression, and SQLite cannot ADD a STORED column at all — the
 *     suite would then be enforcing a different constraint from production.
 *
 * Every writer of this table goes through the model (Teacher\LessonPlanController
 * is the only one), so the key cannot be skipped by a writer that exists.
 *
 * ## Order of operations
 *
 * The new index is created BEFORE the old one is dropped. On MySQL the
 * `group_id` foreign key needs an index that leads with `group_id`; the old
 * unique index is that index today, and MySQL refuses to drop it until another
 * one covers the key. The new index leads with `group_id` too, so it takes
 * over as both the foreign key's index and the read index
 * (WHERE group_id = ? AND session_date BETWEEN …).
 *
 * No pre-flight on the way up: the new key is strictly WEAKER than the old one
 * (it adds a column), so no data that satisfied the old index can violate it.
 *
 * Blueprint only, no raw SQL, so there is no driver guard to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->string('subject_key', 64)->default('')->after('subject');
        });

        // Production holds a handful of rows; this is a loop, not a job. The
        // key function is the model's own, so the backfill and every later
        // save agree on what "the same subject" means.
        DB::table('lesson_plans')->whereNotNull('subject')->orderBy('id')
            ->each(function (object $row): void {
                DB::table('lesson_plans')->where('id', $row->id)
                    ->update(['subject_key' => LessonPlan::subjectKeyFor($row->subject)]);
            });

        Schema::table('lesson_plans', function (Blueprint $table) {
            // 36 characters, hand-named: MySQL refuses over 64 and SQLite does
            // not, so a generated name is only ever checked in production.
            $table->unique(['group_id', 'session_date', 'subject_key'], 'lesson_plan_class_day_subject_unique');
        });

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropUnique('lesson_plan_class_day_unique');
        });
    }

    /**
     * Back to one plan per day — which REFUSES while any class has two plans on
     * one day. Restoring the old index over that data would abort half-way on
     * a duplicate-key error naming only a column; choosing which subject's plan
     * survives is the school's decision, not a rollback's, so this names the
     * plans and stops before changing anything.
     */
    public function down(): void
    {
        $clashes = DB::table('lesson_plans')
            ->select('group_id', 'session_date', DB::raw('COUNT(*) AS plans'))
            ->groupBy('group_id', 'session_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($clashes->isNotEmpty()) {
            $ids = DB::table('lesson_plans')
                ->where(function ($q) use ($clashes) {
                    foreach ($clashes as $c) {
                        $q->orWhere(fn ($w) => $w->where('group_id', $c->group_id)->where('session_date', $c->session_date));
                    }
                })
                ->orderBy('id')->pluck('id')->implode(', ');

            throw new RuntimeException(
                "Cannot go back to one lesson plan per class per day: {$clashes->count()} class-day(s) hold more than one plan "
                . "(lesson_plans ids {$ids}). Remove or move all but one plan on each of those days first."
            );
        }

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->unique(['group_id', 'session_date'], 'lesson_plan_class_day_unique');
        });

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropUnique('lesson_plan_class_day_subject_unique');
        });

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropColumn('subject_key');
        });
    }
};
