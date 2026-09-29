<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * behavior_weeks: one row per (class, points week) once that week's report has
 * been claimed for sending (T-003.3, the Friday points report).
 *
 * THE ROW IS THE SEND CLAIM. `points:weekly-report` inserts it (ignoring a
 * duplicate) and then runs `UPDATE ... SET report_sent_at = now() WHERE id = ?
 * AND report_sent_at IS NULL`; only the process that changed a row goes on to
 * send, so two overlapping runs, a retried run and a second server cannot mail a
 * family twice. That is at-most-once on purpose: a crash between the claim and the
 * mail loses one week's notice rather than repeating it, and the portal report is
 * there whichever happened.
 *
 * It holds NO data about a child: a date, a timestamp and a count. So it needs no
 * retention window, no erasure hook and no RESTRICT to `group_memberships`
 * (.claude/rules/migrations.md rule 10 is about academic records). The cascades are
 * to the organisation and the class, and go with them.
 *
 * `week_start` is the first LOCAL day of the points week ('Y-m-d', written by the
 * command as a plain string so SQLite and MySQL store the same thing). The times
 * are `datetime()`, not `timestamp()` (rule 7: no implicit ON UPDATE), stored UTC.
 * Additive, Blueprint only, index named by hand (rule 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->date('week_start');
            $table->dateTime('report_sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'week_start'], 'behavior_weeks_group_week_unique');
            $table->index(['masjid_id', 'week_start'], 'behavior_weeks_masjid_week_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_weeks');
    }
};
