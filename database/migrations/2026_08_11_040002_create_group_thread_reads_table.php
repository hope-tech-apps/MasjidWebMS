<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_thread_reads — how far one user has read one thread (PLAN T-005c).
 *
 * Minimal unread tracking: a single `last_read_at` per (thread, user), written
 * when the user views the thread. Enough for the admin SPA and the future
 * mobile app (T-015) to show an unread marker; deliberately NOT counters, NOT
 * per-message receipts, NOT push — those are their own decisions later, and a
 * bookmark that only ever moves forward in time needs none of that machinery.
 *
 * Keyed by USER, not Contact, for the same reason messages are authored by a
 * User: the only principal that can read anything today is an admin `users`
 * row. When contacts get their own login, the seam to revisit is
 * GroupAudience::identitiesFor() — not this table's shape, since whoever
 * authenticates will still be some authenticatable id with a bookmark.
 *
 * masjid_id is denormalised like group_memberships so BelongsToMasjid scopes
 * these rows without joining through threads — a read marker names WHICH thread
 * a user was reading, which is itself tenant data.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_thread_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_thread_id')->constrained()->cascadeOnDelete();
            // Cascade: a deleted account needs no bookmarks, and unlike a
            // message body a read marker records nothing anyone must keep.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->timestamps();

            // One bookmark per reader per thread — updateOrCreate's guarantee
            // at the DB level, so a race cannot mint duplicates.
            $table->unique(['group_thread_id', 'user_id']);

            // "This user's markers across the tenant" — the future unread
            // overview, already tenant-filtered.
            $table->index(['masjid_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_thread_reads');
    }
};
