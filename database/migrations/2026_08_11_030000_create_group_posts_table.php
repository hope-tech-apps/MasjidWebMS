<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_posts — the private activity feed of ONE group (PLAN T-005b).
 *
 * The "class story" surface: a leader writes what happened in today's lesson,
 * optionally with photographs, and the people entitled to that group see it.
 * NOTHING here is public. Disclosure is decided per read by App\Support\
 * GroupAudience: leaders and members of the group, plus the guardians of its
 * participants whose consent covers the disclosure. See .claude/rules/groups.md
 * ("Least disclosure by default").
 *
 * Tenant-scoped by masjid_id. MySQL has no row-level security, so isolation is
 * enforced in the app layer by App\Models\Concerns\BelongsToMasjid and proved by
 * tests/Feature/GroupFeedTenantIsolationTest.
 *
 * AUTHOR IS A USER, NOT A CONTACT. A Contact cannot authenticate anywhere in
 * this application — there is no congregant guard — so the only principal that
 * can actually perform a write is an admin `users` row, and attributing a post
 * to a Contact would record a claim the server never verified. Content about
 * minors must be attributable to the account that produced it. The column is
 * nullable and nulls on delete so that retiring a staff account neither destroys
 * a group's history nor blocks the row from being read.
 *
 * RETENTION is a column, not a wish: `retained_until` is stamped on create from
 * config('groups.feed.retention_days') unless the caller sets it, and
 * `groups:purge-feed` force-deletes the row once it passes — through the MODEL,
 * so the attachment rows' own deleting hooks reach the disk. A DB-level cascade
 * fires no model events and would orphan the bytes forever
 * (.claude/rules/private-uploads.md).
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // The admin account that wrote it. nullOnDelete, not cascade: losing
            // a teacher's login must not delete a term of class updates.
            $table->foreignId('author_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Optional headline. A one-line note ("we finished Surah al-Fil")
            // needs no title, so this stays nullable rather than forcing an
            // empty string into every row.
            $table->string('title')->nullable();
            $table->text('body');

            // After this date the post is eligible for purge. Nullable means
            // "keep until somebody decides"; the model stamps a default from
            // config so the common case is bounded without anyone remembering.
            $table->date('retained_until')->nullable();

            $table->timestamps();
            // A mis-click must not destroy a class's history — same reasoning as
            // `groups` itself. Soft delete keeps the bytes; only the purge
            // reaches the disk.
            $table->softDeletes();

            // Every read is "this tenant's group's feed, newest first".
            $table->index(['masjid_id', 'group_id', 'created_at']);
            // The purge sweep: due rows for one tenant, or across all of them.
            $table->index(['masjid_id', 'retained_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_posts');
    }
};
