<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attendance_records — one register cell: this student, this day, this mark.
 *
 * ## Why this is shaped like the letter tracker, not like awards
 *
 * `behavior_awards` and `hifz_entries` are append-only EVENTS: a thing happened,
 * it is recorded, it is never edited. Attendance is not that. A child marked
 * absent at 9:05 walks in at 9:20 and the same cell becomes `late` — the register
 * is CORRECTED IN PLACE. Exactly one existing table already has that shape, and
 * its migration says so (add_avatars_and_arabic_letter_progress, on the
 * per-drill unique index): "One row per drill per student. This is what makes
 * marking idempotent: the tracker upserts against it rather than counting on the
 * client never double-tapping." This table makes the same claim with
 * `session_date` in place of `drill_id`.
 *
 * That is also why there are NO soft deletes here, mirroring
 * ArabicLetterProgress: a register cell is never destroyed, only moved between
 * statuses. Deleting the roster row deletes the register with it, by cascade —
 * a child's attendance has no meaning detached from their enrolment.
 *
 * ## Why `group_membership_id` and never `contact_id`
 *
 * GroupAudience decides who may read a record about a child by reading the
 * SUBJECT ROW directly — `mayReceiveRecordAbout()` checks the membership's role
 * against PARTICIPANT_ROLES and its group_id against the group, and the query
 * half does `whereHas('membership', ...)`. That file states the contract: "Every
 * model passed here MUST expose its subject as a `membership` relation to a
 * `group_memberships` row." A `contact_id` here would (a) force re-deriving the
 * membership on every authorization check, (b) make "attendance about a
 * GUARDIAN" expressible rather than refusable, and (c) break the cascade that
 * bounds a child's register to their enrolment. Awards, ḥifẓ and the letter
 * tracker all made this same call.
 *
 * ## `session_date` is a DATE, and it is not `created_at`
 *
 * The day the class MET, as a calendar day in the school's own reckoning — not a
 * moment, and not the server's day. A teacher entering Tuesday's register on
 * Thursday evening is ordinary, so every listing and every filter reads this
 * column and never `created_at`. Being a DATE rather than a TIMESTAMP also means
 * the MariaDB `explicit_defaults_for_timestamp` trap recorded in the
 * behavior_awards docblock cannot arise here: this table declares no TIMESTAMP
 * column of its own beyond Laravel's own two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Denormalised beside the membership so one class's register for one
            // day scopes without joining through group_memberships — the same
            // reasoning that put group_id on behavior_awards and hifz_entries.
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // THE STUDENT: their own participant membership in this class.
            $table->foreignId('group_membership_id')
                ->constrained('group_memberships')
                ->cascadeOnDelete();

            // Who last set this cell. nullOnDelete for the same reason as
            // arabic_letter_progress.marked_by_user_id: retiring a teacher's
            // login must not erase a term of a child's attendance.
            $table->foreignId('marked_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->date('session_date');

            // present | absent | late | excused. PHP constants on
            // AttendanceRecord, never a DB enum — adding `remote` later must be
            // a write, not an ALTER TABLE ... MODIFY on a live table
            // (.claude/rules/migrations.md, and the same call made by
            // hifz_entries.kind and GroupMembership::ROLES).
            $table->string('status', 16);

            // Optional context: "left at 11 for a dentist appointment".
            $table->text('note')->nullable();

            $table->timestamps();

            // One row per student per day. This is what makes the register
            // idempotent: Save upserts against it, so a double-tap on a phone
            // with a bad connection cannot mint a second, contradictory mark.
            $table->unique(['group_membership_id', 'session_date'], 'attendance_student_day_unique');

            // The only two reads: today's class register, and one child's history.
            //
            // BOTH names are given explicitly because Laravel's generated name for
            // the second one — attendance_records_masjid_id_group_membership_id_
            // session_date_index, 67 characters — is over MySQL's 64-character
            // identifier limit, and MySQL refuses it with errno 1059. SQLite has no
            // such limit, so the test suite went green and the failure appeared for
            // the first time against the live MySQL. Any index added here later
            // needs a hand-written name for the same reason.
            $table->index(['masjid_id', 'group_id', 'session_date'], 'attendance_class_day_idx');
            $table->index(['masjid_id', 'group_membership_id', 'session_date'], 'attendance_student_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
