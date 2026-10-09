<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// New tables: CREATE TABLE takes metadata locks, but rewrites no existing rows.
// All indexes and foreign keys are explicitly named below MySQL's 64-character limit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_pieces', function (Blueprint $table) {
            $table->id(); // Required by production sql_require_primary_key.
            $table->timestamps(6); // Preserve the order of marking saves within a second.
            $table->unsignedBigInteger('masjid_id');
            $table->unsignedBigInteger('class_subject_id');
            $table->string('source', 8);
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->string('grade_label', 32)->nullable();
            $table->unsignedTinyInteger('week_no')->nullable();
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->string('standard_code', 32)->nullable();
            $table->unsignedBigInteger('lesson_plan_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->index('masjid_id', 'sw_piece_org_idx');
            $table->index('lesson_plan_id', 'sw_piece_plan_idx');
            $table->index('created_by_user_id', 'sw_piece_creator_idx');
            $table->unique(['class_subject_id', 'grade_label', 'week_no'], 'sw_piece_guide_unique');
            $table->unique(['class_subject_id', 'lesson_plan_id'], 'sw_piece_plan_unique');
            $table->foreign('masjid_id', 'sw_piece_org_fk')->references('id')->on('masjids')->cascadeOnDelete();
            $table->foreign('class_subject_id', 'sw_piece_subject_fk')->references('id')->on('class_subjects')->cascadeOnDelete();
            $table->foreign('lesson_plan_id', 'sw_piece_plan_fk')->references('id')->on('lesson_plans')->nullOnDelete();
            $table->foreign('created_by_user_id', 'sw_piece_creator_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_pieces');
    }
};
