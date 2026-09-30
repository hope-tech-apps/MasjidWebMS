<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * masjid_points_settings.bucks_swept_at: the hourly `bucks:mint` sweep has found this
 * school's class store ON (T-003.4, W6 review fix).
 *
 * It is how a switch-OFF is told apart from a school that was never on. A sweep that finds the
 * store OFF while this is set knows the store ran and has been paused: it clears `bucks_from`
 * and this column, so that when the store is switched back on the first sweep starts counting
 * at the week in progress and the weeks of the pause are not paid out in one hourly run. A
 * school that has never been swept keeps whatever start day a SuperAdmin set in advance.
 *
 * Nullable datetime (no implicit ON UPDATE), stored UTC. Not personal data. Additive;
 * Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_points_settings', function (Blueprint $table) {
            $table->dateTime('bucks_swept_at')->nullable()->after('bucks_from');
        });
    }

    public function down(): void
    {
        // Guarded like the other W6 migrations' down()s (2026_10_04_100200 to 100400): while any
        // Manara Bucks ledger row exists, dropping this column would lose the mark that tells a
        // paused school from one that was never on, and the next sweep would pay the weeks of a
        // pause out in one run. A partial rollback refuses rather than quietly losing that.
        $rows = Schema::hasTable('prize_ledger_entries') ? DB::table('prize_ledger_entries')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$rows} Manara Bucks ledger entr(ies) exist, and this migration holds "
                .'the mark that a school was swept with the store on, which keeps a paused store from paying out the pause. The ledger is append-only: export and clear it deliberately first.'
            );
        }

        Schema::table('masjid_points_settings', function (Blueprint $table) {
            $table->dropColumn('bucks_swept_at');
        });
    }
};
