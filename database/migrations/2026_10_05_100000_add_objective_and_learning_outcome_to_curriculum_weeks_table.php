<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * curriculum_weeks gains `objective` and `learning_outcome`.
 *
 * ## WHY
 *
 * The school's separated Quarter 1 plan (Qur'an, Arabic and Islamic Studies,
 * Pre-K to Grade 2) has FIVE cells per week: Week, Standard Code, Focus Skill,
 * Objective and Learning Outcome. The table has one free-text slot the teacher
 * sees (`focus`). Every single-slot mapping loses the school's words (Focus
 * Skill alone drops "Memorize Surah Al-Ikhlāṣ"; Objective alone drops the
 * letters in "Letters أ–ب"), and joining two cells with a separator would store
 * a string the school never wrote. So each school column lands in the column of
 * the same name: Focus Skill in `focus`, Objective here, Learning Outcome here,
 * Standard Code in `standard_code`. Nothing is joined, so a byte-for-byte test
 * can state what was imported.
 *
 * ## ADDITIVE
 *
 * Both columns are nullable, with no default and no backfill. Every existing row
 * (the July guide's 1512) keeps NULL and reads exactly as before, and the old
 * code, which never selects them, is unaffected. That is why rolling the CODE
 * back needs no data step, and why the DATA is rolled back by importing the
 * inverse file `curriculum:import` writes, never by this migration.
 *
 * ## DOWN REFUSES TO THROW DATA AWAY
 *
 * Dropping a column that holds the school's words would lose them silently. If
 * any row has either column set, `down()` throws and names the command that
 * removes them first; once none does, it drops both. Blueprint only, no raw SQL,
 * so it runs unguarded on MySQL and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_weeks', function (Blueprint $table) {
            $table->string('objective', 500)->nullable()->after('focus');
            $table->string('learning_outcome', 500)->nullable()->after('objective');
        });
    }

    public function down(): void
    {
        $held = DB::table('curriculum_weeks')
            ->where(fn ($q) => $q->whereNotNull('objective')->orWhereNotNull('learning_outcome'))
            ->count();

        if ($held > 0) {
            throw new RuntimeException(
                "Refusing to drop curriculum_weeks.objective and learning_outcome: {$held} row(s) hold the school's "
                . 'words in them. Import the inverse file first: '
                . 'php artisan curriculum:import <masjid> storage/app/private/curriculum-imports/<m..-inverse-of-..>.json'
            );
        }

        Schema::table('curriculum_weeks', function (Blueprint $table) {
            $table->dropColumn(['objective', 'learning_outcome']);
        });
    }
};
