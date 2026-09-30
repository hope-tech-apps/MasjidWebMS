<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * masjid_points_settings: one optional row per school saying WHEN its weekly
 * points report goes out (T-003.3).
 *
 * `report_weekday` is 0 = Sunday ... 6 = Saturday (the numbering SchoolCalendar and
 * the school-calendar screens use) and `report_time` is 'H:i' on the SCHOOL's clock.
 * Both are nullable: no row, or a null, means "the default" (Friday 15:00,
 * App\Support\PointsReportSchedule), so a school that never touches this behaves as
 * the owner asked for Al-Razi. The values are validated in PHP at the SuperAdmin
 * endpoint, never by a database enum or CHECK (one driver has no CHECK).
 *
 * `masjid_id` is UNIQUE, so a school has at most one row; no partial index is
 * needed (rule 3). The row holds no personal data. Additive, Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masjid_points_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('report_weekday')->nullable();
            $table->char('report_time', 5)->nullable();
            $table->timestamps();

            $table->unique('masjid_id', 'masjid_points_settings_masjid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_points_settings');
    }
};
