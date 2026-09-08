<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own lesson-plan template, added to `lesson_plans`.
 *
 * PURELY ADDITIVE. Every column is nullable with no default, so the table is
 * WIDENED rather than altered: an existing row reads as "this plan predates the
 * template" and keeps its `title` and `body` exactly. No backfill, no
 * `ALTER … MODIFY`, no raw SQL — so there is no dialect trap here and nothing
 * for MigrationsBootTest's driver guard to catch.
 *
 * ## `body` STAYS NOT NULL, and now means ACTIVITIES
 *
 * Relaxing it would need `ALTER TABLE … MODIFY`, the exact statement
 * .claude/rules/migrations.md exists about — and a MySQL-only relaxation would
 * leave SQLite's NOT NULL standing, so the suite would never exercise the
 * production shape. It would also orphan the stated reason `destroy()` exists.
 *
 * It is not a compromise: the school's own weekly grid (template §3) has an
 * "Activities" column, so `body` is relabelled to the school's word. Every
 * lesson has activities, so the field is honest as required.
 *
 * ## What the template asks for and this deliberately does NOT store
 *
 *   - TEACHER NAME — derived from `author_user_id`. Storing it would let a
 *     row disagree with the account that wrote it.
 *   - DATE — `session_date` already, and the unique key.
 *   - NUMBER OF STUDENTS, and its breakdowns by learning style and ability
 *     band — the roster knows the count, and the school has no source anywhere
 *     for per-child learning-style or ability-band assignments. Columns for
 *     data that does not exist would be a form teachers leave blank forever.
 *   - "STUDENTS NEEDING FOLLOW-UP" — refused ON PURPOSE. A free-text field
 *     naming children, sitting on a row with no per-child disclosure rules, is
 *     a record ABOUT a child hidden inside a note about a room; GroupAudience
 *     could not govern it because `lesson_plans` deliberately has no
 *     `membership` relation. The UI points that teacher at the Messages tab
 *     instead, where naming a child is already a governed act.
 *
 * ## Types
 *
 * Prose is `text`, bounded things are `string(n)`. MySQL counts a VARCHAR
 * toward the 65,535-byte row limit at 4 bytes/char under utf8mb4, while
 * TEXT/JSON contribute a ~20-byte pointer. These 23 columns add roughly 2.3 KB;
 * writing them all as VARCHAR(500) would have added ~46 KB and left the table
 * one column away from a "Row size too large" that appears only on MySQL and
 * never in the SQLite suite.
 *
 * NO NEW INDEX, deliberately. `lesson_plan_class_day_unique` is still both the
 * write key and the read key; none of these columns changes either. Index a
 * standard-code lookup when a report needs one, not by symmetry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_plans', function (Blueprint $table) {
            // Context. Free text for now: the pacing-guide importer that will
            // turn these into pickers is a separate change.
            $table->string('subject', 64)->nullable()->after('session_date');
            $table->string('grade_label', 32)->nullable()->after('subject');
            $table->unsignedTinyInteger('curriculum_week_no')->nullable()->after('grade_label');

            // The standard. 32 is generous against a measured maximum of 12.
            $table->string('standard_code', 32)->nullable()->after('curriculum_week_no');
            $table->text('standard_description')->nullable()->after('standard_code');

            $table->text('objective')->nullable()->after('standard_description');
            // A LIST, so json + an 'array' cast — the shape this codebase already
            // uses for masjid_app_publishing.enabled_platforms. Never a
            // comma-joined string: nothing in this repo stores a list that way.
            $table->json('learning_outcomes')->nullable()->after('objective');

            // The template's three bands, plus the two the school's own
            // curriculum names (ELL/AAL and SEN).
            $table->text('differentiation_support')->nullable();
            $table->text('differentiation_extension')->nullable();
            $table->text('differentiation_learning_styles')->nullable();
            $table->text('differentiation_ell_aal')->nullable();
            $table->text('differentiation_sen')->nullable();

            $table->text('cross_integration_subject')->nullable();
            $table->text('cross_integration_islamic')->nullable();
            $table->text('cross_integration_stem')->nullable();

            // A fixed set of which MANY may be chosen -> json + Rule::in on '.*',
            // authority in PHP constants on the model.
            $table->json('teaching_methods')->nullable();
            $table->string('teaching_methods_other', 255)->nullable();
            $table->text('teaching_aids')->nullable();

            $table->text('assessment_formative')->nullable();
            $table->text('assessment_exit_ticket')->nullable();

            // Reflection. Two fields, not the template's three — see above.
            $table->text('reflection_worked')->nullable();
            $table->text('reflection_improve')->nullable();

            // Where a prefilled standard came from, as TEXT rather than an FK a
            // later re-import could move underneath a plan already written.
            $table->string('prefill_source', 120)->nullable();
        });
    }

    /**
     * DEV ONLY. This drops columns holding prose a teacher typed; there is no
     * way to put it back. Rolling this back on production is data loss.
     */
    public function down(): void
    {
        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropColumn([
                'subject', 'grade_label', 'curriculum_week_no',
                'standard_code', 'standard_description',
                'objective', 'learning_outcomes',
                'differentiation_support', 'differentiation_extension',
                'differentiation_learning_styles', 'differentiation_ell_aal',
                'differentiation_sen',
                'cross_integration_subject', 'cross_integration_islamic',
                'cross_integration_stem',
                'teaching_methods', 'teaching_methods_other', 'teaching_aids',
                'assessment_formative', 'assessment_exit_ticket',
                'reflection_worked', 'reflection_improve',
                'prefill_source',
            ]);
        });
    }
};
