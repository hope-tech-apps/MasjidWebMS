<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * curriculum_weeks — one school's pacing guide, week by week.
 *
 * ## WHY THIS IS A TENANT TABLE AND NOT A PHP CONSTANT
 *
 * App\Support\QuranIndex is the tempting precedent and its own docblock says why
 * it does not apply: it is reference data "fixed for fourteen centuries" that
 * "cannot be edited by a tenant, imported, or versioned". A pacing guide is the
 * opposite of all three — it belongs to ONE school, it is a July 2026 draft, and
 * it is versioned per subject. Putting it in app/Support would hand NC Standard
 * Course of Study codes to every masjid ḥalaqa in Manara.
 *
 * So: reference data INSIDE a tenant, populated by `curriculum:import`.
 *
 * ## THE PLAN COPIES TEXT; IT NEVER POINTS AT A ROW HERE
 *
 * Prefill writes a SNAPSHOT onto the lesson plan — the same call behavior_awards
 * makes when it snapshots a skill label, and the inverse of
 * class_assignments.points_possible, which is deliberately NOT snapshotted
 * because correcting it SHOULD move every score. A guide re-imported next July
 * must not silently rewrite what a teacher taught last October. There is
 * therefore no FK from lesson_plans to this table, on purpose.
 *
 * ## `standard_code` IS NULLABLE, BY THE SCHOOL'S OWN RULE
 *
 * The Qur'an & Islamic Studies column is not part of the NC Standard Course of
 * Study and carries no code. Parentheses in that column are glosses — "(peace be
 * upon him)", "(birr al-walidayn)" — and the importer must never read one as a
 * standard. Measured: 1260 of 1512 imported rows carry a code, and all 252 that
 * do not are that column.
 *
 * `week_no` is NOT derived from a date anywhere. Three of the school's own
 * documents give three different school years — 41 weeks, 36 weeks, and 8-week
 * quarters — so any date-to-week mapping would be invented. The teacher picks
 * the week; the plan remembers it.
 *
 * No soft deletes: re-importable reference data holding no child's record. Not
 * registered in `groups:purge-feed`, for the same reason lesson_plans is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // The tenant's own vocabulary, exactly as group_memberships.grade_label
            // is: "Pre-Kindergarten" / "Kindergarten" / "Grade 1". Never an enum —
            // the day the school authors an Arabic column or renames a grade, it
            // must appear with no code change.
            $table->string('grade_label', 32);
            $table->string('subject', 64);

            $table->unsignedTinyInteger('week_no');
            $table->unsignedTinyInteger('quarter')->nullable();

            $table->string('focus', 500);
            $table->string('standard_code', 32)->nullable();

            // The WEEK's assessment note, copied onto each of its subject rows.
            // Denormalised on purpose: prefill is a single-row
            // (masjid, grade, subject, week) lookup, and a second table for one
            // sentence is not worth the join.
            $table->string('assessment_note', 255)->nullable();

            // Provenance. The curriculum's own instruction is to verify codes
            // against the official DPI documents before external citation, so a
            // row says which draft it came from.
            $table->string('source_label', 120)->nullable();

            $table->timestamps();

            // HAND-NAMED. Laravel would generate
            // curriculum_weeks_masjid_id_grade_label_subject_week_no_unique —
            // 61 characters, which fits, and that is exactly the trap: MySQL
            // refuses anything over 64 and SQLite refuses nothing, so a
            // generated name is only ever verified in production.
            //
            // It is also the READ key: prefill looks up one cell by all four,
            // and the left prefix (masjid_id, grade_label) serves "which
            // subjects does this grade have?". No second index.
            $table->unique(['masjid_id', 'grade_label', 'subject', 'week_no'], 'curriculum_week_cell_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_weeks');
    }
};
