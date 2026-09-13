<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * school_closures — a meeting day on which there is no school, and why.
 *
 * A closure always falls inside its year and on the year's weekday
 * (StoreSchoolClosureRequest), so it is an exception to a day that would
 * otherwise have met — never a free-floating date.
 *
 * ## Only closures are enforced on the register
 *
 * A closed day has no register to take (SaveAttendanceRequest,
 * AttendanceController::save). Creating a closure over a day that already has
 * register marks is refused with the count, under a lock on the year's row, so
 * a child's attendance is never hidden underneath one — which is why the
 * report card, the per-child history and the records export needed no change.
 *
 * `masjid_id` is denormalised beside `school_year_id` so the scope works without
 * a join, the same reasoning that put `group_id` on attendance_records. New
 * table, so ordinary foreign keys are fine (the no-FK rule is about ALTERing
 * `masjids`, 2026_09_09_120000_add_parent_id_to_masjids_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->cascadeOnDelete();

            $table->date('closed_on');

            // "Thanksgiving weekend". Office-written and shown to families.
            $table->string('reason', 160);

            $table->timestamps();

            // One closure per day per year. Both names hand-written for MySQL's
            // 64-character identifier limit.
            $table->unique(['school_year_id', 'closed_on'], 'school_closure_day_unique');
            $table->index(['masjid_id', 'closed_on'], 'school_closure_org_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_closures');
    }
};
