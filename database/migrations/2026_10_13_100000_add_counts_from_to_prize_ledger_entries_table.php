<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * prize_ledger_entries.counts_from: the week a carried balance counts as minted in.
 *
 * When a student is moved to another class their Manara Bucks go with them, as ONE PAIR of
 * ledger rows that App\Support\ClassStore::carryBalance() writes inside the move's transaction.
 * Two kinds join the six of 2026_10_04_100100 (`kind` is a string of 16, so they need no change
 * to the column):
 *
 *   transfer_out  the whole balance leaves the roster row the student held in the old class (-)
 *   transfer_in   the same amount arrives on their roster row in the new class (+)
 *
 * Their keys: `transfer_out:{membership}:{n}` (n counts that roster row's earlier transfers out,
 * by kind) and `transfer_in:{entry}` (the id of the transfer_out row it answers), so "one in per
 * out" is a database fact the way `reversal:{entry}` is. Neither row points at the other through
 * `reverses_entry_id` and there is no linking column: each row explains its own roster row's
 * balance alone, because the retention purge will separate them. The other ten descriptive
 * columns (note, prize, week, breakdown) are NULL on both, so nothing of the old class's history
 * is copied into the new one.
 *
 * `counts_from` is the one thing a pair needs that the ledger did not have. Expiry writes off
 * what a child holds that was minted BEFORE a cutoff, and it tells before from after by a minted
 * row's `week_start`. A carried amount has no week, so without a date of its own the whole of it
 * would read as older than every cutoff ever due, and last year's end would take a balance moved
 * this October. Both rows of a pair therefore carry the same day: the newest week the old roster
 * row was minted for, never later than the day the move was run. Each row then counts as "from
 * this cutoff on" or not by itself, with its own sign (App\Support\BucksExpiry).
 *
 * NULL on every row of the other six kinds, and on a pair whose old roster row was never minted
 * for. A plain DATE read as 'Y-m-d' text, like `week_start`: not cast on the model. No index (it
 * is only ever read beside `group_membership_id`, which has one) and no back-fill: the store is
 * off for every organisation and the ledger holds no rows.
 *
 * DEPLOY ORDER. bin/deploy serves the new code before it runs this. The one read of the column
 * (expiry's sum) is behind App\Support\ClassStore::carryReady(). The writer has no caller yet.
 * The commit that makes the move call it must also make the move refuse until this column is
 * there: today the move's guard (App\Support\RosterMove::ready()) asks about the roster's
 * consent column only, and does NOT ask about this one.
 *
 * ROLLING BACK. Like every class-store migration after the ledger's own, down() refuses while
 * any ledger row exists: with the column gone, the next sweep would write off every balance
 * that had been carried. Additive; Blueprint only, so the same statement runs on MySQL and on
 * the suite's SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prize_ledger_entries', function (Blueprint $table) {
            $table->date('counts_from')->nullable()->after('week_start');
        });
    }

    public function down(): void
    {
        // Guarded like the ledger's own down() (2026_10_04_100100) and the columns added after
        // it: a partial rollback refuses rather than quietly losing what a row was worked out from.
        $rows = Schema::hasTable('prize_ledger_entries') ? DB::table('prize_ledger_entries')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$rows} Manara Bucks ledger entr(ies) exist, and this migration holds "
                .'the date that keeps a balance carried to another class from being written off by a cutoff older than the move. The ledger is append-only: export and clear it deliberately first.'
            );
        }

        Schema::table('prize_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('counts_from');
        });
    }
};
