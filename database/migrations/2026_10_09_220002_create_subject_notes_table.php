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
        Schema::create('subject_notes', function (Blueprint $table) {
            $table->id(); // Required by production sql_require_primary_key.
            $table->timestamps();
            $table->unsignedBigInteger('masjid_id');
            $table->unsignedBigInteger('class_subject_id');
            $table->unsignedBigInteger('group_membership_id')->nullable();
            $table->text('body');
            $table->boolean('shared_with_family')->default(false);
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->index('masjid_id', 'sw_note_org_idx');
            $table->index(['class_subject_id', 'created_at', 'id'], 'sw_note_subject_created_idx');
            $table->index('group_membership_id', 'sw_note_member_idx');
            $table->index('author_user_id', 'sw_note_author_idx');
            $table->foreign('masjid_id', 'sw_note_org_fk')->references('id')->on('masjids')->cascadeOnDelete();
            $table->foreign('class_subject_id', 'sw_note_subject_fk')->references('id')->on('class_subjects')->cascadeOnDelete();
            $table->foreign('group_membership_id', 'sw_note_member_fk')->references('id')->on('group_memberships')->cascadeOnDelete();
            $table->foreign('author_user_id', 'sw_note_author_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_notes');
    }
};
