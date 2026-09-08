<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One child's report for one reporting period.
 *
 * TWO DOCUMENTS, ONE TABLE. A progress report and a report card ask the same
 * questions of the same child on the same scale; what differs is WHEN in the
 * quarter they are issued and how final they are. Modelling them as separate
 * tables would duplicate every column and every query, and would guarantee the
 * two drift. `type` is the difference.
 *
 * ---------------------------------------------------------------------------
 * DRAFT UNTIL PUBLISHED, and publishing is the disclosure
 * ---------------------------------------------------------------------------
 *
 * A teacher fills a card in over days. `published_at` is what makes it visible
 * to the family, and it is null by default — so a half-finished card, or one
 * carrying a comment a teacher is still wording, is never something a parent can
 * open. This is the same shape as the group post feed: written by staff, seen by
 * families only once someone decides it is ready.
 *
 * Once published the card is a document a family keeps. That is why the marks
 * table stores the subject and criterion as TEXT rather than as a reference into
 * a template that can be reworded later — see App\Support\ReportCardTemplate.
 *
 * ---------------------------------------------------------------------------
 * The attendance figures are SNAPSHOTTED, not joined
 * ---------------------------------------------------------------------------
 *
 * `days_present` / `days_absent` / `days_late` are copied in at publication.
 * Recomputing them on every read would mean a card issued in November silently
 * changing its own attendance figures in March as the register fills in — a
 * document that rewrites itself is not a record. A card still in draft
 * recomputes, because it has not been issued to anybody yet.
 *
 * ---------------------------------------------------------------------------
 * Identity
 * ---------------------------------------------------------------------------
 *
 * Addressed by the child's PARTICIPANT MEMBERSHIP, not by contact id: a child
 * who moves classrooms mid-year has a second membership, and their first
 * quarter's card belongs to the class that issued it. The unique index is
 * therefore (group_membership_id, school_year, term, type) — one progress report
 * and one report card per child per quarter, and a second attempt is an UPDATE
 * rather than a duplicate.
 *
 * Composite index names are hand-written and under 64 characters. MySQL's
 * identifier limit truncated an auto-generated name on this codebase before and
 * aborted a migration HALFWAY on production, after the whole suite had passed on
 * SQLite, which has no such limit. See .claude/rules/migrations.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_cards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('group_membership_id')->constrained('group_memberships')->cascadeOnDelete();

            // 'progress' (mid-quarter) or 'report_card' (end of quarter).
            // A varchar, not an enum: a third kind of report must be a code
            // change, never an ALTER TABLE on a live table.
            $table->string('type', 24);

            // '2026-2027'. Stored as text because a school year spans two
            // calendar years and any single-year integer is a lie for half of it.
            $table->string('school_year', 9);

            // 1-4. Al-Razi runs four quarters.
            $table->unsignedTinyInteger('term');

            // The child's grade AT THE TIME, snapshotted for the same reason the
            // criteria are: a card must keep saying what it said.
            $table->string('grade_label', 40)->nullable();

            $table->text('teacher_comment')->nullable();

            // Snapshotted at publication — see the docblock.
            $table->unsignedSmallInteger('days_present')->nullable();
            $table->unsignedSmallInteger('days_absent')->nullable();
            $table->unsignedSmallInteger('days_late')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['group_membership_id', 'school_year', 'term', 'type'],
                'report_card_period_unique'
            );

            // The teacher's own screen: "show me this class's cards for Q2".
            $table->index(['group_id', 'school_year', 'term'], 'report_card_class_period_idx');

            // The tenant boundary, on the column every scoped query filters by.
            $table->index(['masjid_id', 'published_at'], 'report_card_masjid_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cards');
    }
};
