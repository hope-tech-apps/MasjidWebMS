<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `school_subjects.seeded_by`: the mark a seed migration leaves on a row it wrote.
 *
 * NULL for every row the office typed. It exists so the seed's `down()`
 * (2026_10_03_100300) can tell a row it wrote from an office row that happens to
 * read the same: a "Qur'an" typed into the Subjects screen at the defaults
 * (position 0, every grade) is identical, column for column, to the seeded one,
 * and the seed skips a subject that already exists, so it never owned that row.
 * The office cannot set it (not fillable, never sent).
 *
 * WHY ITS OWN MIGRATION. The column was first added by editing the create-table
 * migration (2026_10_03_100200) after that migration had already been committed
 * without it, and the earlier version of 100200 is on other branches and may have
 * run on a shared box. Laravel never re-runs an applied migration, so on such a
 * box the seed's insert would fail on an unknown column and abort the deploy.
 * 100200 is back to what it first said; this adds the column after it and before
 * the seed, on every box: a fresh one gets it here, one that ran the earlier 100200
 * gets it here too. A migration that has run anywhere is never edited: a new
 * column is a new migration.
 *
 * Guarded both ways (`hasColumn`), so it is safe on a table that already carries
 * the column (a box that ran the edited 100200 as it briefly stood) and on a
 * rollback that has nothing to drop. Blueprint only. The column is not indexed and
 * `down()` drops provenance only, never a subject: the seed's own `down()`, which
 * runs first in a rollback, is what removes seeded rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('school_subjects', 'seeded_by')) {
            return;
        }

        Schema::table('school_subjects', function (Blueprint $table) {
            $table->string('seeded_by', 120)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('school_subjects', 'seeded_by')) {
            return;
        }

        Schema::table('school_subjects', function (Blueprint $table) {
            $table->dropColumn('seeded_by');
        });
    }
};
