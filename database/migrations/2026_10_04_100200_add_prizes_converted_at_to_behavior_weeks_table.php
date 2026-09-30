<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * behavior_weeks.prizes_converted_at: this class's points week has been turned into Manara
 * Bucks (T-003.4, W6).
 *
 * The row already exists per (class, week) as the Friday report's send claim
 * (2026_10_02_110000). This is a second, independent fact about the same week: `bucks:mint`
 * stamps it once it has processed the week, and skips a week older than the two-week
 * adjustment window that carries it. The minting itself is idempotent through the ledger's
 * `dedupe_key`, so the column is a record and a saving of work, never the thing that makes
 * "once" true.
 *
 * Nullable datetime (no implicit ON UPDATE), stored UTC. The report's claim reads only
 * `report_sent_at`, so a row created by minting is never mistaken for a sent report.
 * Additive; Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('behavior_weeks', function (Blueprint $table) {
            $table->dateTime('prizes_converted_at')->nullable()->after('recipients_count');
        });
    }

    public function down(): void
    {
        // Guarded like the ledger's own down() (2026_10_04_100100): while any Manara Bucks
        // ledger row exists, dropping these columns would leave rows the
        // application can no longer explain. A partial rollback refuses rather than
        // quietly losing that.
        $rows = Schema::hasTable('prize_ledger_entries') ? DB::table('prize_ledger_entries')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$rows} Manara Bucks ledger entr(ies) exist, and this migration holds "
                .'which weeks minting has already processed. The ledger is append-only: export and clear it deliberately first.'
            );
        }

        Schema::table('behavior_weeks', function (Blueprint $table) {
            $table->dropColumn('prizes_converted_at');
        });
    }
};
