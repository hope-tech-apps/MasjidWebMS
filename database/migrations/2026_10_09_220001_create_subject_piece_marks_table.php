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
        Schema::create('subject_piece_marks', function (Blueprint $table) {
            $table->id(); // Required by production sql_require_primary_key.
            $table->timestamps();
            $table->unsignedBigInteger('masjid_id');
            $table->unsignedBigInteger('subject_piece_id');
            $table->unsignedBigInteger('group_membership_id');
            $table->unsignedTinyInteger('level')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('shared_with_family')->default(false);
            $table->unsignedBigInteger('marked_by_user_id')->nullable();
            $table->index('masjid_id', 'sw_mark_org_idx');
            $table->index('group_membership_id', 'sw_mark_member_idx');
            $table->index('marked_by_user_id', 'sw_mark_marker_idx');
            $table->unique(['subject_piece_id', 'group_membership_id'], 'sw_mark_piece_member_unique');
            $table->foreign('masjid_id', 'sw_mark_org_fk')->references('id')->on('masjids')->cascadeOnDelete();
            $table->foreign('subject_piece_id', 'sw_mark_piece_fk')->references('id')->on('subject_pieces')->cascadeOnDelete();
            $table->foreign('group_membership_id', 'sw_mark_member_fk')->references('id')->on('group_memberships')->restrictOnDelete(); // A child's record: never destroyed with the roster row (App\Support\AcademicRecordsHeld).
            $table->foreign('marked_by_user_id', 'sw_mark_marker_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_piece_marks');
    }
};
