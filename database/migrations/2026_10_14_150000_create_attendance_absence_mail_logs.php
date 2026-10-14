<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Durable mail claims: one guardian/day batch and one guardian/student/date audit row before delivery. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_points_settings', function (Blueprint $table) {
            $table->string('attendance_absence_send_time', 5)->nullable();
            $table->date('attendance_absence_last_date')->nullable();
        });
        Schema::create('attendance_absence_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('guardian_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('guardian_email');
            $table->date('attendance_date');
            $table->string('status', 16);
            $table->timestamp('queued_at');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('exception_class')->nullable();
            $table->timestamps();
            $table->unique(['guardian_contact_id', 'attendance_date'], 'absence_guardian_day_unique');
            $table->index(['masjid_id', 'attendance_date'], 'absence_school_day_idx');
        });
        Schema::create('attendance_absence_mail_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('mail_id')->constrained('attendance_absence_mails')->cascadeOnDelete();
            $table->foreignId('guardian_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('student_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('child_name')->nullable();
            $table->json('classes');
            $table->timestamps();
            $table->unique(['guardian_contact_id', 'student_contact_id', 'attendance_date'], 'absence_guardian_student_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_absence_mail_children');
        Schema::dropIfExists('attendance_absence_mails');
        Schema::table('masjid_points_settings', fn (Blueprint $table) => $table->dropColumn(['attendance_absence_send_time', 'attendance_absence_last_date']));
    }
};
