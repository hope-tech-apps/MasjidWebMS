<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareService;
use App\Services\Domains\DetachResult;
use App\Services\Domains\DomainDetacher;
use Illuminate\Console\Command;

/**
 * Detach every web address Studio attached for one organisation (W2 S3).
 *
 * The tool for a trashed or departed organisation (docs/runbooks/
 * pages-domain-ceiling.md, step 2) and the way past Masjid's force-delete
 * guard (App\Exceptions\DomainsStillAttached). The organisation may be
 * trashed: taking its hosts off Cloudflare is exactly what it needs.
 *
 * A DRY RUN unless --execute is given: it lists, for each row, what Studio
 * would remove (only what its own POSTs created, per the row's flags) and what
 * it would leave for a person, and sends nothing to Cloudflare. With
 * --execute each row goes through DomainDetacher, which re-reads every object
 * before deleting it.
 *
 * Rows that came from the live host map (imported, or adopted by S6) are
 * never detached here; they are listed as left alone.
 */
class ReleaseDomains extends Command
{
    protected $signature = 'domains:release
        {masjid_id : The organisation, trashed or not}
        {--execute : Detach for real; without it nothing is sent or changed}
        {--operator= : Who is running it, for the ledger (required with --execute)}
        {--reason= : Why, for the ledger (required with --execute)}
        {--force : Also detach a LIVE (not trashed) organisation\'s addresses}
        {--json : Print the run as JSON}';

    protected $description = 'Detach every web address Studio attached for one organisation, removing only what Studio created in Cloudflare. A dry run unless --execute.';

    public function handle(DomainDetacher $detacher, CloudflareService $cloudflare): int
    {
        $masjid = Masjid::withTrashed()->find((int) $this->argument('masjid_id'));

        if ($masjid === null) {
            $this->error('No organisation #' . $this->argument('masjid_id') . '.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));

        // Each detach started here is ledgered, like S6's actions (review
        // follow-up 10), so an executed run needs both.
        if ($execute && ($operator === '' || $reason === '')) {
            $this->error('--execute needs --operator and --reason: they go into the ledger with each detach. Nothing was changed.');

            return self::FAILURE;
        }

        // A live organisation's addresses are its website. Taking them all down
        // is for a trashed or departed one; anything else needs --force.
        if ($execute && ! $masjid->trashed() && ! $this->option('force')) {
            $this->error("Organisation #{$masjid->id} is live, not trashed: detaching every address takes its website down. Trash it first, or add --force if that is really meant. Nothing was changed.");

            return self::FAILURE;
        }

        if ($execute && ! $cloudflare->isConfigured()) {
            $this->error('CLOUDFLARE_STUDIO_TOKEN is not set, so nothing can be detached from Cloudflare. Nothing was changed.');

            return self::FAILURE;
        }

        // Redirect rows first: a serving host is refused while an alias still
        // points at it (review follow-up 2).
        $rows = MasjidDomain::query()
            ->where('masjid_id', $masjid->id)
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [MasjidDomain::ROLE_REDIRECT])
            ->orderBy('id')
            ->get();
        $report = [];
        $failed = false;

        foreach ($rows as $row) {
            if (! $row->ownedByStudio()) {
                $report[] = ['id' => $row->id, 'host' => $row->host, 'outcome' => 'left_alone', 'reason' => 'from the live host map (imported or adopted)'];

                continue;
            }

            if (! $execute) {
                $report[] = ['id' => $row->id, 'host' => $row->host, 'outcome' => 'would_detach'] + $row->detachPlan();

                continue;
            }

            $result = $detacher->detach($row, null, $operator, $reason);
            $report[] = ['id' => $row->id] + $result->toArray();
            $failed = $failed || $result->outcome !== DetachResult::DETACHED;
        }

        $out = [
            'masjid_id' => $masjid->id,
            'organisation_trashed' => $masjid->trashed(),
            'executed' => $execute,
            'rows' => $report,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($execute ? 'Detached' : 'DRY RUN: would detach') . " the web addresses of organisation #{$masjid->id} ({$masjid->name})"
                . ($masjid->trashed() ? ', which is trashed' : '') . '.');

            foreach ($report as $line) {
                $this->line("  #{$line['id']} {$line['host']}: {$line['outcome']}");

                foreach (array_merge((array) ($line['removed'] ?? []), (array) ($line['would_remove'] ?? [])) as $object) {
                    $this->line("      removes {$object}");
                }

                foreach ((array) ($line['manual_steps'] ?? []) as $step) {
                    $this->line("      by hand: {$step}");
                }

                if (! empty($line['error'])) {
                    $this->line("      {$line['error']}");
                }
            }

            if (! $execute) {
                $this->line('Nothing was sent or changed. Run again with --execute to detach.');
            }
        }

        // A run that did not finish every detach says so in its exit code, so
        // a script or an operator cannot read a partial release as done.
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
