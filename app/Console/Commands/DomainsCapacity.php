<?php

namespace App\Console\Commands;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * How full the renderer's Pages project is (Manara Studio W2, S1).
 *
 * Every website host Studio attaches is a custom domain on ONE Pages project,
 * and Cloudflare caps those per project (config `cloudflare.pages_domain_ceiling`).
 * At the ceiling the attacher parks a new client's host on `capacity` and
 * retries hourly, and until this command nothing said so to anyone. The owner
 * asked to hear about it before it bites (2026-09-24): "definitely keep me up to
 * date and where we need to start retiring some we will."
 *
 * WHERE THE COUNT COMES FROM, and the output always says which:
 *
 *  - `cloudflare`: with CLOUDFLARE_STUDIO_TOKEN, one GET of the project's
 *    domain list (CloudflareService::countPagesDomains). The real figure.
 *  - `rows_estimate`: without the token, or when that GET fails, the
 *    `masjid_domains` rows that hold or are acquiring a slot (SLOT_STATUSES,
 *    and a `detaching` row until its Pages domain is removed).
 *    `reserved` rows are never counted: they hold a host for an organisation
 *    and are not custom domains on the project. Rows of a trashed
 *    organisation ARE counted, because trashing leaves Cloudflare untouched.
 *
 * WHAT IT SAYS, one line per run on the `monitors` channel (monitors.log keeps
 * every line; ops-alerts emails `error` to OPS_ALERT_EMAIL):
 *
 *  - `error` the first time the project reaches each percentage in
 *    `cloudflare.pages_domain_notice_at`. A Cache::forever marker per
 *    threshold keeps each to one email; the marker is written after the line,
 *    so a lost cache can only repeat a notice, never swallow one. A run that
 *    jumps past several thresholds writes one line naming the highest.
 *  - `error` on EVERY run while any row waits on `capacity`: a real client is
 *    waiting for a slot, and that is not something to say once.
 *  - `info` otherwise.
 *
 * It always exits 0: it reports, and a monitor that fails its own run on a bad
 * read is noise nobody can act on. The retirement procedure is the runbook.
 *
 * SCHEDULED in routes/console.php, daily at 07:17.
 */
class DomainsCapacity extends Command
{
    protected $signature = 'domains:capacity
        {--json : Print the figures as JSON}';

    protected $description = 'Report how many of the renderer Pages project\'s custom-domain slots are used, and tell the owner as it fills.';

    /** Rows that hold a Pages slot or are on their way to one. */
    public const SLOT_STATUSES = [
        MasjidDomain::STATUS_PENDING,
        MasjidDomain::STATUS_PROVISIONING,
        MasjidDomain::STATUS_ACTIVE,
        MasjidDomain::STATUS_MANUAL,
    ];

    /** The per-threshold marker, completed with the percentage. */
    public const NOTICED_KEY = 'domains:capacity:noticed:';

    public const RUNBOOK = 'docs/runbooks/pages-domain-ceiling.md';

    /** Hosts per client behind `clients_left_estimate`: an apex and its `www` (spec §4, D17). */
    public const HOSTS_PER_CLIENT = 2;

    public function handle(CloudflareService $cloudflare): int
    {
        $ceiling = (int) config('cloudflare.pages_domain_ceiling');
        $cloudflareError = null;
        $used = null;

        if ($cloudflare->isConfigured()) {
            $count = $cloudflare->countPagesDomains();

            if ($count->is(CloudflareResult::OK)) {
                $used = (int) $count->data['count'];
            } else {
                $cloudflareError = $count->outcome . ($count->error !== null ? ': ' . $count->error : '');
            }
        }

        $source = $used !== null ? 'cloudflare' : 'rows_estimate';
        // A `detaching` row still holds its slot until its Pages domain is gone (W2 S3).
        $used ??= MasjidDomain::query()
            ->where(fn ($q) => $q->whereIn('status', self::SLOT_STATUSES)
                ->orWhere(fn ($q) => $q->where('status', MasjidDomain::STATUS_DETACHING)->whereNotNull('cf_pages_domain_id')))
            ->count();

        $percent = $ceiling > 0 ? round($used * 100 / $ceiling, 1) : 100.0;
        $waiting = MasjidDomain::query()->where('waiting_on', 'capacity')->count();

        $reached = array_values(array_filter(
            $this->thresholds(),
            fn (int $threshold) => $percent >= $threshold,
        ));
        $fresh = array_values(array_filter($reached, fn (int $threshold) => ! Cache::has(self::NOTICED_KEY . $threshold)));
        $notice = $fresh === [] ? null : max($fresh);

        $report = [
            'used' => $used,
            'source' => $source,
            'ceiling' => $ceiling,
            'percent' => $percent,
            'waiting_on_capacity' => $waiting,
            'clients_left_estimate' => max(0, intdiv($ceiling - $used, self::HOSTS_PER_CLIENT)),
            'clients_left_basis' => 'estimate: ' . self::HOSTS_PER_CLIENT . ' hosts per client (an apex and its www)',
            'notice_at' => $this->thresholds(),
            'notice' => $notice,
            'runbook' => self::RUNBOOK,
        ];

        if ($cloudflareError !== null) {
            $report['cloudflare_error'] = $cloudflareError;
        }

        $this->log($report);

        foreach ($fresh as $threshold) {
            Cache::forever(self::NOTICED_KEY . $threshold, now()->toIso8601String());
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("{$used} of {$ceiling} Pages custom domains used ({$percent}%, {$source}).");
            $this->line("  Clients left: about {$report['clients_left_estimate']} ({$report['clients_left_basis']}).");
            $this->line("  Hosts waiting on capacity: {$waiting}.");

            if ($cloudflareError !== null) {
                $this->warn("  Cloudflare could not be read ({$cloudflareError}); the figure is the row estimate.");
            }
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $report */
    private function log(array $report): void
    {
        $channel = Log::channel('monitors');
        $figures = "{$report['used']} of {$report['ceiling']} ({$report['percent']}%, {$report['source']})";

        if ($report['waiting_on_capacity'] > 0) {
            // One line per run, so a threshold first reached on a run that
            // also has a waiting host is said here, in the same email.
            $channel->error(
                "domains:capacity: {$report['waiting_on_capacity']} web address(es) are waiting for a Pages custom-domain slot; "
                . "{$figures} are used. A client is waiting. Free a slot or raise the ceiling: see {$report['runbook']}."
                . ($report['notice'] !== null ? " The project has now reached {$report['notice']}% of its ceiling." : ''),
                $report,
            );

            return;
        }

        if ($report['notice'] !== null) {
            $channel->error(
                "domains:capacity: the renderer Pages project has reached {$report['notice']}% of its custom-domain ceiling: "
                . "{$figures}. About {$report['clients_left_estimate']} more clients fit. What to retire first: {$report['runbook']}.",
                $report,
            );

            return;
        }

        $channel->info("domains:capacity: {$figures} Pages custom domains used.", $report);
    }

    /** @return list<int> */
    private function thresholds(): array
    {
        $thresholds = array_map('intval', (array) config('cloudflare.pages_domain_notice_at', []));
        sort($thresholds);

        return array_values(array_unique($thresholds));
    }
}
