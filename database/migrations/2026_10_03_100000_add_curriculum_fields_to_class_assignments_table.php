<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a piece of work is FOR: its subject, its type, its weight and the one
 * standard it teaches (T-001.1, T-001.2, T-001.3). ONE migration for all three
 * items because they change the same table, and a table altered three times in
 * one release is three chances to leave a deploy half-applied.
 *
 * ## Every column is a SNAPSHOT
 *
 * `subject`, `standard_code` and `curriculum_focus` are copies of words the school
 * chose at the time, never foreign keys. The office can rename or retire a
 * subject, and the school can re-import its pacing guide, without a mark a parent
 * has already read changing under them. `subject_key` is derived from `subject`
 * on every save (App\Support\SubjectKey) and exists so two spellings of one
 * subject group together; it is `hidden` on the model and is not something a
 * client sends.
 *
 * ## Existing rows stay NULL
 *
 * Blank is not a default. The nine rows on production carry no subject, no type,
 * no weight and no standard, and stay that way: inventing a subject for them
 * would put a claim on a child's record that nobody made. `subject_key` is NOT
 * NULL with default '' ("no subject") for the reason `lesson_plans.subject_key`
 * is: a NULL never collides in a MySQL unique index and never compares equal in a
 * GROUP BY the way '' does. There is no unique index on it here; the empty
 * string is simply the one value "no subject" has.
 *
 * ## Types and the optional per-work weight
 *
 * `type` is a short KEY ('quiz', 'homework', 'test', 'classwork', 'other'), the
 * allowed set being a PHP constant (ClassAssignment::TYPES) and never a DB enum.
 * `weight` is an optional per-piece override of the class's weight for that type
 * (owner, 2026-09-28: "per type with a per-assignment override"); NULL means
 * "inherit". 0-100 fits an unsigned tinyint.
 *
 * ## Additive, and the deploy window
 *
 * Nullable columns and one NOT NULL with a default: nothing existing is touched
 * and no index is added (the table is nine rows on production). `bin/deploy`
 * checks out new PHP before it migrates, so for a few seconds new code meets the
 * old schema; a save in that window would fail on an unknown column. Deploy after
 * school hours.
 *
 * ## Rolling back refuses rather than lose what teachers typed
 *
 * `down()` drops the columns and everything in them with it, so it throws while
 * any row carries a subject, a type, a weight or a standard, naming the count, and
 * changes nothing. Blueprint only, no raw SQL, so no driver guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_assignments', function (Blueprint $table) {
            $table->string('subject', 64)->nullable()->after('title');
            $table->string('subject_key', 64)->default('')->after('subject');
            $table->string('type', 16)->nullable()->after('subject_key');
            $table->unsignedTinyInteger('weight')->nullable()->after('type');
            $table->string('standard_code', 32)->nullable()->after('weight');
            $table->string('curriculum_focus', 500)->nullable()->after('standard_code');
            $table->unsignedTinyInteger('curriculum_week_no')->nullable()->after('curriculum_focus');
        });
    }

    public function down(): void
    {
        $used = \Illuminate\Support\Facades\DB::table('class_assignments')
            ->where(function ($q) {
                $q->whereNotNull('subject')->orWhereNotNull('type')->orWhereNotNull('weight')
                    ->orWhereNotNull('standard_code')->orWhereNotNull('curriculum_focus');
            })
            ->count();

        if ($used > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$used} piece(s) of work carry a subject, type, weight or standard that "
                .'dropping these columns would destroy. Clear them deliberately first.'
            );
        }

        Schema::table('class_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'subject', 'subject_key', 'type', 'weight',
                'standard_code', 'curriculum_focus', 'curriculum_week_no',
            ]);
        });
    }
};
