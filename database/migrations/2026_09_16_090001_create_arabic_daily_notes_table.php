<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One teacher's note about one child's Arabic on one day.
 *
 * WHY PER-STUDENT AND NOT PER-CLASS
 * ---------------------------------
 * The owner's decision, 2026-09-16: "daily Arabic note belongs to one student".
 * It is also the direction that keeps options open — per-student notes roll up
 * into a class view whenever somebody wants one, and a class-level note can
 * never be split back into the children it was about.
 *
 * WHY A TABLE AND NOT A COLUMN
 * ----------------------------
 * Unlike the per-drill note, this is not a property of any existing record. It
 * is about a DAY, and a day has no row in this module until somebody writes
 * about it. The shape is copied deliberately from `attendance_records`, which
 * already answers "one fact about one child on one day" in this module — same
 * columns, same unique key, same index names in the same style. A second shape
 * for the same question is how two screens end up disagreeing about what a day
 * is.
 *
 * UNIQUE ON (student, date), SO A SECOND SAVE EDITS
 * -------------------------------------------------
 * A teacher writing twice about today means they are correcting themselves, not
 * recording two days. The upsert relies on this key; without it the second save
 * silently produces a duplicate and the screen shows whichever the database
 * returned first.
 *
 * ABSENCE OF A ROW MEANS NOTHING WAS WRITTEN — never "no progress". The same
 * rule the register and the gradebook already hold in this module: blank is not
 * a status and blank is not a zero.
 *
 * Index names are given BY HAND. MySQL caps identifiers at 64 characters and
 * SQLite does not, so a generated name long enough to be rejected in production
 * passes every local test first. This repository has had a migration die
 * half-way through for exactly that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arabic_daily_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_membership_id')
                ->constrained('group_memberships')
                ->cascadeOnDelete();

            // Nullable on purpose, and nulled rather than cascaded when a staff
            // login is retired: the note is a record about a child and outlives
            // whoever typed it. `attendance_records` makes the same choice.
            $table->foreignId('marked_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('session_date');
            $table->text('note');
            $table->timestamps();

            $table->unique(['group_membership_id', 'session_date'], 'arabic_note_student_day_unique');
            $table->index(['masjid_id', 'group_id', 'session_date'], 'arabic_note_class_day_idx');
            $table->index(['masjid_id', 'group_membership_id', 'session_date'], 'arabic_note_student_hist_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arabic_daily_notes');
    }
};
