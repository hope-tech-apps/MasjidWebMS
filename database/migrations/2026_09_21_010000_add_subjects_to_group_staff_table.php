<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which subjects a teacher teaches in ONE class (owner, 2026-09-21).
 *
 * At the BISS teacher meeting the owner told teachers each would see only the
 * tabs for their own subject: an Arabic teacher gets the letters and no Qur'an,
 * a Qur'an teacher the reverse, an Islamic Studies teacher neither. Sunday School
 * splits one class across three teachers; the full-time school does not.
 *
 * NULL means every subject, and every row that exists today is NULL, so a
 * full-time school's teacher — who teaches the whole class — sees exactly what
 * they saw before. Only an assignment someone narrows becomes narrow.
 *
 * Per assignment, not per teacher: the same person can teach Qur'an in one class
 * and Arabic in another, and Sunday School does exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_staff', function (Blueprint $table) {
            $table->json('subjects')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('group_staff', function (Blueprint $table) {
            $table->dropColumn('subjects');
        });
    }
};
