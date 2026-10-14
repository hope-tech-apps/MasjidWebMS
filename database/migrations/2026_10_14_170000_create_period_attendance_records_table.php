<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved meeting snapshots have no FK to the editable timetable; roster retention matches day marks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->date('session_date');
            $table->unsignedBigInteger('meeting_id');
            $table->foreignId('student_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('group_membership_id')->constrained('group_memberships')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->text('class_name');
            $table->unsignedBigInteger('period_id');
            $table->text('period_name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedBigInteger('class_subject_id')->nullable();
            $table->text('subject_name');
            $table->json('teacher_ids');
            $table->unsignedBigInteger('first_scheduled_meeting_id');
            $table->time('first_scheduled_starts_at');
            $table->string('status',16);
            $table->text('note')->nullable();
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['masjid_id','session_date','meeting_id','student_contact_id'], 'period_attendance_cell_unique');
            $table->index(['masjid_id','group_id','session_date'], 'period_attendance_class_day_idx');
            $table->index(['masjid_id','student_contact_id','session_date'], 'period_attendance_child_day_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('period_attendance_records'); }
};
