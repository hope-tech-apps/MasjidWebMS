<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The newsletter layout of a broadcast's email: an ordered list of blocks
 * (heading, rich text, image, button, divider, two-image row, spacer).
 *
 * ONE JSON column on the broadcast, not a `broadcast_blocks` table. A broadcast
 * is a record of something that has already left the building — it is never
 * edited after it is composed — so the blocks are read and written only as a
 * whole, in order, together with the row they belong to. A child table would
 * add ordering columns, a second write inside the compose transaction and a
 * cross-tenant surface (a block id to guess) for no query anyone runs.
 *
 * Nullable, and NULL for every existing row: a broadcast without blocks is sent
 * through the original single-image email exactly as before
 * (BroadcastLegacyEmailUnchangedTest). `json` is MySQL's JSON type and SQLite's
 * TEXT; neither has a length that a long newsletter could overrun, which the
 * suite pins by asserting the column type rather than by a round trip SQLite
 * would pass whatever the length (.claude/rules/shipping.md).
 *
 * Images are NOT in this column. They are media rows on the broadcast
 * (collection `broadcast_blocks`), referenced from a block by a key, so the
 * bytes live on the same disk and follow the same lifecycle as the broadcast's
 * one existing image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->json('blocks')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn('blocks');
        });
    }
};
