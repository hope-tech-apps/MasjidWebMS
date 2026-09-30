<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * prize_ledger_entries.week_points and week_rate: what a minted week was worked out FROM
 * (T-003.4, W6 review fix).
 *
 * `week_basis` says how many bucks a week's points came to at the moment a row was written,
 * but not at which rate, so when a SuperAdmin changed `points_per_buck` the next run
 * compared the OLD-rate basis with a NEW-rate figure and wrote the difference as an
 * `adjusted` delta over the last two closed weeks: a rate change silently took bucks back
 * (or handed them out). These two columns make the row self-explaining:
 *
 *   week_rate    the points_per_buck this week was minted at, kept for the week's whole life
 *   week_points  the week's positive points as accounted after this row (earned and adjusted
 *                rows): the ledger's own record of what `week_basis` was worked out from, so an
 *                office reading a week can check the sum without the awards
 *
 * BucksMinter now re-prices a week at the week's OWN rate, so a new rate applies only to weeks
 * minted after it.
 *
 * Both are nullable: a row written before these columns existed (none on production: the
 * store is OFF everywhere) has neither, and reads at the school's current rate as before.
 * Additive; Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prize_ledger_entries', function (Blueprint $table) {
            $table->unsignedInteger('week_points')->nullable()->after('week_basis');
            $table->unsignedSmallInteger('week_rate')->nullable()->after('week_points');
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
                .'the rate and points each minted week was priced at, which every later adjustment of that week reads. The ledger is append-only: export and clear it deliberately first.'
            );
        }

        Schema::table('prize_ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['week_points', 'week_rate']);
        });
    }
};
