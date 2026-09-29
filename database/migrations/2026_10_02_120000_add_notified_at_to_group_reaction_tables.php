<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notified_at on both reaction tables — the digest's "already told the author"
 * stamp (owner, 2026-09-29; T-002.2).
 *
 * `groups:notify-reactions` (hourly) tells the AUTHOR of a story or message, once,
 * in a content-free email, that there are new reactions. It claims each row by
 * stamping `notified_at` (an UPDATE guarded by `notified_at IS NULL`), so two
 * overlapping runs cannot both send, and a row is never announced twice. A
 * reaction that is taken back before the settle window closes is deleted and so
 * never counted at all.
 *
 * NULL means "not yet announced" — including every row that exists today (both
 * tables are empty in production: 0 rows measured 2026-09-28), so nothing is
 * retro-announced.
 *
 * datetime(), not timestamp(): a domain time must not get MySQL's implicit ON
 * UPDATE. The (notified_at, created_at) index serves the sweep's only query, "the
 * unannounced rows older than the settle window". Names are hand-set under 64
 * characters (MySQL refuses longer; SQLite does not).
 *
 * Additive only: two nullable columns and two indexes. Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['group_message_reactions' => 'gmr_notified_created_idx', 'group_post_reactions' => 'gpr_notified_created_idx'] as $table => $index) {
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dateTime('notified_at')->nullable()->after('contact_id');
                $blueprint->index(['notified_at', 'created_at'], $index);
            });
        }
    }

    public function down(): void
    {
        foreach (['group_message_reactions' => 'gmr_notified_created_idx', 'group_post_reactions' => 'gpr_notified_created_idx'] as $table => $index) {
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropIndex($index);
                $blueprint->dropColumn('notified_at');
            });
        }
    }
};
