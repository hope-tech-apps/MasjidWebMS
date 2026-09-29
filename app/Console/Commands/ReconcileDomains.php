<?php

namespace App\Console\Commands;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareService;
use App\Services\Domains\DomainAttacher;
use App\Services\Domains\DetachResult;
use App\Services\Domains\DomainDetacher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The poller behind Manara Studio's web addresses (W1, S7). Cloudflare sends no
 * callbacks, so every five minutes this advances each `masjid_domains` row that
 * is its business, through DomainAttacher.
 *
 * WHICH ROWS ARE ITS BUSINESS
 *
 *  - rows still moving (`pending`, `awaiting_nameservers`, `provisioning`)
 *    whose `next_check_at` has come;
 *  - `active` rows not yet seen serving, for the probe;
 *  - `active` and `manual` rows seen serving (or demoted and waiting to be
 *    seen again), for their re-probe once a day (W2 S4). This is the one
 *    request a run makes without a token for production's imported rows: a
 *    GET of each host's own /api/tenant, never anything to Cloudflare;
 *  - `manual` and `imported` rows, ONLY when the token is configured, so the
 *    attacher can promote them to `active` by reading Cloudflare;
 *  - `detaching` rows whose `next_check_at` has come (W2 S3), handed to
 *    DomainDetacher rather than the attacher, so a removal that stopped
 *    part-way is finished. These are selected even for a trashed organisation:
 *    taking a host off Cloudflare is exactly what a departed one needs.
 *
 * Never `reserved`, never `failed`, and, `detaching` aside, never a row whose
 * organisation is trashed (W2 S2): trashing is reversible and leaves the row as it was, and a
 * restore resumes it exactly there, but nothing is attached in Cloudflare for
 * an organisation that is not live. The same `whereHas('masjid')` the lookup's
 * served() scope relies on excludes it.
 *
 * So on production's imported rows (manual and reserved), a run probes each
 * confirmed host once a day on its own address and sends nothing else without
 * the token; with it, it also only ever reads Cloudflare. Neither ever writes
 * to Cloudflare for them.
 *
 * Without the token, rows that are waiting still get their probe (the only
 * request, to their own host), and the run logs one warning an hour saying
 * they wait on the token, through a Cache::add marker, so the log says it
 * without saying it 288 times a day. The warning is at warning level because
 * production logs nothing below it (.claude/rules/shipping.md).
 *
 * It always exits 0: a row that could not be advanced is recorded on the row
 * and tried again on the next tick, and a scheduled command that exits non-zero
 * every five minutes is noise nobody can act on.
 *
 * SCHEDULED in routes/console.php at 3-59/5.
 */
class ReconcileDomains extends Command
{
    protected $signature = 'domains:reconcile
        {--id=* : Advance only these masjid_domains ids (still never a reserved or failed row, nor one of a trashed organisation), due or not}
        {--json : Print the run as JSON}';

    protected $description = 'Advance the Manara Studio web addresses that are waiting on Cloudflare, or on being seen serving.';

    /** One fixed key: the no-token warning is written at most once an hour. */
    public const NO_TOKEN_WARNING_KEY = 'domains:reconcile:no-token-warned';

    public function handle(CloudflareService $cloudflare, DomainAttacher $attacher, DomainDetacher $detacher): int
    {
        $configured = $cloudflare->isConfigured();
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        $rows = self::selection($configured, $ids !== [] ? $ids : null)->orderBy('id')->get();

        // A confirmed host's daily re-probe needs no token, so it is not
        // "waiting on" one and is not counted here (W2 S4). A detach that
        // cannot finish without the token is (review follow-up 7).
        $waiting = $rows->reject(fn (MasjidDomain $row) => $row->underReconfirmation());

        if (! $configured && $waiting->isNotEmpty()
            && Cache::add(self::NO_TOKEN_WARNING_KEY, true, now()->addHour())) {
            Log::warning('domains:reconcile: web addresses are waiting on CLOUDFLARE_STUDIO_TOKEN; nothing was sent to Cloudflare.', [
                'waiting' => $waiting->count(),
                'ids' => $waiting->pluck('id')->values()->all(),
            ]);
        }

        $results = [];
        $reprobeCap = max(1, (int) config('cloudflare.reconfirm.per_run', 20));
        $reprobes = 0;
        $deferred = 0;

        foreach ($rows as $row) {
            $before = $row->status;

            // Re-probes are spread across runs: at most `reconfirm.per_run` a
            // run, so a first deploy (every confirmed host due at once) is not
            // one burst of requests. The rest stay due and go on the next run.
            // Not for rows an operator named with --id: those were asked for.
            if ($ids === [] && $row->underReconfirmation() && $row->cf_redirect_rule_id === null) {
                if ($reprobes >= $reprobeCap) {
                    $deferred++;

                    continue;
                }

                $reprobes++;
            }

            try {
                // A rule a failed collapse left on a serving host (review
                // follow-up 4) goes first. Until it is gone the row stays
                // parked on its half-hour retry: advancing it would re-probe a
                // host that answers 301, count the redirect as a miss, and
                // push the next removal out by a day.
                if (! $row->isRedirect() && $row->cf_redirect_rule_id !== null
                    && ! $detacher->removeStrayRedirectRule($row)) {
                    $results[] = [
                        'id' => $row->id,
                        'host' => $row->host,
                        'status_before' => $before,
                        'status' => $row->status,
                        'waiting_on' => 'rule_cleanup',
                        'serving_confirmed' => $row->serving_confirmed_at !== null,
                    ];

                    continue;
                }

                if ($row->status === MasjidDomain::STATUS_DETACHING) {
                    $gone = $detacher->detach($row)->outcome === DetachResult::DETACHED;
                    $results[] = [
                        'id' => $row->id,
                        'host' => $row->host,
                        'status_before' => $before,
                        'status' => $gone ? 'detached' : $row->status,
                        'waiting_on' => $gone ? null : $row->waiting_on,
                        'serving_confirmed' => false,
                    ];

                    continue;
                }

                $row = $attacher->advance($row);
                $results[] = [
                    'id' => $row->id,
                    'host' => $row->host,
                    'status_before' => $before,
                    'status' => $row->status,
                    'waiting_on' => $row->waiting_on,
                    'serving_confirmed' => $row->serving_confirmed_at !== null,
                ];
            } catch (Throwable $e) {
                Log::warning('domains:reconcile could not advance a web address.', ['id' => $row->id, 'host' => $row->host, 'error' => $e->getMessage()]);
                $results[] = ['id' => $row->id, 'host' => $row->host, 'status_before' => $before, 'error' => $e->getMessage()];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'token_configured' => $configured,
                'selected' => count($results),
                'reprobes_deferred' => $deferred,
                'rows' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($configured ? 'Token configured.' : 'No token: nothing is sent to Cloudflare.') . ' Selected ' . count($results) . ' row(s).'
                . ($deferred > 0 ? " {$deferred} re-probe(s) left for the next run." : ''));

            foreach ($results as $result) {
                $this->line("  #{$result['id']} {$result['host']}: {$result['status_before']} -> " . ($result['status'] ?? 'error: ' . ($result['error'] ?? '')));
            }
        }

        return self::SUCCESS;
    }

    /**
     * The rows a run advances. With `$ids`, only those, and whether they are
     * due does not matter (an operator asked); the status rules still hold.
     *
     * @param  list<int>|null  $ids
     */
    public static function selection(bool $tokenConfigured, ?array $ids = null): Builder
    {
        $due = fn (Builder $q) => $ids !== null
            ? $q
            : $q->where(fn (Builder $when) => $when->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()));

        // What the attacher advances: never a trashed organisation's row (W2 S2).
        $attaching = fn (Builder $q) => $q->whereHas('masjid')->where(function (Builder $q) use ($tokenConfigured, $due) {
            $q->where(fn (Builder $moving) => $due($moving->whereIn('status', MasjidDomain::NON_TERMINAL)))
                ->orWhere(fn (Builder $active) => $due(
                    $active->where('status', MasjidDomain::STATUS_ACTIVE)->whereNull('serving_confirmed_at')
                ))
                // Hosts seen serving, and demoted ones waiting to be seen again,
                // for their daily re-probe (W2 S4; MasjidDomain::underReconfirmation()).
                ->orWhere(fn (Builder $confirmed) => $due(
                    $confirmed->where('role', MasjidDomain::ROLE_SERVING)
                        ->whereIn('status', MasjidDomain::TRUSTED)
                        ->where(fn (Builder $seen) => $seen->whereNotNull('serving_confirmed_at')->orWhereNotNull('serving_missed_since'))
                ));

            // Reads promote a serving host to `active`; a redirect host has no
            // Pages domain to read (W2 S5), and its own check is the 301.
            if ($tokenConfigured) {
                $q->orWhere(fn (Builder $readable) => $due($readable->where('role', MasjidDomain::ROLE_SERVING)->where(
                    fn (Builder $which) => $which->where('status', MasjidDomain::STATUS_MANUAL)
                        ->orWhere('source', MasjidDomain::SOURCE_IMPORTED)
                )->where('status', '!=', MasjidDomain::STATUS_ACTIVE)));
            }
        });

        return MasjidDomain::query()
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->whereNotIn('status', [MasjidDomain::STATUS_RESERVED, MasjidDomain::STATUS_FAILED])
            ->where(fn (Builder $q) => $q
                // What the detacher finishes (W2 S3), whatever the organisation's state.
                ->where(fn (Builder $detaching) => $due($detaching->where('status', MasjidDomain::STATUS_DETACHING)))
                // A redirect rule left on a serving host by a failed collapse.
                ->orWhere(fn (Builder $stray) => $due($stray->where('role', MasjidDomain::ROLE_SERVING)->whereNotNull('cf_redirect_rule_id')))
                ->orWhere($attaching));
    }
}
