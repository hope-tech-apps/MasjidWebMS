<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The age a family GAVE for a student, and the day they gave it, so a class
 * roster can show an age for a child whose date of birth nobody has typed.
 *
 * A registration form that asks "how old is your child?" gives a school an age
 * for every student on the first day, and no date of birth for any of them.
 * Without this the Age column added with `contacts.date_of_birth` stays empty
 * until the office has asked every family for a date and typed it.
 *
 * ONE column holding both facts as `{age}@{Y-m-d}` (for example `6@2026-09-14`),
 * because an age without the day it was true on is wrong within a year, and the
 * two must never be written apart. TEXT, not a number: like `date_of_birth` it
 * is written through the model's `encrypted` cast, so the column holds
 * ciphertext. Nullable and additive: no existing row changes and nothing is
 * backfilled here. No index: an encrypted value is never searched or sorted.
 *
 * A date of birth, once the office adds one, WINS: the roster then shows the
 * exact age and this value is not read (App\Support\StudentAge::shown()).
 *
 * Who writes and reads it is in `.claude/rules/groups.md` ("The age a family
 * gave"): one writer and one reader on the model, and no payload carries the
 * value itself, only the whole-number age worked out from it.
 *
 * DEPLOY ORDER. bin/deploy serves the new code before it runs this. Every read
 * is behind App\Support\StudentAge::givenColumnExists(), so for those seconds a
 * roster answers as before. Roll back by code only: `migrate:rollback` would
 * destroy every age that was recorded.
 *
 * Blueprint only, so the same statement runs on MySQL and on the suite's SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('age_given')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('age_given');
        });
    }
};
