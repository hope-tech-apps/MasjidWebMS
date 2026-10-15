<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcript_settings', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->unique()->constrained('masjids')->restrictOnDelete();
            $t->json('points'); $t->unsignedInteger('honors_extra'); $t->unsignedInteger('ap_extra'); $t->json('failing_letters'); $t->timestamps();
        });
        Schema::create('transcript_courses', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('class_subject_id')->unique()->constrained('class_subjects')->restrictOnDelete();
            $t->unsignedInteger('credit_hundredths'); $t->string('level', 12); $t->timestamps();
        });
        Schema::create('transcript_finals', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $t->foreignId('class_subject_id')->constrained('class_subjects')->restrictOnDelete();
            $t->foreignId('school_year_id')->constrained('school_years')->restrictOnDelete();
            $t->string('letter',20)->nullable(); $t->text('reason')->nullable();
            $t->foreignId('overridden_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('overridden_at')->nullable(); $t->timestamps();
            $t->unique(['masjid_id','contact_id','class_subject_id','school_year_id'],'transcript_final_uq');
        });
        Schema::create('transcript_prior_years', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $t->text('school_name'); $t->string('year_label',100); $t->string('grade_label',60);
            $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable(); $t->json('courses');
            $t->foreignId('group_resource_id')->nullable()->constrained('group_resources')->restrictOnDelete();
            $t->foreignId('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
        });
        Schema::create('transcript_issues', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $t->uuid('serial')->unique(); $t->json('snapshot'); $t->text('pdf_path'); $t->string('pdf_sha256',64);
            $t->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('issued_at'); $t->timestamps();
        });
        Schema::create('transcript_share_requests', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $t->foreignId('receiving_masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->string('status',24); $t->text('sending_school'); $t->text('student_name'); $t->date('date_of_birth')->nullable();
            $t->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('requested_at');
            $t->string('consent_method',12)->nullable(); $t->text('consent_evidence')->nullable();
            $t->foreignId('consented_by_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $t->foreignId('consented_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('consented_at')->nullable();
            $t->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('resolved_at')->nullable();
            $t->foreignId('withdrawn_by_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $t->foreignId('issued_transcript_id')->nullable()->constrained('transcript_issues')->restrictOnDelete();
            $t->timestamps(); $t->index(['receiving_masjid_id','status'],'transcript_inbox_idx');
        });
        Schema::create('transcript_received', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            // Inert provenance/idempotency token only: no FK or reader back to the sending school.
            $t->unsignedBigInteger('source_request_id')->unique();
            $t->text('origin_school'); $t->uuid('origin_serial'); $t->timestamp('received_at');
            $t->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('snapshot'); $t->text('pdf_path'); $t->string('pdf_sha256',64); $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['transcript_received','transcript_share_requests','transcript_issues','transcript_prior_years','transcript_finals'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) throw new RuntimeException('Transcripts contain academic records. Keep the migration and switch transcripts off instead.');
        }
        foreach (['transcript_received','transcript_share_requests','transcript_issues','transcript_prior_years','transcript_finals','transcript_courses','transcript_settings'] as $table) Schema::dropIfExists($table);
    }
};
