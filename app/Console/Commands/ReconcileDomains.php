<?php

namespace App\Console\Commands;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareService;
use App\Services\Domains\DomainAttacher;
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
 *  - `manual` and `imported` rows, ONLY when the token is configured, so the
 *    attacher can promote them to `active` by reading Cloudflare.
 *
 * Never `reserved` and never `failed`. So on production today, where the only
 * rows will be the imported ones (manual and reserved) and the token decides
 * the third bullet, a run without the token selects nothing and sends nothing,
 * and a run with it only ever reads.
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
        {--id=* : Advance only these masjid_domains ids (still never a reserved or failed row), due or not}
        {--json : Print the run as JSON}';

    protected $description = 'Advance the Manara Studio web addresses that are waiting on Cloudflare, or on being seen serving.';

    /** One fixed key: the no-token warning is written at most once an hour. */
    public const NO_TOKEN_WARNING_KEY = 'domains:reconcile:no-token-warned';

    public function handle(CloudflareService $cloudflare, DomainAttacher $attacher): int
    {
        $configured = $cloudflare->isConfigured();
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        $rows = self::selection($configured, $ids !== [] ? $ids : null)->orderBy('id')->get();

        if (! $configured && $rows->isNotEmpty()
            && Cache::add(self::NO_TOKEN_WARNING_KEY, true, now()->addHour())) {
            Log::warning('domains:reconcile: web addresses are waiting on CLOUDFLARE_STUDIO_TOKEN; nothing was sent to Cloudflare.', [
                'waiting' => $rows->count(),
                'ids' => $rows->pluck('id')->all(),
            ]);
        }

        $results = [];

        foreach ($rows as $row) {
            $before = $row->status;

            try {
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
                'rows' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($configured ? 'Token configured.' : 'No token: nothing is sent to Cloudflare.') . ' Selected ' . count($results) . ' row(s).');

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

        return MasjidDomain::query()
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->whereNotIn('status', [MasjidDomain::STATUS_RESERVED, MasjidDomain::STATUS_FAILED])
            ->where(function (Builder $q) use ($tokenConfigured, $due) {
                $q->where(fn (Builder $moving) => $due($moving->whereIn('status', MasjidDomain::NON_TERMINAL)))
                    ->orWhere(fn (Builder $active) => $due(
                        $active->where('status', MasjidDomain::STATUS_ACTIVE)->whereNull('serving_confirmed_at')
                    ));

                if ($tokenConfigured) {
                    $q->orWhere(fn (Builder $readable) => $due($readable->where(
                        fn (Builder $which) => $which->where('status', MasjidDomain::STATUS_MANUAL)
                            ->orWhere('source', MasjidDomain::SOURCE_IMPORTED)
                    )->where('status', '!=', MasjidDomain::STATUS_ACTIVE)));
                }
            });
    }
}
