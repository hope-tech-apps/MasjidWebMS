<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lessons of different classes that carry the same key are one lesson the classes take together.
     * Additive and nullable: every existing row reads as before. Blueprint only, so MySQL and SQLite agree.
     */
    public function up(): void
    {
        Schema::table('timetable_meetings', function (Blueprint $t) {
            $t->uuid('joint_key')->nullable();
            $t->index(['masjid_id','joint_key'],'tt_meetings_joint');
        });
    }

    public function down(): void
    {
        Schema::table('timetable_meetings', fn (Blueprint $t) => $t->dropIndex('tt_meetings_joint'));
        Schema::table('timetable_meetings', fn (Blueprint $t) => $t->dropColumn('joint_key'));
    }
};
