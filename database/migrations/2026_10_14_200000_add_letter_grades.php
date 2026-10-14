<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** Atomic school scale and publication snapshots; existing skills remain unchanged. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->json('letter_grade_settings')->nullable();
        });
        Schema::table('report_card_marks', function (Blueprint $table) {
            $table->foreignId('class_subject_id')->nullable()->constrained('class_subjects')->restrictOnDelete();
            $table->decimal('calculated_percentage', 6, 2)->nullable();
            $table->string('calculated_letter', 20)->nullable();
            $table->string('override_letter', 20)->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('override_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_at')->nullable();
            $table->string('final_letter', 20)->nullable();
            $table->decimal('final_percentage', 6, 2)->nullable();
            $table->text('final_comment')->nullable();
            $table->timestamp('letter_snapshot_at')->nullable();
        });
        Schema::create('term_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('class_subject_id')->constrained('class_subjects')->restrictOnDelete();
            $table->foreignId('school_term_id')->constrained('school_terms')->restrictOnDelete();
            $table->foreignId('report_card_id')->constrained('report_cards')->restrictOnDelete();
            $table->string('subject', 120);
            $table->string('final_letter', 20)->nullable();
            $table->decimal('percentage', 6, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['contact_id', 'class_subject_id', 'school_term_id'], 'term_grades_student_subject_term_unique');
        });
    }

    public function down(): void
    {
        // Rollback must not destroy an issued grade or a teacher's draft. Disabling keeps both.
        if (DB::table('term_grades')->exists() || DB::table('report_card_marks')->where('kind', 'term_grade')->exists()) {
            throw new RuntimeException('Letter-grade records exist. Keep this schema and switch off letter grades instead.');
        }
        Schema::dropIfExists('term_grades');
        Schema::table('report_card_marks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_subject_id');
            $table->dropConstrainedForeignId('override_by_user_id');
            $table->dropColumn(['calculated_percentage','calculated_letter','override_letter','override_reason','override_at','final_letter','final_percentage','final_comment','letter_snapshot_at']);
        });
        Schema::table('masjids', fn (Blueprint $table) => $table->dropColumn('letter_grade_settings'));
    }
};
