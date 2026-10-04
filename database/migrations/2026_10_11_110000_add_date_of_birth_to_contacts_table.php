<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional date of birth for a student, so a class roster can show an age.
 *
 * TEXT, not DATE: the value is written through the model's `encrypted` cast, so
 * what the column holds is ciphertext many times longer than a date, never a
 * date. A DATE or a short VARCHAR column would reject it on MySQL in strict mode
 * and accept it on SQLite, which is why tests/Mysql pins the type. Nullable
 * and additive: no existing row changes and nothing is backfilled. No index,
 * because an encrypted value is never searched or sorted.
 *
 * The age is NOT stored. It is worked out on read, in whole years on the
 * school's clock (App\Support\StudentAge), so it is never a year out of date.
 *
 * Who writes it and who reads it is in `.claude/rules/groups.md`
 * ("A student's date of birth, and the age on a roster"): one named writer and
 * one named reader on the model; the office reads and sets it for a student in
 * a class and can clear it for any contact; and no teacher, family, mobile or
 * public payload carries it.
 *
 * DEPLOY ORDER. bin/deploy serves the new code before it runs this. Every read
 * of the column is therefore behind App\Support\StudentAge::columnExists(), so
 * for those seconds the rosters answer as before, with no ages. Roll back by
 * code only: `migrate:rollback` would destroy every date the office typed.
 *
 * Blueprint only, so the same statement runs on MySQL and on the suite's SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('date_of_birth')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('date_of_birth');
        });
    }
};
