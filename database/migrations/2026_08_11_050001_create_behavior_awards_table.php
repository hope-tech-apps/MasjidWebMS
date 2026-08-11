<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * behavior_awards — one recognition given to ONE student (PLAN T-013).
 *
 * The behaviour/recognition layer of the Classroom module, laid on top of the
 * Groups primitive rather than beside it: the student is named by their
 * `group_memberships` row, exactly as a guardian edge names its ward and a
 * participant thread names its subject. That is what makes the privacy rule
 * decidable from the row.
 *
 * ## A CHILD'S RECORD IS PRIVATE, BY DESIGN AND NOT BY PREFERENCE
 *
 * ClassDojo's loudest, most-documented complaint is that behaviour points are
 * shown publicly and shame children. Manara's differentiator is the opposite,
 * so this table is shaped to make the public version hard to build:
 *
 *   - an award is visible to the group's LEADERS, to the student themselves,
 *     and to THAT student's own guardians — nobody else, and in particular
 *     never to another guardian in the same group;
 *   - there is no class-wide total, no rank column, no leaderboard, and the
 *     read path never exposes one. Aggregates are computed per student, over
 *     rows the caller was already entitled to;
 *   - the decision lives in App\Support\GroupAudience (mayReceiveAwardsAbout /
 *     readableAwardsQuery), never in a controller, and it is applied as a QUERY
 *     CONSTRAINT so a forbidden award cannot appear in a listing at all.
 *
 * ## POINTS ARE SNAPSHOTTED
 *
 * `points`, `skill_label` and `skill_polarity` are copies taken at award time,
 * the same reasoning as the fee-plan snapshots on `registrations`: an office
 * that renames "Disruption" to "Off-task", or re-weights it from 1 to 3, is
 * describing what it wants NEXT term — it is not issuing a retroactive
 * correction to what a child was told last October. `behavior_skill_id` is kept
 * for provenance only and nulls on delete; nothing on the read path re-reads
 * the skill row for a value.
 *
 * ## DELETION
 *
 * `group_membership_id` CASCADES, deliberately differing from
 * `group_threads.about_membership_id`, which nulls. A thread is a conversation
 * between adults that stays meaningful with a shrunken audience; an award is a
 * record ABOUT the child whose entire audience is derived from that membership
 * row. With the row gone there is nobody the record could ever be disclosed to
 * — it would be unreadable data about a minor, retained forever. Least
 * disclosure says it goes with them.
 *
 * `deleted_at` IS THE REVOCATION CLOCK. Revoking an award (the teacher tapped
 * the wrong child) soft-deletes it: it leaves every listing and every total at
 * once, while `revoked_by_user_id` records who made the correction, because a
 * correction to a child's record is itself accountable. The row goes for good
 * when the retention window closes.
 *
 * Retention mirrors the feed and the threads (.claude/rules/groups.md,
 * obligation 3): `retained_until` is stamped on create from
 * config('groups.behavior.retention_days') unless the caller sets it, and the
 * SAME `groups:purge-feed` sweep force-deletes it once it passes. Rows only —
 * an award carries no bytes on disk — so, as with threads, no model-walking
 * teardown is needed.
 *
 * Tenant-scoped by masjid_id (BelongsToMasjid); MySQL has no row-level
 * security. Proven by tests/Feature/BehaviorTenantIsolationTest.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Denormalised alongside the membership so a per-group listing and
            // the purge sweep scope without joining through group_memberships —
            // the same reasoning that put masjid_id on group_memberships.
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // THE STUDENT: their own participant membership in that group. An
            // explicit edge, not a contact_id, because "this child, in this
            // class" is the unit the audience rule is decided over — the same
            // shape as group_threads.about_membership_id. See the class
            // docblock for why this cascades where that one nulls.
            $table->foreignId('group_membership_id')
                ->constrained('group_memberships')->cascadeOnDelete();

            // Provenance only. nullOnDelete: deleting the vocabulary entry must
            // not delete a child's record, and nothing on the read path needs
            // this row — the values below are the record.
            $table->foreignId('behavior_skill_id')->nullable()
                ->constrained('behavior_skills')->nullOnDelete();

            // The admin account that gave it. nullOnDelete for the same reason
            // as group_posts.author_user_id: retiring a teacher's login must not
            // erase a term of recognition.
            $table->foreignId('awarded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // ---- the snapshot: what this award WAS when it was given ----
            $table->string('skill_label');
            $table->string('skill_polarity', 32);
            $table->integer('points');

            // Optional context a teacher adds ("helped a new student settle in").
            // Text, and bounded at the request boundary — a note about a lesson,
            // not a case file.
            $table->text('note')->nullable();

            // When the recognition HAPPENED, which is not always when it was
            // typed in: a teacher entering Friday's awards on Sunday evening is
            // ordinary. Every listing and every date-range filter reads this,
            // never created_at.
            //
            // DATETIME, NOT timestamp(), and that is load-bearing. Production
            // runs MariaDB 10.11, where `explicit_defaults_for_timestamp` still
            // defaults to OFF — so the FIRST non-nullable TIMESTAMP column in a
            // table silently acquires `DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP`. Any later UPDATE that does not name the column
            // (revoking an award writes revoked_by_user_id, not this) would then
            // rewrite when the recognition happened. DATETIME carries no such
            // implicit behaviour on either driver, so the value stays what the
            // teacher said it was. Blueprint emits the right dialect for both,
            // so no driver guard is involved.
            $table->dateTime('awarded_at');

            // Who corrected the record, when `deleted_at` says one was
            // corrected. Null on a live award.
            $table->foreignId('revoked_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // After this date the row is eligible for purge. Nullable means
            // "keep until somebody decides"; the model stamps a default from
            // config so the common case is bounded without anyone remembering.
            $table->date('retained_until')->nullable();

            $table->timestamps();
            // Revocation. See the class docblock: deleted_at IS the revocation
            // clock, and only the retention purge hard-deletes.
            $table->softDeletes();

            // The per-group listing, newest first, optionally date-ranged.
            $table->index(['masjid_id', 'group_id', 'awarded_at']);
            // The per-student listing and the per-student summary — the two
            // reads this module exists for.
            $table->index(['masjid_id', 'group_membership_id', 'awarded_at']);
            // The purge sweep: due rows for one tenant, or across all of them.
            $table->index(['masjid_id', 'retained_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_awards');
    }
};
