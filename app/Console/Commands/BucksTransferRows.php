<?php

namespace App\Console\Commands;

use App\Models\PrizeLedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `bucks:transfer-rows`: does the Manara Bucks ledger hold a row of a transfer kind?
 *
 * Asked by `bin/deploy` before it puts code in place that does NOT know those kinds (a
 * roll-back to before balances followed a moved student). To such code a carried amount was
 * minted before every cutoff, so its next hourly sweep would write off every carried balance in
 * a school with one finished year on file, and rolling forward again does not bring them back:
 * a write-off is given back only when its cutoff no longer exists, and these still would.
 *
 * It prints ONE number, how many rows of a transfer kind exist across every organisation, and
 * nothing else: no amount, no id, no school. The exit code is the answer a script reads:
 *
 *   0  none: the older code is safe to deploy
 *   3  at least one: refuse
 *
 * Anything else (the table is missing, the database cannot be reached) is the framework's own
 * failure code, and the caller must treat it as "could not tell" and stop: it fails closed.
 */
class BucksTransferRows extends Command
{
    /** At least one transfer row exists. Not 1 or 2, which a crash or a usage error also answer. */
    public const ROWS_EXIST = 3;

    protected $signature = 'bucks:transfer-rows';

    protected $description = 'Count the Manara Bucks ledger rows of a transfer kind (exit 0: none, 3: some)';

    public function handle(): int
    {
        // The raw table: a console run has no tenant bound, and the question is about every school.
        $rows = DB::table('prize_ledger_entries')
            ->whereIn('kind', PrizeLedgerEntry::TRANSFER_KINDS)
            ->count();

        $this->line((string) $rows);

        return $rows > 0 ? self::ROWS_EXIST : self::SUCCESS;
    }
}
