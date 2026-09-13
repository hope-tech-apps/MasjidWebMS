<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * school_years — the dates a school meets between.
 *
 * ## The meeting weekday is DERIVED from first_day, never stored
 *
 * A school year is a date range whose meeting days repeat every 7 days from
 * `first_day` to `last_day` (BISS: 2026-10-11 is a Sunday). A separate
 * `meeting_weekday` column could disagree with `first_day`, and "we now meet on
 * Saturdays" is really a different first day. So there is no such column: the
 * weekday is `first_day`'s, in App\Models\SchoolYear::meetingWeekday().
 *
 * ## Why a date range and not one row per school day
 *
 * "Every Sunday except…" is what an office actually decides. Moving the last
 * day or cancelling a Sunday is then one write, not a regeneration, and the
 * reason travels with the exception (school_closures).
 *
 * ## curriculum_weeks.week_no is STILL NOT mapped to these dates
 *
 * 2026_09_08_150000_create_curriculum_weeks_table says why a date-to-week
 * mapping would be invented. Having a calendar does not change that; nothing
 * here derives a week number.
 *
 * ## No soft deletes
 *
 * Reference data, the same call curriculum_weeks makes. Deleting a year that
 * closures, register marks or form answers still point at is refused in
 * SchoolCalendarController::destroyYear, which is why the cascade below only
 * ever fires when the organisation itself goes.
 *
 * Years in one organisation must not overlap. No index can express a range
 * overlap, so StoreSchoolYearRequest checks it; the unique index below is the
 * backstop for the one overlap an index CAN see (two years starting the same day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // '2026–27'. Short on purpose: it is a heading, not a description.
            $table->string('label', 32);

            $table->date('first_day');
            $table->date('last_day');

            $table->timestamps();

            // Hand-named: MySQL's 64-character identifier limit is invisible on
            // the SQLite suite (.claude/rules/migrations.md).
            $table->unique(['masjid_id', 'first_day'], 'school_year_org_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_years');
    }
};
