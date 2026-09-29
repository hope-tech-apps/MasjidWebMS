<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * groups.points_period — how a class's points are SHOWN (T-003.2, "points need a
 * reset option at the end of the week that teachers can opt into").
 *
 * NULL and 'running' both mean what every class did before this column existed:
 * one running total. 'weekly' makes the class read a week at a time, with the full
 * history kept and shown. It is a VIEW choice. No award row is deleted, revoked or
 * edited by turning it on or off, so switching it back loses nothing and nothing
 * needs a backup or a data migration.
 *
 * Additive and nullable (.claude/rules/migrations.md, rules 1 and 6): a string
 * validated against Group::POINTS_PERIODS in PHP, never a DB enum, and no default
 * so the new code meeting the old schema for the seconds a deploy takes reads it as
 * absent (Group::pointsPeriod() degrades to 'running'). Blueprint only: no raw SQL,
 * so no driver guard. `string(16)` is checked by a schema test, because SQLite
 * would let a longer value through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('points_period', 16)->nullable()->after('arabic_stage');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('points_period');
        });
    }
};
