<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    /** Hidden hints avoid schema probes and reference queries on pre-migration/unused paths. */
    public function up(): void
    {
        Schema::table('masjids', fn (Blueprint $t) => $t->boolean('has_timetable_records')->default(false));
        Schema::table('users', fn (Blueprint $t) => $t->boolean('has_timetable_records')->default(false));
        // This also covers a database that already ran the step-one migration and has kept rows.
        $schools = [];
        foreach (['timetable_period_sets','timetable_periods','timetable_days','timetable_class_days','timetable_rooms','timetable_class_rooms','timetable_meetings','timetable_meeting_teachers'] as $table) {
            foreach (DB::table($table)->distinct()->pluck('masjid_id') as $id) $schools[$id] = $id;
        }
        DB::table('masjids')->whereIn('id', array_values($schools))->update(['has_timetable_records'=>true]);
        DB::table('users')->whereIn('id', DB::table('timetable_meeting_teachers')->select('user_id'))->update(['has_timetable_records'=>true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('has_timetable_records'));
        Schema::table('masjids', fn (Blueprint $t) => $t->dropColumn('has_timetable_records'));
    }
};
