<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The letter tracker learns a second alphabet.
 *
 * The school runs the Letters tab for the 28 Arabic letters and asked for A–Z
 * tracked the same way, on the same tab, for the same children. A second table
 * would have meant a second export section, a second entry in the roster's
 * "this child holds N academic records" count, a second cascade to remember
 * when a membership is deleted and a second place for the tenant scope to be
 * forgotten. One column says which alphabet a row belongs to instead.
 *
 * ## No backfill, on purpose
 *
 * `alphabet` is NOT NULL with a default of `arabic`, so every row that exists
 * today is already correct the moment the column lands — which is the truth: the
 * only alphabet the tracker has ever recorded is the qāʿidah. A nullable column
 * plus an UPDATE would have meant a window where a read either missed the old
 * rows or had to spell `WHERE alphabet = 'arabic' OR alphabet IS NULL`, and that
 * second clause would have outlived the migration.
 *
 * ## The table keeps its `arabic_letter_progress` name
 *
 * It now holds both alphabets and the name is therefore a little wrong, and
 * renaming it is still not worth doing. The name is written into the hardcoded
 * constraint list in `2026_09_09_040000_stop_roster_edits_destroying_academic_records`
 * (which reads production's live FK names and fails halfway if one is guessed
 * wrong), into the school records export, and into the T-040 PII inventory. A
 * rename would touch all three, would have to be sequenced against a deploy that
 * runs old code against the new schema for a few seconds, and would change no
 * behaviour whatsoever. A confusing table name is cheaper than that.
 *
 * ## The new unique index is created BEFORE the old one is dropped
 *
 * MySQL refuses to drop an index a foreign key depends on, and
 * `group_membership_id` carries one. As the schema stands the drop would
 * succeed anyway — the FK has its own auto-created
 * `arabic_letter_progress_group_membership_id_foreign` index, which the
 * 2026_09_09 constraint swap left in place — but that is a fact about today's
 * schema rather than something this file should rely on. Adding the wider key
 * first (also leftmost on `group_membership_id`) means the constraint always has
 * somewhere to stand. `down()` is arranged the same way, in reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            // arabic | english, as App\Support\Letters\CurriculumRegistry names
            // them. A string and not an enum, like every other status column
            // here (.claude/rules/migrations.md).
            $table->string('alphabet', 16)->default('arabic')->after('group_membership_id');
        });

        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            // One row per drill per student PER ALPHABET. This is what still
            // makes marking idempotent, and what lets the two tracks name a
            // drill alike without colliding — the database should not have to
            // know that today's two curricula happen to share no id.
            $table->unique(
                ['group_membership_id', 'alphabet', 'drill_id'],
                'letter_progress_student_alphabet_drill_unique'
            );
        });

        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            $table->dropUnique('arabic_progress_student_drill_unique');

            // The class overview counts mastered rows for one group on ONE
            // alphabet. Named by hand and kept short: MySQL caps an identifier
            // at 64 characters and SQLite does not, so a generated name that
            // passes the suite can still abort a migration halfway on
            // production.
            $table->index(
                ['masjid_id', 'group_id', 'alphabet', 'status'],
                'letter_progress_masjid_group_alpha_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            $table->dropIndex('letter_progress_masjid_group_alpha_status_idx');
        });

        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            // Back first, for the same reason up() adds before it drops. This
            // can only succeed while no student holds one drill id on both tracks;
            // the two curricula name nothing alike, so rolling back a database
            // this migration created is safe. A rollback after someone adds an
            // alphabet whose ids overlap Arabic's is not, and should fail loudly
            // here rather than quietly drop a child's record.
            $table->unique(['group_membership_id', 'drill_id'], 'arabic_progress_student_drill_unique');
        });

        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            $table->dropUnique('letter_progress_student_alphabet_drill_unique');
        });

        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            $table->dropColumn('alphabet');
        });
    }
};
