<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Masjid;
use App\Support\BucksExpiry;
use App\Support\SchoolCalendar;
use App\Support\SchoolSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write off Manara Bucks at the end of a class or a school year (T-003.4, R5).
 *
 * Hourly (routes/console.php, withoutOverlapping), for a school that has the `class_store`
 * grant only. The rules (the cutoffs, one `expired` row per child and cutoff, only what was
 * minted before the cutoff, never below zero) live in App\Support\BucksExpiry.
 *
 * Every group is considered, active or not: a class that has been switched off at year end is
 * exactly the one whose bucks should end. Fail-soft per class; ONE line per run on the
 * `monitors` channel; `--dry-run` writes nothing.
 */
class ExpireClassBucks extends Command
{
    protected $signature = 'bucks:expire
        {--masjid= : Only this organisation (its id)}
        {--dry-run : Work out what would expire; write nothing}';

    protected $description = 'Write off Manara Bucks at the end of a class or school year (grant: class_store)';

    public function handle(): int
    {
        $only = $this->option('masjid');

        if ($only !== null && ! ctype_digit((string) $only)) {
            $this->error('--masjid must be an organisation id.');

            return self::INVALID;
        }

        $dry = (bool) $this->option('dry-run');

        $run = [
            'dry_run' => $dry,
            'organisations' => 0,
            'skipped_off' => 0,
            'classes' => 0,
            'students' => 0,
            'bucks' => 0,
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
                $calendar = SchoolCalendar::for((int) $masjid->id);

                $groups = Group::withoutMasjidScope()
                    ->where('masjid_id', $masjid->id)
                    ->orderBy('id')
                    ->get();

                foreach ($groups as $group) {
                    try {
                        $one = BucksExpiry::forClass($masjid, $group, $calendar, $dry);
                        $run['classes']++;
                        $run['students'] += $one['students'];
                        $run['bucks'] += $one['bucks'];
                    } catch (Throwable $e) {
                        $run['failures']++;
                        Log::warning('bucks:expire failed for class '.$group->id.': '.$e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $run['failures']++;
                Log::warning('bucks:expire failed for organisation '.$masjid->id.': '.$e->getMessage());
            }
        }

        Log::channel('monitors')->info('bucks:expire', $run);

        $this->line(sprintf(
            'bucks:expire%s: %d organisation(s), %d class(es), %d student(s) expired %d buck(s), %d failure(s).',
            $dry ? ' (dry run)' : '',
            $run['organisations'],
            $run['classes'],
            $run['students'],
            $run['bucks'],
            $run['failures'],
        ));

        return self::SUCCESS;
    }
}
