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
 * week in progress, so switching the grant on never pays out history nobody expected.
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

    /** @param array<string,mixed> $run */
    private function sweepSchool(Masjid $masjid, CarbonImmutable $now, bool $dry, array &$run): void
    {
        $settings = ClassStoreSettings::for((int) $masjid->id);
        $bucksFrom = $settings['bucks_from'];

        if ($bucksFrom === null) {
            // Nothing retroactive by surprise: points count from the week in progress.
            $bucksFrom = PointsWeek::containing($now, SchoolPointsWeek::timezone((int) $masjid->id))->startDate();

            if (! $dry) {
                $row = MasjidPointsSetting::withoutMasjidScope()->firstOrNew(['masjid_id' => $masjid->id]);
                $row->masjid_id = $masjid->id;
                $row->bucks_from = $bucksFrom;
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
