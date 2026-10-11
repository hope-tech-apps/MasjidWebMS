<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_period_sets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('school_year_id'); $t->string('name',60); $t->timestamps();
            $t->index(['masjid_id','school_year_id'],'tt_sets_year');
            $t->foreign('masjid_id','tt_sets_org_fk')->references('id')->on('masjids')->restrictOnDelete();
            $t->foreign('school_year_id','tt_sets_year_fk')->references('id')->on('school_years')->restrictOnDelete();
        });
        Schema::create('timetable_periods', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('period_set_id'); $t->string('name',60); $t->time('starts_at'); $t->time('ends_at'); $t->string('kind',16); $t->unsignedSmallInteger('position'); $t->timestamps();
            $t->index(['masjid_id','period_set_id'],'tt_periods_set');
            $t->foreign('period_set_id','tt_periods_set_fk')->references('id')->on('timetable_period_sets')->cascadeOnDelete();
        });
        Schema::create('timetable_days', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('school_year_id'); $t->unsignedTinyInteger('weekday'); $t->unsignedBigInteger('period_set_id'); $t->timestamps();
            $t->unique(['masjid_id','school_year_id','weekday'],'tt_days_unique');
            $t->foreign('school_year_id','tt_days_year_fk')->references('id')->on('school_years')->restrictOnDelete();
            $t->foreign('period_set_id','tt_days_set_fk')->references('id')->on('timetable_period_sets')->restrictOnDelete();
        });
        Schema::create('timetable_class_days', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('school_year_id'); $t->unsignedBigInteger('group_id'); $t->unsignedTinyInteger('weekday'); $t->unsignedBigInteger('period_set_id'); $t->timestamps();
            $t->unique(['masjid_id','school_year_id','group_id','weekday'],'tt_class_days_unique');
            $t->foreign('school_year_id','tt_class_days_year_fk')->references('id')->on('school_years')->restrictOnDelete();
            $t->foreign('group_id','tt_class_days_group_fk')->references('id')->on('groups')->cascadeOnDelete();
            $t->foreign('period_set_id','tt_class_days_set_fk')->references('id')->on('timetable_period_sets')->restrictOnDelete();
        });
        Schema::create('timetable_rooms', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->string('name',60); $t->string('name_key',60); $t->unsignedInteger('capacity')->nullable(); $t->boolean('active')->default(true); $t->timestamps();
            $t->unique(['masjid_id','name_key'],'tt_rooms_name');
            $t->foreign('masjid_id','tt_rooms_org_fk')->references('id')->on('masjids')->restrictOnDelete();
        });
        Schema::create('timetable_class_rooms', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('group_id'); $t->unsignedBigInteger('room_id')->nullable(); $t->timestamps();
            $t->unique(['masjid_id','group_id'],'tt_class_rooms_unique');
            $t->foreign('group_id','tt_class_rooms_group_fk')->references('id')->on('groups')->cascadeOnDelete();
            $t->foreign('room_id','tt_class_rooms_room_fk')->references('id')->on('timetable_rooms')->restrictOnDelete();
        });
        Schema::create('timetable_meetings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('school_year_id'); $t->unsignedBigInteger('group_id'); $t->unsignedTinyInteger('weekday'); $t->unsignedBigInteger('period_id'); $t->string('kind',16); $t->unsignedBigInteger('class_subject_id')->nullable(); $t->string('activity_name',60)->nullable(); $t->unsignedBigInteger('room_id')->nullable(); $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->timestamps();
            $t->index(['masjid_id','school_year_id','weekday','effective_from'],'tt_meetings_year_day');
            $t->index(['group_id','weekday','period_id'],'tt_meetings_slot');
            $t->foreign('school_year_id','tt_meetings_year_fk')->references('id')->on('school_years')->restrictOnDelete();
            $t->foreign('group_id','tt_meetings_group_fk')->references('id')->on('groups')->restrictOnDelete();
            $t->foreign('period_id','tt_meetings_period_fk')->references('id')->on('timetable_periods')->restrictOnDelete();
            $t->foreign('class_subject_id','tt_meetings_subject_fk')->references('id')->on('class_subjects')->restrictOnDelete();
            $t->foreign('room_id','tt_meetings_room_fk')->references('id')->on('timetable_rooms')->restrictOnDelete();
        });
        Schema::create('timetable_meeting_teachers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('meeting_id'); $t->unsignedBigInteger('user_id'); $t->timestamps();
            $t->unique(['meeting_id','user_id'],'tt_teachers_unique'); $t->index(['masjid_id','user_id'],'tt_teachers_user');
            $t->foreign('meeting_id','tt_teachers_meeting_fk')->references('id')->on('timetable_meetings')->cascadeOnDelete();
            $t->foreign('user_id','tt_teachers_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        foreach (['timetable_meeting_teachers','timetable_meetings','timetable_class_rooms','timetable_rooms','timetable_class_days','timetable_days','timetable_periods','timetable_period_sets'] as $table) Schema::dropIfExists($table);
    }
};
