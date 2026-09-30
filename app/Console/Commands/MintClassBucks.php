<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Masjid;
use App\Models\MasjidPointsSetting;
use App\Support\BucksMinter;
use App\Support\ClassStoreSettings;
use App\Support\PointsWeek;
use App\Support\SchoolPointsWeek;
use App\Support\SchoolSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Turn each class's closed points weeks into Manara Bucks (T-003.4, W6).
 *
 * Hourly (routes/console.php, withoutOverlapping), and for a school that has the
 * `class_store` grant ONLY: the grant is OFF for every organisation until a SuperAdmin
 * decides, so until then this runs, finds nobody and writes nothing. The rules (positive
 * points only, once per child and week, a two-week window of late changes, never below zero)
 * live in App\Support\BucksMinter and are written out there.
 *
 * The first time a school with the store on is seen, `bucks_from` is set to the start of the
 * week in progress, so switching the grant on never pays out history nobody expected. The
 * same holds after a PAUSE: a sweep that finds the store OFF for a school it had been running
 * for clears `bucks_from` (and `bucks_swept_at`, the mark of "swept while on"), so switching
 * the store back on counts from the week in progress and the weeks of the pause are not paid
 * out in one hourly run. A start day a SuperAdmin sets afterwards is honoured as any other.
 *
 * Fail-soft: one class failing is logged at WARNING (which production keeps) and the rest
 * still run. Every run writes ONE line to the `monitors` channel: production's
 * LOG_LEVEL=warning would drop an info line on the default channel, which would leave no
 * proof the sweep ever ran. `--dry-run` works out what would be written and writes nothing.
 */
class MintClassBucks extends Command
{
    protected $signature = 'bucks:mint
        {--masjid= : Only this organisation (its id)}
        {--dry-run : Work out what would be minted; write nothing}';

    protected $description = 'Turn each class\'s closed points weeks into Manara Bucks (grant: class_store)';

    public function handle(): int
    {
        $only = $this->option('masjid');

        if ($only !== null && ! ctype_digit((string) $only)) {
            $this->error('--masjid must be an organisation id.');

            return self::INVALID;
        }

        $dry = (bool) $this->option('dry-run');
        $now = CarbonImmutable::instance(Date::now());

        $run = [
            'dry_run' => $dry,
            'organisations' => 0,
            'skipped_off' => 0,
            'paused' => 0,
            'classes' => 0,
            'weeks' => 0,
            'minted_students' => 0,
            'minted_bucks' => 0,
            'adjusted_students' => 0,
            'adjusted_bucks' => 0,
            'failures' => 0,
        ];

        $masjids = Masjid::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get();

        foreach ($masjids as $masjid) {
            $run['organisations']++;

            if (! SchoolSettings::classStore($masjid)) {
                $run['skipped_off']++;

                // Inside the same per-organisation guard as the sweep: this runs for EVERY
                // store-OFF organisation each hour (all of them until the grant is given), so one
                // that throws must not fail the run (P4, the point's W5/W6 delta review).
                try {
                    if (! $dry && $this->pauseCounting($masjid)) {
                        $run['paused']++;
                    }
                } catch (Throwable $e) {
                    $run['failures']++;
                    Log::warning('bucks:mint failed to pause organisation '.$masjid->id.': '.$e->getMessage());
                }

                continue;
            }

            try {
                $this->sweepSchool($masjid, $now, $dry, $run);
            } catch (Throwable $e) {
                // One school must not stop the rest.
                $run['failures']++;
                Log::warning('bucks:mint failed for organisation '.$masjid->id.': '.$e->getMessage());
            }
        }

        Log::channel('monitors')->info('bucks:mint', $run);

        $this->line(sprintf(
            'bucks:mint%s: %d organisation(s), %d class(es), %d week(s), %d student(s) minted %d buck(s), %d adjusted by %d, %d failure(s).',
            $dry ? ' (dry run)' : '',
            $run['organisations'],
            $run['classes'],
            $run['weeks'],
            $run['minted_students'],
            $run['minted_bucks'],
            $run['adjusted_students'],
            $run['adjusted_bucks'],
            $run['failures'],
        ));

        return self::SUCCESS;
    }

    /**
     * The store is OFF for this school. If an earlier sweep had it ON, the weeks that close
     * from now until it is switched back on are a pause, not history: forget the start day so
     * the next sweep with the store on counts from the week in progress.
     *
     * @return bool whether a running school was paused by this call
     */
    private function pauseCounting(Masjid $masjid): bool
    {
        // THE DEPLOY WINDOW: the code goes out before `migrate` has run, and then
        // `bucks_swept_at` does not exist yet. Reading it would throw on every store-OFF
        // organisation, every hour, until the migration lands. Nothing has been swept with the
        // store on before that column exists, so there is nothing to pause: skip.
        if (! Schema::hasColumn('masjid_points_settings', 'bucks_swept_at')) {
            return false;
        }

        $row = MasjidPointsSetting::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->whereNotNull('bucks_swept_at')
            ->first();

        if ($row === null) {
            return false;
        }

        $row->bucks_from = null;
        $row->bucks_swept_at = null;
        $row->save();

        Log::warning('bucks:mint: the class store is off for organisation '.$masjid->id.'; counting restarts at the week in progress when it is switched back on');

        return true;
    }

    /** @param array<string,mixed> $run */
    private function sweepSchool(Masjid $masjid, CarbonImmutable $now, bool $dry, array &$run): void
    {
        $settings = ClassStoreSettings::for((int) $masjid->id);
        $bucksFrom = $settings['bucks_from'];

        if ($bucksFrom === null) {
            // Nothing retroactive by surprise: points count from the week in progress.
            $bucksFrom = PointsWeek::containing($now, SchoolPointsWeek::timezone((int) $masjid->id))->startDate();
        }

        if (! $dry) {
            // Written once per run of "on": the start day when there is none, and the mark that
            // this school has been swept with the store on (what tells a later OFF it is a pause).
            $row = MasjidPointsSetting::withoutMasjidScope()->firstOrNew(['masjid_id' => $masjid->id]);

            if (! $row->exists || $row->bucks_from === null || $row->bucks_swept_at === null) {
                $row->masjid_id = $masjid->id;
                $row->bucks_from ??= $bucksFrom;
                $row->bucks_swept_at ??= $now;
                $row->save();
            }
        }

        // Named explicitly: this command runs with no tenant bound, where the global scope
        // adds no filter at all.
        $groups = Group::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            try {
                $one = BucksMinter::forClass($masjid, $group, $now, $bucksFrom, $settings['points_per_buck'], $dry);
                $run['classes']++;

                foreach (['weeks', 'minted_students', 'minted_bucks', 'adjusted_students', 'adjusted_bucks'] as $field) {
                    $run[$field] += $one[$field];
                }
            } catch (Throwable $e) {
                $run['failures']++;
                Log::warning('bucks:mint failed for class '.$group->id.': '.$e->getMessage());
            }
        }
    }
}
