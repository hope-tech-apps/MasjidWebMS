<?php

namespace App\Console\Commands;

use App\Models\MobileAppUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What builds are actually out there.
 *
 * Active handsets (`last_active_at` inside the window) grouped by platform,
 * marketing version and build, newest activity first. The reading that decides
 * whether the legacy `/features` endpoint can be deleted and whether a
 * tester-facing build is safe to ship.
 *
 * ---------------------------------------------------------------------------
 * "pre-R1" IS A REAL ROW, NOT A GAP
 * ---------------------------------------------------------------------------
 * A NULL `app_build` means the handset has never once called this server from a
 * build that sends `X-Manara-App`. That is the interesting row: it is
 * Burlington's store build, Play vc13, and every TestFlight install nobody
 * updated. It is printed as `pre-R1` rather than blank so that nobody reads a
 * blank cell as a data problem and moves on.
 *
 * The count is of DEVICES, not people and not launches. One person with two
 * handsets is two rows here; a handset that launches forty times a day is one.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CANNOT TELL YOU
 * ---------------------------------------------------------------------------
 *  - A device that has not called in `--days` is not counted, and a device that
 *    was uninstalled still counts until its window lapses. "Active" here is
 *    "the server heard from it", nothing stronger.
 *  - The values are client-supplied (App\Support\AppClientHeader). They are
 *    counted, never trusted: nothing in this application authorises on them.
 *  - A pre-R1 row cannot be told from a device whose app was reinstalled from
 *    an old build — the controller deliberately never nulls a stored value, so
 *    a device that ever reported R1 keeps saying R1.
 *
 * Read-only. It runs one grouped SELECT and writes nothing.
 */
class AppTelemetryBuilds extends Command
{
    protected $signature = 'app-telemetry:builds
                            {--days=30 : Count a device active if it called within this many days}
                            {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Show which app builds active devices are running, grouped by platform, version and build.';

    /** What a NULL column is printed as, in both output modes. */
    public const PRE_R1 = 'pre-R1';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $rows = MobileAppUser::query()
            ->where('last_active_at', '>=', $since)
            ->select([
                'app_platform',
                'app_version',
                'app_build',
                DB::raw('COUNT(*) as devices'),
                DB::raw('MAX(last_active_at) as last_seen'),
            ])
            ->groupBy('app_platform', 'app_version', 'app_build')
            // Biggest cohort first: the question is almost always "how many are
            // still on the old thing", and that is one line, at the top.
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->map(fn ($row) => [
                'platform' => $row->app_platform ?? self::PRE_R1,
                'version' => $row->app_version ?? self::PRE_R1,
                'build' => $row->app_build ?? self::PRE_R1,
                'devices' => (int) $row->devices,
                'last_seen' => (string) $row->last_seen,
                'tagged' => $row->app_build !== null,
            ])
            ->all();

        $total = array_sum(array_column($rows, 'devices'));
        $untagged = array_sum(array_map(
            fn (array $row) => $row['tagged'] ? 0 : $row['devices'],
            $rows
        ));

        if ($this->option('json')) {
            $this->line(json_encode([
                'days' => $days,
                'since' => $since->toDateTimeString(),
                'devices' => $total,
                'devices_pre_r1' => $untagged,
                'builds' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Active devices in the last {$days} days: {$total}");

        if ($rows === []) {
            $this->line('  No device has called in that window.');

            return self::SUCCESS;
        }

        $this->table(
            ['Platform', 'Version', 'Build', 'Devices', 'Last seen'],
            array_map(fn (array $row) => [
                $row['platform'],
                $row['version'],
                $row['build'],
                $row['devices'],
                $row['last_seen'],
            ], $rows)
        );

        if ($untagged > 0) {
            $this->warn("  {$untagged} of them have never reported a build (" . self::PRE_R1 . ').');
            $this->line('  Those are the installs that still depend on the legacy /features list.');
            $this->line('  Cross-check with: php artisan app:legacy-features-report');
        } else {
            $this->line('  Every active device has reported a build.');
        }

        return self::SUCCESS;
    }
}
