<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_messages.edited_at — when the author last changed the words of a sent
 * message (W7, 2026-10-01).
 *
 * NULL means "never edited", which is every row that exists today, so there is
 * no backfill and no default: the column is nullable, unindexed, and adding it
 * is a metadata change on MySQL 8.4. Code that reads it tolerates the column
 * not existing yet (a deploy runs the new PHP for a few seconds before
 * `migrate`), and the one write that needs it refuses with a 503 until it does
 * (GroupThreadsController::updateMessage).
 *
 * The earlier wording lives in group_message_edits (the next migration), never
 * here: this column is only the marker a reader sees.
 *
 * Blueprint only — no raw SQL, no driver guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
