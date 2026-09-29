<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * NULL means "not yet announced". A row that exists when this migration runs was
 * made BEFORE the digest existed, so up() stamps it `notified_at = created_at`:
 * without that, the first run after the deploy would announce every reaction
 * ever made, however old. (Production held 0 rows in both tables when measured
 * 2026-09-28, so today the backfill is a no-op there; it is what keeps a
 * different database, or a reaction made between that measurement and the deploy,
 * from a first-run flood.) `created_at`, not "now": the row then records when the
 * reaction was made, and the stamp cannot be mistaken for an announcement that
 * went out at deploy time. A row whose `created_at` is NULL is left NULL, which
 * the sweep never matches either (it selects `created_at <= cutoff`).
 *
 * datetime(), not timestamp(): a domain time must not get MySQL's implicit ON
 * UPDATE. The (notified_at, created_at) index serves the sweep's only query, "the
 * unannounced rows older than the settle window". Names are hand-set under 64
 * characters (MySQL refuses longer; SQLite does not).
 *
 * Additive only: two nullable columns and two indexes. The backfill is the query
 * builder (a column-to-column UPDATE), not DB::statement, so it runs unchanged on
 * MySQL and SQLite and needs no driver guard. down() drops the indexes and columns
 * and touches no row.
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

            // Reactions that pre-date the digest are not news. One UPDATE, column to
            // column; an empty table is a no-op.
            DB::table($table)
                ->whereNull('notified_at')
                ->whereNotNull('created_at')
                ->update(['notified_at' => DB::raw('created_at')]);
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
