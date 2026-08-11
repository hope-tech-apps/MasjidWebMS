<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_threads — one conversation inside a group (PLAN T-005c).
 *
 * The teacher <-> parent channel of the ClassDojo-style feature set. A thread
 * carries a subject and a SCOPE, and the scope is the disclosure decision:
 *
 *   - `group`       — announcement-discussion, addressed to the same audience
 *                     as the private feed (leaders, members, and consented
 *                     guardians — decided per read by App\Support\GroupAudience);
 *   - `participant` — a private leader <-> guardian/member conversation about
 *                     ONE member, readable only by the group's leaders and the
 *                     specific person it concerns.
 *
 * `scope` is a plain string, NOT a DB enum: GroupThread::SCOPES is the
 * authority, validated at the request boundary — adding a scope must never mean
 * `ALTER TABLE … MODIFY` on a live table (.claude/rules/migrations.md).
 *
 * THE TARGET IS AN EXPLICIT EDGE, mirroring guardianship. "About one member"
 * would be ambiguous as prose, so `about_membership_id` names the member's own
 * participant membership row in THIS group — the same reason a guardian row
 * names its ward. The invariant (participant threads MUST carry a target,
 * group-wide threads MUST NOT) is enforced at the request boundary
 * (StoreGroupThreadRequest) and in GroupThreadsController. nullOnDelete, not
 * cascade: removing a member from the roster must not destroy the record of
 * what was discussed about them — the thread survives, degraded to
 * leaders-only, because GroupAudience fails CLOSED on a null target.
 *
 * Tenant-scoped by masjid_id; MySQL has no row-level security, so isolation is
 * App\Models\Concerns\BelongsToMasjid, proven by
 * tests/Feature/GroupMessagingTenantIsolationTest.
 *
 * RETENTION mirrors the feed (.claude/rules/groups.md, obligation 3):
 * `retained_until` is stamped on create from
 * config('groups.messaging.retention_days') unless the caller sets it, and the
 * same `groups:purge-feed` sweep force-deletes the row once it passes. Unlike
 * the feed there are NO bytes here (attachments are deliberately out of this
 * slice), so the DB cascade onto group_messages is sufficient and safe.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // The admin account that opened the conversation. nullOnDelete for
            // the same reason as group_posts.author_user_id: retiring a
            // teacher's login must not delete a year of parent conversations.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('subject');

            // `group` or `participant` — GroupThread::SCOPES is the authority.
            $table->string('scope', 32);

            // Only on a participant-scoped thread: the participant membership
            // row (in group_memberships) of the member this conversation is
            // about. See the class docblock for why this is an explicit edge
            // and why it nulls rather than cascades.
            $table->foreignId('about_membership_id')->nullable()
                ->constrained('group_memberships')->nullOnDelete();

            // A closed thread takes no further messages until reopened. State,
            // not deletion — the conversation stays readable.
            $table->timestamp('closed_at')->nullable();

            // After this date the thread (and, by cascade, its messages) is
            // eligible for purge. Nullable means "keep until somebody decides";
            // the model stamps a default from config so the common case is
            // bounded without anyone remembering.
            $table->date('retained_until')->nullable();

            $table->timestamps();
            // A mis-click must not destroy a conversation about a child — same
            // reasoning as group_posts. Only the retention purge hard-deletes.
            $table->softDeletes();

            // The list read: this tenant's group's threads, optionally by scope.
            $table->index(['masjid_id', 'group_id', 'scope']);
            // The purge sweep: due rows for one tenant, or across all of them.
            $table->index(['masjid_id', 'retained_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_threads');
    }
};
