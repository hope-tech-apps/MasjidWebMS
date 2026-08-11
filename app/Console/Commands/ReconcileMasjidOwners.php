<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * S0 — the reconciliation half of ownership determinism
 * (docs/multi-tenant-admin-design.md, "Migration path for existing admins").
 *
 * `masjids.user_id` is about to become unique among live rows. The index cannot
 * be added while any user owns two of them, and the migration deliberately
 * refuses to guess which one is real — detaching a masjid from its admin is an
 * ownership decision, not a schema detail. This command is where that decision
 * is surfaced and, on `--fix`, taken.
 *
 * WHY IT STILL EXISTS EVEN THOUGH PRODUCTION IS ALREADY CLEAN. Production was
 * checked on 2026-08-11: no user owns more than one live masjid there, and three
 * masjids have no owner at all (which the index permits — see the migration).
 * Staging, local dumps, restored backups and every future write that does not go
 * through StoreMasjidRequest/ProvisionMasjidRequest carry no such guarantee, and
 * a `migrate` that dies on a constraint violation mid-deploy is a bad way to
 * find out. Run this first, everywhere.
 *
 *   php artisan masjids:reconcile-owners            # report only; exit 1 if dirty
 *   php artisan masjids:reconcile-owners --fix      # detach the extras
 *   php artisan masjids:reconcile-owners --fix --force   # skip the production prompt
 *
 * THE TIE-BREAK IS "KEEP THE FIRST-CREATED", by ascending `id`. `id` is
 * monotonic and never null, so the choice is reproducible across a report run
 * and the fix run that follows it — which matters, because the operator approves
 * what the report showed. The losers are not deleted: they keep every row they
 * own and simply become unowned (`user_id = NULL`), the same state the three
 * production masjids are already in, and a SuperAdmin can re-point ownership
 * afterwards through the masjid update endpoint.
 */
class ReconcileMasjidOwners extends Command
{
    use ConfirmableTrait;

    protected $signature = 'masjids:reconcile-owners
                            {--fix : Detach the duplicates instead of only reporting them}
                            {--force : Skip the confirmation prompt when running in production}';

    protected $description = 'Report (and with --fix, resolve) users who own more than one live masjid.';

    public function handle(): int
    {
        $conflicts = $this->conflicts();

        $this->reportOrphans();

        if ($conflicts === []) {
            $this->info('masjids.user_id is already deterministic: no user owns more than one live masjid.');

            return self::SUCCESS;
        }

        $this->reportConflicts($conflicts);

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Reported only. Re-run with --fix to detach every masjid marked DETACH above.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed('Detaching ' . $this->detachCount($conflicts) . ' masjid(s) from their current owner')) {
            return self::FAILURE;
        }

        $detached = $this->detach($conflicts);

        $this->newLine();
        $this->info("Detached {$detached} masjid(s). masjids.user_id is now unique among live rows.");

        return $this->conflicts() === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Live masjids grouped by owner, for owners holding more than one.
     *
     * Soft-deleted rows are excluded because the index excludes them, and
     * unowned rows because NULLs never conflict.
     *
     * @return array<int, list<object>> user_id => masjid rows, ascending id
     */
    private function conflicts(): array
    {
        $owners = DB::table('masjids')
            ->select('user_id')
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id')
            ->all();

        if ($owners === []) {
            return [];
        }

        return DB::table('masjids')
            ->select('id', 'user_id', 'name', 'created_at')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $owners)
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->values()->all())
            ->all();
    }

    /**
     * Masjids with no owner at all. Informational — the index permits any number
     * of them — but an operator reading this output should know they exist,
     * because a "missing" admin is the other shape this data problem takes.
     */
    private function reportOrphans(): void
    {
        $orphans = DB::table('masjids')->whereNull('deleted_at')->whereNull('user_id')->count();

        if ($orphans > 0) {
            $this->line("{$orphans} live masjid(s) have no owner (user_id IS NULL). That is allowed by the "
                . 'unique index and is not changed by this command.');
        }
    }

    /** @param  array<int, list<object>>  $conflicts */
    private function reportConflicts(array $conflicts): void
    {
        $this->error(count($conflicts) . ' user(s) own more than one live masjid.');
        $this->newLine();

        $rows = [];

        foreach ($conflicts as $userId => $masjids) {
            foreach ($masjids as $index => $masjid) {
                $rows[] = [
                    $userId,
                    $masjid->id,
                    $masjid->name,
                    (string) $masjid->created_at,
                    $index === 0 ? 'KEEP' : 'DETACH',
                ];
            }
        }

        $this->table(['user_id', 'masjid_id', 'name', 'created_at', 'action'], $rows);
    }

    /** @param  array<int, list<object>>  $conflicts */
    private function detachCount(array $conflicts): int
    {
        return array_sum(array_map(fn (array $masjids) => count($masjids) - 1, $conflicts));
    }

    /**
     * Null out `user_id` on every masjid except each owner's first-created one.
     *
     * Written through the query builder rather than Eloquent on purpose: this
     * must not fire the model's observers/media hooks or stamp `updated_by` with
     * whoever happens to be authenticated in a console context. `updated_at` is
     * bumped so the change is visible in the audit trail.
     *
     * @param  array<int, list<object>>  $conflicts
     */
    private function detach(array $conflicts): int
    {
        $losers = [];

        foreach ($conflicts as $masjids) {
            foreach (array_slice($masjids, 1) as $masjid) {
                $losers[] = $masjid->id;
            }
        }

        if ($losers === []) {
            return 0;
        }

        return DB::table('masjids')
            ->whereIn('id', $losers)
            ->update(['user_id' => null, 'updated_at' => now()]);
    }
}
