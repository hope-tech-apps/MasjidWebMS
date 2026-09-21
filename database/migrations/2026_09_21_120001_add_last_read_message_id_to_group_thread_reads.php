<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read receipts (owner, 2026-09-21): WHICH message a reader has read up to.
 *
 * `last_read_at` answers "when did they last open it", which is enough for an
 * unread dot and not enough for a receipt. Timestamps are whole seconds, so a
 * message written in the same second as somebody opened the thread would read
 * as seen when it never reached their screen, and a thread opened at page one
 * of a long conversation would mark messages they never scrolled to. The id of
 * the newest message the reader was actually SERVED is exact on both counts.
 *
 * Nullable, and nothing is backfilled: an existing bookmark has no honest
 * answer to "up to which message", so it keeps answering by time
 * (GroupMessageSignals falls back to `last_read_at`) until its reader next
 * opens the thread and the id is written.
 *
 * Deliberately NOT a foreign key. It is a high-water mark compared with `<=`,
 * not a reference that must resolve; messages only ever go with their thread,
 * whose bookmarks cascade with it; and adding a constrained column rebuilds
 * the table on SQLite, which is the rebuild that has silently dropped indexes
 * here before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_thread_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('last_read_message_id')->nullable()->after('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_thread_reads', function (Blueprint $table) {
            $table->dropColumn('last_read_message_id');
        });
    }
};
