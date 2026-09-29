<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_post_reads — which guardian OPENED which class story post
 * (owner, 2026-09-29; "Seen by 4 of 7 parents").
 *
 * ONE ROW PER (post, guardian). Written by insertOrIgnore from a client POST
 * fired only when the Story tab is showing the posts — never from the /posts GET,
 * which the portal fires on page load whatever tab is open, and which returns the
 * 15 newest posts (a GET-side write would mark them all "seen" when a parent only
 * opened Grades). `first_seen_at` is the FIRST time; a repeat is ignored, so the
 * unique key is what makes the write idempotent.
 *
 * Only a guardian Contact reads through the family portal, so there is no
 * user_id: a staff member's reading is never recorded here.
 *
 * NOT written while `groups.story_reads.enabled` is off (the default): the
 * parent-facing notice that this is recorded ships with the switch.
 *
 * `first_seen_at` is a datetime(), not a timestamp(): a domain time must not get
 * MySQL's implicit ON UPDATE (see create_behavior_awards_table). Stored UTC.
 *
 * CASCADES, all of them: a read goes with its post (retention force-delete) and
 * with a deleted contact. Nothing hangs off it.
 *
 * Every composite index is named by hand at 64 characters or fewer (MySQL refuses
 * longer; SQLite does not). Additive only: a new table. Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_post_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_post_id')->constrained('group_posts')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->dateTime('first_seen_at');
            $table->timestamps();

            $table->unique(['group_post_id', 'contact_id'], 'group_post_reads_post_contact_unique');
            $table->index(['masjid_id', 'group_post_id'], 'group_post_reads_masjid_post_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_post_reads');
    }
};
