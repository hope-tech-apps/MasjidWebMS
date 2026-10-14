<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Office intake records reference contacts, never mutable roster membership IDs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_admission_settings', function (Blueprint $table) {
            $table->id(); $table->foreignId('masjid_id')->unique()->constrained('masjids')->cascadeOnDelete();
            $table->json('application_form_ids')->nullable();
            $table->text('accepted_text')->nullable(); $table->text('wait_listed_text')->nullable(); $table->text('declined_text')->nullable();
            $table->timestamps();
        });
        Schema::create('school_reenrolments', function (Blueprint $table) {
            $table->id(); $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->restrictOnDelete();
            $table->date('opens_on'); $table->date('closes_on');
            // Removing the optional form must not remove a family's recorded answer.
            $table->foreignId('deposit_form_id')->nullable()->constrained('forms')->nullOnDelete();
            $table->timestamps(); $table->unique(['masjid_id', 'school_year_id'], 'reenrol_year_uq');
        });
        Schema::create('school_reenrolment_answers', function (Blueprint $table) {
            $table->id(); $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('reenrolment_id')->constrained('school_reenrolments')->cascadeOnDelete();
            $table->foreignId('student_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('answer', 20)->nullable(); $table->string('next_grade', 80)->nullable();
            $table->foreignId('answered_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('recorded_by_office')->default(false); $table->timestamp('answered_at')->nullable();
            $table->timestamps(); $table->unique(['reenrolment_id', 'student_contact_id'], 'reenrol_child_uq');
        });
        Schema::create('school_reenrolment_deposits', function (Blueprint $table) {
            $table->id(); $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('reenrolment_id')->constrained('school_reenrolments')->cascadeOnDelete();
            $table->foreignId('student_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('form_response_id')->unique()->constrained('form_responses')->cascadeOnDelete();
            $table->timestamps(); $table->index(['reenrolment_id', 'student_contact_id'], 'reenrol_deposit_child_idx');
        });
        Schema::create('school_applications', function (Blueprint $table) {
            $table->id(); $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('form_response_id')->unique()->constrained('form_responses')->cascadeOnDelete();
            $table->string('status', 20)->default('new'); $table->timestamps();
        });
        Schema::create('school_application_histories', function (Blueprint $table) {
            $table->id(); $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('form_response_id')->constrained('form_responses')->cascadeOnDelete();
            $table->string('status', 20); $table->text('note')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('emailed')->default(false); $table->timestamps();
        });
        Schema::create('school_admission_mails', function (Blueprint $table) {
            $table->id(); $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('reenrolment_id')->nullable()->constrained('school_reenrolments')->cascadeOnDelete();
            $table->foreignId('application_history_id')->nullable()->constrained('school_application_histories')->cascadeOnDelete();
            // Delivery history survives login/actor removal; explicit contact erasure severs attribution.
            $table->foreignId('guardian_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guardian_email'); $table->json('children')->nullable();
            $table->text('subject'); $table->longText('body'); $table->string('status', 20)->default('queued');
            $table->timestamp('queued_at')->nullable(); $table->timestamp('attempted_at')->nullable(); $table->timestamp('sent_at')->nullable(); $table->timestamp('failed_at')->nullable();
            $table->string('exception_class')->nullable(); $table->timestamps();
            $table->index(['masjid_id', 'kind', 'status'], 'admission_mail_queue_idx');
            $table->unique(['application_history_id', 'guardian_email'], 'application_mail_recipient_uq');
        });
    }

    public function down(): void
    {
        $tables = ['school_admission_mails', 'school_application_histories', 'school_applications', 'school_reenrolment_deposits', 'school_reenrolment_answers', 'school_reenrolments', 'school_admission_settings'];
        foreach ($tables as $table) if (Schema::hasTable($table) && \Illuminate\Support\Facades\DB::table($table)->exists()) throw new RuntimeException('Refusing to roll back populated school admissions records.');
        foreach ($tables as $table) Schema::dropIfExists($table);
    }
};
