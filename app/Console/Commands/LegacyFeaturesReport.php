<?php

namespace App\Console\Commands;

use App\Http\Middleware\CountLegacyFeaturesHit;
use App\Models\Masjid;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Yesterday's legacy `/features` traffic, in one line a person will see.
 *
 * `App\Http\Middleware\CountLegacyFeaturesHit` counts every served response in
 * a cache key per organisation per day, split `tagged` / `untagged`. Cache keys
 * are not enumerable, nobody opens a cache to browse it, and a number nobody
 * reads is not evidence — so this runs once a day, reads yesterday for every
 * organisation, and writes exactly ONE log entry.
 *
 * ---------------------------------------------------------------------------
 * WHY Log::warning AND WHY EXACTLY ONE LINE
 * ---------------------------------------------------------------------------
 * The production box runs `LOG_LEVEL=warning`. A `Log::info` here would be
 * written by this code, dropped by the logger, and leave a daily report that
 * looks like it is running and produces nothing — a failure below the log
 * level, which this repository has already been bitten by once.
 *
 * One line, not one per organisation: the question ("is anybody still on the
 * old list, and who") is one question, and an answer split across twenty lines
 * is an answer nobody reads to the end. The per-organisation detail rides
 * inside the context array.
 *
 * ---------------------------------------------------------------------------
 * READING IT
 * ---------------------------------------------------------------------------
 *   untagged  a build shipped BEFORE R1 read the legacy list. These are the
 *             installs S3b would break. Untagged hits on organisation 1 are
 *             Burlington's store build (v2.5 b44).
 *   tagged    an R1 build read the legacy list — which it only does when
 *             /menu answered 404 or was unreachable. While the kill row is
 *             set, EVERY R1 build is tagged here and that is correct, not a
 *             regression. Compare against `php artisan app-telemetry:builds`.
 *
 * Our own `tenancy:canary` probes are not in either bucket: the middleware
 * skips any request carrying `X-Canary`. Until that filter reached production,
 * the canary added about six untagged hits a day to organisation 1. A report
 * for a day before then, or for the day it shipped, reads organisation 1 too
 * high by that much. Those keys expire within three days.
 *
 * Zero of both for an organisation is not proof of nothing: a cache flush, a
 * restarted box with an in-memory store, or a day this command did not run all
 * look identical to silence. The counters are a floor on the traffic, never a
 * ceiling — say so before anybody deletes an endpoint on the strength of them.
 *
 * Read-only: it reads cache keys and the organisation list, and writes one log
 * line. Nothing is deleted, so a rerun is safe and idempotent.
 */
class LegacyFeaturesReport extends Command
{
    protected $signature = 'app:legacy-features-report
                            {--date= : The day to report (Y-m-d). Defaults to yesterday.}
                            {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Report how many devices read the legacy mobile /features list yesterday, per organisation, split by whether the build identifies itself.';

    public function handle(): int
    {
        $date = trim((string) $this->option('date')) ?: now()->subDay()->toDateString();

        // A malformed --date would read keys that can never exist and report a
        // confident zero. Refuse instead.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $this->error('--date must be Y-m-d.');

            return self::FAILURE;
        }

        $perOrg = [];
        $taggedTotal = 0;
        $untaggedTotal = 0;

        // Every organisation, including unlisted and child ones: a handset
        // registered against a child organisation reads that child's feature
        // list. An organisation deleted since yesterday drops out of this
        // report — it has no list to keep serving, so its counts would not
        // change any decision.
        foreach (Masjid::query()->orderBy('id')->pluck('name', 'id') as $id => $name) {
            $tagged = (int) Cache::get(CountLegacyFeaturesHit::key($date, (int) $id, 'tagged'), 0);
            $untagged = (int) Cache::get(CountLegacyFeaturesHit::key($date, (int) $id, 'untagged'), 0);

            if ($tagged === 0 && $untagged === 0) {
                continue;
            }

            $taggedTotal += $tagged;
            $untaggedTotal += $untagged;

            $perOrg[] = [
                'masjid_id' => (int) $id,
                'name' => (string) $name,
                'tagged' => $tagged,
                'untagged' => $untagged,
            ];
        }

        // Busiest first, and untagged decides the order: the organisations with
        // pre-R1 traffic are the ones the retirement is blocked on.
        usort($perOrg, fn (array $a, array $b) => [$b['untagged'], $b['tagged']] <=> [$a['untagged'], $a['tagged']]);

        $summary = [
            'date' => $date,
            'organisations' => count($perOrg),
            'tagged' => $taggedTotal,
            'untagged' => $untaggedTotal,
            'per_organisation' => $perOrg,
        ];

        // ONE line per run, at warning level so the production logger keeps it.
        Log::warning('legacy mobile /features usage', $summary);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Legacy /features reads on {$date}: {$untaggedTotal} from builds that do not identify themselves, {$taggedTotal} from R1 builds falling back.");

        if ($perOrg === []) {
            $this->line('  No organisation recorded a read. That is a floor, not a proof: a cache flush or a restarted cache store looks the same.');

            return self::SUCCESS;
        }

        $this->table(
            ['Org', 'Name', 'Pre-R1 (untagged)', 'R1 fallback (tagged)'],
            array_map(fn (array $row) => [
                $row['masjid_id'],
                $row['name'],
                $row['untagged'],
                $row['tagged'],
            ], $perOrg)
        );

        if ($untaggedTotal > 0) {
            $this->warn('  Builds shipped before R1 are still reading this list. It cannot be deleted yet (plan v3, S3b).');
        }

        return self::SUCCESS;
    }
}
