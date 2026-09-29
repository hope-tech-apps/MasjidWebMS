<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_post_reactions — 🤲 👍 💯 ❓ on a CLASS STORY post (owner, 2026-09-29).
 *
 * The story-side twin of group_message_reactions, and deliberately the same
 * shape (see 2026_09_21_120000): ONE ROW PER (post, reaction, person); the
 * person is a staff User or a guardian Contact, never both (enforced in the
 * model, because a CHECK would exist on only one of the two drivers); TWO
 * UNIQUE KEYS, one per principal column, where the NULLs do the partitioning
 * (NULLs are distinct in a unique index on MySQL and SQLite alike, so no
 * generated column and no partial index is needed).
 *
 * `reaction` is a short KEY from App\Support\Reactions, never the emoji.
 *
 * CASCADES, all of them. A reaction records nothing anyone must keep: it goes
 * with its post (retention purge force-deletes the post; a soft delete keeps
 * the row but the post is invisible, so nothing is served), with a deleted
 * staff account, and with a deleted contact. Nothing hangs off it.
 *
 * Every composite index is named by hand, at 64 characters or fewer: MySQL
 * refuses longer names and SQLite does not, so the suite would stay green and
 * production would fail. GroupPostReactionsTest asserts the length.
 *
 * Additive only: a new table, no existing column, index or row touched.
 * Blueprint only, no raw SQL, no driver guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_post_id')->constrained('group_posts')->cascadeOnDelete();
            $table->string('reaction', 32);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_post_id', 'reaction', 'user_id'], 'group_post_reactions_post_user_unique');
            $table->unique(['group_post_id', 'reaction', 'contact_id'], 'group_post_reactions_post_contact_unique');
            $table->index(['masjid_id', 'group_post_id'], 'gpr_masjid_post_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_post_reactions');
    }
};
