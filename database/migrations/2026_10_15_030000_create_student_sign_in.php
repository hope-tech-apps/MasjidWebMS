<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_sign_in_settings', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->unique()->constrained('masjids')->cascadeOnDelete();
            $t->string('from_grade', 16)->default('6th'); $t->timestamps();
        });
        Schema::create('student_accounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $t->string('username', 40)->collation(\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite' ? 'nocase' : 'utf8mb4_unicode_ci'); $t->string('password')->nullable();
            $t->boolean('enabled')->default(true); $t->boolean('must_change_password')->default(true);
            $t->timestamp('last_sign_in_at')->nullable(); $t->timestamps();
            $t->unique(['masjid_id','username'], 'student_username_uq');
            $t->unique(['masjid_id','contact_id'], 'student_contact_uq');
        });
        Schema::create('student_sign_in_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $t->string('username', 40); $t->string('ip_address', 45)->nullable(); $t->text('user_agent')->nullable();
            $t->string('outcome', 24); $t->timestamp('attempted_at')->index();
        });
        Schema::create('student_work_settings', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $t->foreignId('class_assignment_id')->unique()->constrained('class_assignments')->restrictOnDelete();
            $t->boolean('students_hand_in')->default(false); $t->date('due_on')->nullable(); $t->timestamps();
        });
        Schema::create('student_hand_ins', function (Blueprint $t) {
            $t->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $t->foreignId('class_assignment_id')->constrained('class_assignments')->restrictOnDelete();
            $t->foreignId('group_membership_id')->constrained('group_memberships')->restrictOnDelete();
            $t->foreignId('student_contact_id')->constrained('contacts')->restrictOnDelete();
            $t->unsignedInteger('version'); $t->string('kind', 8); $t->text('link')->nullable();
            $t->string('disk', 64)->nullable(); $t->text('path')->nullable();
            $t->text('original_name')->nullable(); $t->string('mime_type', 190)->nullable(); $t->unsignedBigInteger('size_bytes')->nullable();
            $t->boolean('late'); $t->timestamp('submitted_at');
            $t->unique(['class_assignment_id','group_membership_id','version'], 'student_hand_in_version_uq');
        });
    }

    public function down(): void
    {
        foreach (['student_hand_ins','student_work_settings','student_sign_in_logs','student_accounts','student_sign_in_settings'] as $table) Schema::dropIfExists($table);
    }
};
