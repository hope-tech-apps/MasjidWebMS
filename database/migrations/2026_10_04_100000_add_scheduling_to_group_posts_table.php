<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled class stories (T-002.4, owner 2026-09-29): a teacher writes a story now
 * and it reaches families later.
 *
 * FOUR NULLABLE COLUMNS ON group_posts, and what each one means:
 *
 *   published_at       WHEN FAMILIES MAY SEE IT. Every family read goes through
 *                      GroupPost::scopePublished(), which asks `published_at <= now`, so
 *                      a story in the future is invisible by the clock, whether or not
 *                      any sweep has run. An ordinary post is stamped "now" by the model.
 *   announced_at       WHEN THE CLASS-STORY EMAIL WAS DISPATCHED. NULL = not yet. The
 *                      `groups:publish-due` sweep claims a due story with an UPDATE
 *                      guarded by `announced_at IS NULL`, so it is announced at most once.
 *   publish_failed_at  a scheduled story the sweep refused to release (its author no
 *   publish_failure    longer teaches the class). scopePublished() excludes it whatever
 *                      the clock says, so a failed story never becomes visible when its
 *                      time passes. `publish_failure` is the reason the teacher is shown.
 *
 * NULLABLE, NOT NOT NULL DEFAULT (a deviation from the first plan, RECON-PLAN section
 * 3.1 rule 4): a NOT NULL `published_at` needs `->change()` on a live table, which
 * SQLite rebuilds; and during the deploy seconds an old-code INSERT that knows nothing
 * of the column must still succeed. The model always sets it, and the scope reads a NULL
 * as "published" (a legacy row), so an old-code insert is visible at once, exactly as it
 * was before this migration.
 *
 * BACKFILL: `published_at` and `announced_at` both become `created_at` on every existing
 * row, soft-deleted ones included (the query builder does not apply the SoftDeletes
 * scope). `announced_at` is what stops the ten live stories from being announced a second
 * time by the first sweep. A row whose `created_at` is NULL is left NULL.
 *
 * datetime(), not timestamp(): a domain time must not pick up MySQL's implicit ON UPDATE.
 * Both index names are hand-set at 64 characters or fewer: MySQL refuses longer and SQLite
 * does not. The backfill is one column-to-column UPDATE through the query builder, so it
 * runs unchanged on both drivers and needs no guard.
 *
 * Additive only. down() drops the indexes and columns and touches no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_posts', function (Blueprint $table) {
            $table->dateTime('published_at')->nullable()->after('body');
            $table->dateTime('announced_at')->nullable()->after('published_at');
            $table->dateTime('publish_failed_at')->nullable()->after('announced_at');
            $table->string('publish_failure', 255)->nullable()->after('publish_failed_at');

            // Every family read: "this class's stories that are out, newest first".
            $table->index(['masjid_id', 'group_id', 'published_at'], 'group_posts_masjid_group_published_idx');
            // The sweep's one question: "not yet announced, and due".
            $table->index(['announced_at', 'published_at'], 'group_posts_announce_due_idx');
        });

        DB::table('group_posts')
            ->whereNull('published_at')
            ->whereNotNull('created_at')
            ->update([
                'published_at' => DB::raw('created_at'),
                'announced_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('group_posts', function (Blueprint $table) {
            $table->dropIndex('group_posts_masjid_group_published_idx');
            $table->dropIndex('group_posts_announce_due_idx');
            $table->dropColumn(['published_at', 'announced_at', 'publish_failed_at', 'publish_failure']);
        });
    }
};
