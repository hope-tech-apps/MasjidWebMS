<?php

namespace App\Console\Commands;

use App\Models\ContactLoginCode;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Delete parent sign-in code records once they are old enough to be useless
 * (T-015d).
 *
 * A row is kept AFTER it is consumed or expires, on purpose: "which address
 * requested a sign-in at 02:14, from which IP, and was it used" is the first
 * question anyone asks after a suspected compromise of a family account, and a
 * row deleted at verification time cannot answer it. The row holds no
 * credential — `code_hash` is a keyed digest and the plaintext was never stored
 * — so retaining it discloses nothing that `contacts` does not already hold.
 *
 * But it is still a log of who tried to sign in and from where, so it is not
 * kept forever either. The default window is 30 days.
 *
 * NOT SCHEDULED YET. `routes/console.php` is where `Schedule::command()` lives
 * and that file is owned by another change in flight; adding
 * `Schedule::command('family:prune-login-codes')->daily()` there is a one-line
 * follow-up. Until then the table grows at the rate parents sign in, which is
 * small, and nothing depends on the sweep for correctness — an expired code is
 * refused by `ContactLoginCode::isRedeemable()`, not by its absence.
 */
class PruneContactLoginCodes extends Command
{
    protected $signature = 'family:prune-login-codes {--days=30 : Delete codes that expired more than this many days ago}';

    protected $description = 'Delete parent/guardian sign-in code records that expired long enough ago to be useless.';

    public function handle(TenantContext $tenant): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // A console run has no bound tenant, and BelongsToMasjid adds no filter
        // when unbound — which is exactly what a cross-tenant maintenance sweep
        // wants. `runWithout()` states that rather than relying on the process
        // happening to have bound nothing, so the command is still correct if
        // it is ever invoked from inside a bound request.
        $deleted = $tenant->runWithout(
            fn () => ContactLoginCode::query()->where('expires_at', '<', $cutoff)->delete()
        );

        $this->info("Pruned {$deleted} sign-in code record(s) that expired before {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
