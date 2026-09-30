<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * masjid_points_settings: how a school's points turn into Manara Bucks (T-003.4, W6).
 *
 * `points_per_buck` (R1): how many positive points make one buck, default 1.
 * `paper_bucks_enabled`: the cash-out-to-paper feature, OFF (owner, 2026-09-29: the physical
 * Manara Bucks are paused until the admin team decides). Built, not offered, and refused
 * server-side while false. NOT NULL with a default so a row written before this column
 * existed, and a school with no row at all, read as off and as 1.
 * `bucks_from`: the first day whose points may become bucks. NULL until the first minting
 * run for a school that has the store switched on sets it to the start of the week in
 * progress, so switching the store on never pays out a term of history nobody expected; a
 * SuperAdmin may move it earlier to credit that history on purpose.
 *
 * A negative-takes-bucks switch is deliberately NOT here: R2 says negatives never take
 * bucks, so a column with no behaviour would be a placeholder (rule: no placeholder columns
 * before the feature exists).
 *
 * Additive; the new NOT NULL columns carry defaults. Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_points_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('points_per_buck')->default(1)->after('report_time');
            $table->boolean('paper_bucks_enabled')->default(false)->after('points_per_buck');
            $table->date('bucks_from')->nullable()->after('paper_bucks_enabled');
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
                .'the rate, start day and paper switch of each school, which the earned rows were worked out from. The ledger is append-only: export and clear it deliberately first.'
            );
        }

        Schema::table('masjid_points_settings', function (Blueprint $table) {
            $table->dropColumn(['points_per_buck', 'paper_bucks_enabled', 'bucks_from']);
        });
    }
};
