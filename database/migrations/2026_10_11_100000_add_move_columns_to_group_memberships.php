<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student MOVED to another class: where from, where to, on which day, by whom.
 *
 * ---------------------------------------------------------------------------
 * WHY FOUR COLUMNS AND NOT A HISTORY TABLE
 * ---------------------------------------------------------------------------
 *
 * The office has to be able to read, on the roster itself, "Moved to 2nd Grade
 * on 4 October" on the place a student left and "Moved from 1st Grade" on the
 * place they hold now. Four nullable columns on the roster row answer that. A
 * `roster_moves` table would be a twelfth key into `group_memberships` to
 * classify (App\Support\AcademicRecordsHeld::KEYS), plus a scrub rule, an
 * erasure rule and an export. The columns keep only the LATEST move of a row;
 * each move also writes one `roster.move` log line with ids only.
 *
 *   - `moved_from_group_id`  on the row now standing in the NEW class
 *   - `moved_to_group_id`    on the OLD row, when it stays behind
 *   - `moved_on`             on both: the day the office chose
 *   - `moved_by_user_id`     on both: who moved them
 *
 * Only student rows carry them. A guardian entry never does.
 *
 * ---------------------------------------------------------------------------
 * PLAIN COLUMNS: NO FOREIGN KEY, NO INDEX
 * ---------------------------------------------------------------------------
 *
 * The same rule as `left_recorded_by_user_id` beside them
 * (2026_09_12_000000): adding a foreign key to an EXISTING table makes SQLite
 * rebuild the table, and a rebuild drops WHERE-clause indexes. A class that was
 * deleted since leaves an id that reads as "a class that was removed", and an
 * administrator who left reads as an actor nobody can name, which is what
 * `nullOnDelete` would have produced anyway. Nothing filters or joins on these
 * columns in a hot path (the roster list reads them off rows it already has),
 * so there is no index either.
 *
 * Additive: every existing row keeps its meaning, and NULL means "never moved".
 * No backfill. The same on MySQL and SQLite, so nothing here is driver-guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('moved_from_group_id')->nullable()->after('left_recorded_by_user_id');
            $table->unsignedBigInteger('moved_to_group_id')->nullable()->after('moved_from_group_id');
            $table->date('moved_on')->nullable()->after('moved_to_group_id');
            $table->unsignedBigInteger('moved_by_user_id')->nullable()->after('moved_on');
        });
    }

    public function down(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->dropColumn(['moved_by_user_id', 'moved_on', 'moved_to_group_id', 'moved_from_group_id']);
        });
    }
};
