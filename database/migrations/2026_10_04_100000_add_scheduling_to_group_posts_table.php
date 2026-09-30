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
 *   published_at       WHEN IT IS DUE. Every family read goes through
 *                      GroupPost::scopePublished(), which asks `published_at <= now` AND
 *                      `announced_at IS NOT NULL` (since the review-fix round): a
 *                      scheduled story is out only once the sweep has announced it, so a
 *                      sweep that has not run delays a story and can never leak one. An
 *                      ordinary post is stamped "now" and announced by the model.
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
 * SQLite rebuilds; and after a CODE-ONLY rollback, old code that knows nothing of the
 * column must still insert. The model always sets it, and the scope reads a NULL as
 * "published" (a legacy row).
 *
 * THE DEPLOY WINDOW runs the other way (the point's W5 review, b): bin/deploy checks out
 * the NEW code before it migrates, so for a few seconds new code runs on the OLD schema
 * and every family read that selects `published_at` fails until this migration finishes.
 * Ship it after school hours (groups.md, DECISIONS).
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
        // Dropping these columns makes every waiting story visible at once (the scope
        // goes back to "everything"), so refuse while any story is still waiting or due
        // and unannounced (the point's W5 review, a). Cancel or publish them first.
        $waiting = DB::table('group_posts')
            ->whereNull('deleted_at')
            ->whereNull('announced_at')
            ->whereNull('publish_failed_at')
            ->whereNotNull('published_at')
            ->count();

        if ($waiting > 0) {
            throw new \RuntimeException("Refusing to roll back scheduled stories: {$waiting} story(ies) still waiting to go out would become visible at once. Cancel or publish them first.");
        }

        Schema::table('group_posts', function (Blueprint $table) {
            $table->dropIndex('group_posts_masjid_group_published_idx');
            $table->dropIndex('group_posts_announce_due_idx');
            $table->dropColumn(['published_at', 'announced_at', 'publish_failed_at', 'publish_failure']);
        });
    }
};
