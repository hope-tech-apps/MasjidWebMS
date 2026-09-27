<?php

namespace App\Console\Commands;

use App\Models\MasjidDomain;
use App\Services\Domains\DomainProbe;
use Illuminate\Console\Command;
use Throwable;

/**
 * The reviewed tool for the web addresses imported from the live host map
 * (Manara Studio W2, S6).
 *
 * W1 froze the imported rows on purpose (R28): they are how the live tenants
 * are reached, so Studio can neither delete nor re-point them. This is the one
 * way to change one, and EVERY executed use is a production change that needs
 * the owner's go, with the dry run's output recorded first.
 *
 *   list     every imported (or adopted) row: host, organisation, status, the
 *            last probe, and whether a probe matches now. Writes nothing; the
 *            only requests are one GET of each host's own /api/tenant.
 *   release  delete an imported `reserved` row, so its host may be attached
 *            again. Refused when a probe shows the host serving its own
 *            organisation right now.
 *   adopt    hand an imported `reserved` row to Studio: `studio`, `pending`,
 *            `adopted_from_import_at` stamped, so the attacher may attach it
 *            (which still adopts only a CNAME already at the renderer and
 *            overwrites nothing). A `manual` or `active` row is refused. An
 *            adopted row keeps an imported row's protections for its life.
 *
 * There is no re-point. Moving a live host to another organisation would move
 * a live site; that stays a manual database change with its own review.
 *
 * release and adopt are DRY RUNS unless --execute, and --execute needs both
 * --operator and --reason, which go into the append-only ledger
 * (`masjid_domain_changes`) with the row before and after.
 */
class ImportedDomains extends Command
{
    protected $signature = 'domains:imported
        {action : list, release or adopt}
        {--id=* : The masjid_domains ids to release or adopt}
        {--operator= : Who is running it, for the ledger (required with --execute)}
        {--reason= : Why, for the ledger (required with --execute)}
        {--execute : Make the change; without it nothing is changed}
        {--json : Print the result as JSON}';

    protected $description = 'List, release or adopt the web addresses imported from the live host map. Changes are dry runs unless --execute, and each executed one is ledgered.';

    public function handle(DomainProbe $probe): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['list', 'release', 'adopt'], true)) {
            $this->error("Unknown action \"{$action}\". Use list, release or adopt.");

            return self::FAILURE;
        }

        if ($action === 'list') {
            return $this->finish(['action' => 'list', 'rows' => $this->listing($probe)], self::SUCCESS);
        }

        $execute = (bool) $this->option('execute');
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        if ($ids === []) {
            $this->error("Name the rows to {$action} with --id.");

            return self::FAILURE;
        }

        if ($execute && ($operator === '' || $reason === '')) {
            $this->error('--execute needs --operator and --reason: they go into the ledger with the change. Nothing was changed.');

            return self::FAILURE;
        }

        $results = [];
        $failed = false;

        foreach ($ids as $id) {
            $row = MasjidDomain::find($id);
            $refusal = $row === null ? "No web address #{$id}." : $this->refusal($action, $row, $probe);

            if ($refusal !== null) {
                $results[] = ['id' => $id, 'host' => $row?->host, 'outcome' => 'refused', 'reason' => $refusal];
                $failed = true;

                continue;
            }

            if (! $execute) {
                $results[] = ['id' => $id, 'host' => $row->host, 'outcome' => "would_{$action}"];

                continue;
            }

            try {
                if ($action === 'release') {
                    $row->releaseImported($operator, $reason);
                } else {
                    $row->reclassifyImported('adopt', $operator, $reason);
                }

                $results[] = ['id' => $id, 'host' => $row->host, 'outcome' => $action === 'release' ? 'released' : 'adopted'];
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'host' => $row->host, 'outcome' => 'failed', 'reason' => $e->getMessage()];
                $failed = true;
            }
        }

        return $this->finish(['action' => $action, 'executed' => $execute, 'rows' => $results], $failed ? self::FAILURE : self::SUCCESS);
    }

    /** Why `$row` may not be released or adopted, or null. */
    private function refusal(string $action, MasjidDomain $row, DomainProbe $probe): ?string
    {
        if ($row->source !== MasjidDomain::SOURCE_IMPORTED) {
            return "{$row->host} is not an imported row" . ($row->adopted_from_import_at !== null ? ' (it was adopted already).' : '.');
        }

        if ($row->status !== MasjidDomain::STATUS_RESERVED) {
            return $action === 'adopt'
                ? "{$row->host} is {$row->status}: it is serving, and adopting it would take it out of the scope CORS and card-payment returns admit. Only a reserved row is adopted."
                : "{$row->host} is {$row->status}, not reserved: it is how its organisation is reached, and release is only for a reserved row.";
        }

        if ($action === 'release') {
            $seen = $probe->probe($row);

            if ($seen['matched']) {
                return "{$row->host} is serving organisation #{$row->masjid_id} right now ({$seen['seen']}); releasing it would let another organisation take a live host.";
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function listing(DomainProbe $probe): array
    {
        return MasjidDomain::query()
            ->with('masjid')
            ->where(fn ($q) => $q->where('source', MasjidDomain::SOURCE_IMPORTED)->orWhereNotNull('adopted_from_import_at'))
            ->orderBy('masjid_id')
            ->orderBy('host')
            ->get()
            ->map(function (MasjidDomain $row) use ($probe) {
                $now = $probe->probe($row);

                return [
                    'id' => $row->id,
                    'host' => $row->host,
                    'masjid_id' => (int) $row->masjid_id,
                    'organisation' => $row->masjid?->name,
                    'status' => $row->status,
                    'source' => $row->source,
                    'adopted_from_import_at' => $row->adopted_from_import_at?->toIso8601String(),
                    'last_checked_at' => $row->last_checked_at?->toIso8601String(),
                    'serving_confirmed_at' => $row->serving_confirmed_at?->toIso8601String(),
                    'probe_matches_now' => $now['matched'],
                    'probe_saw' => $now['seen'],
                ];
            })
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $out */
    private function finish(array $out, int $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $code;
        }

        foreach ($out['rows'] as $row) {
            $this->line(match ($out['action']) {
                'list' => sprintf('#%d %s  org #%d %s  %s/%s  probe now: %s (%s)',
                    $row['id'], $row['host'], $row['masjid_id'], (string) $row['organisation'], $row['status'], $row['source'],
                    $row['probe_matches_now'] ? 'serving' : 'not serving', $row['probe_saw']),
                default => "#{$row['id']} {$row['host']}: {$row['outcome']}" . (isset($row['reason']) ? " ({$row['reason']})" : ''),
            });
        }

        if ($out['action'] !== 'list' && ! ($out['executed'] ?? false)) {
            $this->line('DRY RUN: nothing was changed. Record this output, then run again with --execute --operator=... --reason=... once the owner has said go.');
        }

        return $code;
    }
}
