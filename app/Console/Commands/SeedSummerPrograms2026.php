<?php

namespace App\Console\Commands;

use App\Jobs\SendMasjidNotificationJob;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * One-off content seeder for the June–July 2026 summer programs.
 *
 * Creates one Event per occurrence (the Events tab shows a rolling ~1-week
 * window, so each surfaces as its week arrives) + a single "Summer Programs
 * 2026" summary Announcement. Idempotent: deletes the previously-seeded summer
 * events + announcement before recreating, so it is safe to re-run. `--clear`
 * removes the seeded content and exits. `--push` also sends one announcement
 * push (targets subscription ids).
 *
 * Times are stored as local wall-clock "Y-m-d H:i:s" — the same convention the
 * admin UI uses (no timezone conversion on the column), so they display as
 * entered (e.g. 7:00 PM).
 */
class SeedSummerPrograms2026 extends Command
{
    protected $signature = 'events:seed-summer-2026 {--masjid=1} {--push : Also send one announcement push} {--clear : Remove the seeded content and exit}';

    protected $description = 'Seed the June–July 2026 summer programs into Events + a summary Announcement (idempotent).';

    private const ANNOUNCEMENT_TITLE = 'Summer Programs 2026';

    public function handle(): int
    {
        $masjidId = (int) $this->option('masjid');
        $masjid = Masjid::find($masjidId);
        if (!$masjid) {
            $this->error("masjid {$masjidId} not found");
            return self::FAILURE;
        }

        // [title, details, place, time H:i, duration mins, [dates...]]
        $programs = [
            ['Prophetic Leadership', 'Weekly prophetic leadership program.', 'Musallah', '19:00', 60,
                ['2026-06-23', '2026-06-30', '2026-07-07', '2026-07-14', '2026-07-21', '2026-07-28']],
            ['Youth Night', 'Youth Night — Stories of the Sahaba.', 'Youth Center', '19:00', 90,
                ['2026-06-24', '2026-07-01', '2026-07-08', '2026-07-15', '2026-07-22', '2026-07-29']],
            ['Book Study', 'Weekly book study.', 'Musallah', '19:00', 60,
                ['2026-07-02', '2026-07-09', '2026-07-16', '2026-07-23', '2026-07-30']],
            ['Lessons from Surah Al-Kahf', 'Weekly lessons from Surah Al-Kahf.', 'Musallah', '19:00', 60,
                ['2026-06-26', '2026-07-10', '2026-07-17', '2026-07-24', '2026-07-31']],
            ['Soccer (Girls and Boys)', 'Soccer for girls and boys.', 'Masjid Playground', '10:30', 90,
                ['2026-06-27']],
            ['Soccer (Girls and Boys)', 'Soccer for girls and boys.', 'TBD', '10:30', 90,
                ['2026-07-11', '2026-07-18', '2026-07-25']],
            // Holiday weekend (Jul 3–4) — tentative per the calendar legend.
            ['Lessons from Surah Al-Kahf', 'Tentative — holiday weekend, TBD based on availability.', 'TBD', '19:00', 60,
                ['2026-07-03']],
            ['Soccer (Girls and Boys)', 'Tentative — holiday weekend, TBD based on availability.', 'TBD', '10:30', 90,
                ['2026-07-04']],
        ];

        // Clean previously-seeded summer content so re-runs don't duplicate.
        $titles = array_values(array_unique(array_map(fn ($p) => $p[0], $programs)));
        $deleted = Event::where('masjid_id', $masjidId)
            ->whereBetween('start', ['2026-06-22 00:00:00', '2026-08-01 23:59:59'])
            ->whereIn('title', $titles)
            ->delete();
        Announcement::where('masjid_id', $masjidId)
            ->where('title', self::ANNOUNCEMENT_TITLE)
            ->forceDelete();
        $this->info("cleared {$deleted} existing summer event(s) + announcement");

        if ($this->option('clear')) {
            MobileCache::flushMasjid($masjidId, MobileCache::EVENTS);
            MobileCache::flushMasjid($masjidId, MobileCache::ANNOUNCEMENTS);
            $this->info('cleared. exiting (--clear).');
            return self::SUCCESS;
        }

        $created = 0;
        foreach ($programs as [$title, $details, $place, $time, $duration, $dates]) {
            foreach ($dates as $date) {
                $start = "{$date} {$time}:00";
                Event::create([
                    'masjid_id' => $masjidId,
                    'title' => $title,
                    'details' => $details,
                    'place' => $place,
                    'start' => $start,
                    'end' => Carbon::parse($start)->addMinutes($duration)->format('Y-m-d H:i:s'),
                ]);
                $created++;
            }
        }
        $this->info("created {$created} event(s)");

        $text = "Join us for our Summer Programs, in shaa Allah!\n\n"
            . "• Tuesdays 7:00 PM — Prophetic Leadership (Musallah)\n"
            . "• Wednesdays 7:00 PM — Youth Night: Stories of the Sahaba (Youth Center)\n"
            . "• Thursdays 7:00 PM — Book Study (Musallah)\n"
            . "• Fridays 7:00 PM — Lessons from Surah Al-Kahf (Musallah)\n"
            . "• Saturdays 10:30 AM — Soccer for Girls & Boys\n\n"
            . "June 22 – July 31, 2026. See the Events tab for dates. (Jul 3–4 are tentative — holiday weekend.)";

        Announcement::create([
            'masjid_id' => $masjidId,
            'title' => self::ANNOUNCEMENT_TITLE,
            'details' => 'June–July 2026 summer programs schedule.',
            'text' => $text,
            'start_date' => '2026-06-22',
            'end_date' => '2026-07-31',
        ]);
        $this->info('created announcement "' . self::ANNOUNCEMENT_TITLE . '"');

        MobileCache::flushMasjid($masjidId, MobileCache::EVENTS);
        MobileCache::flushMasjid($masjidId, MobileCache::ANNOUNCEMENTS);

        if ($this->option('push')) {
            $subscriptionIds = MobileAppUser::where('masjid_id', $masjidId)
                ->whereNotNull('onesignal_subscription_id')
                ->pluck('onesignal_subscription_id')
                ->filter()
                ->values()
                ->toArray();

            if (empty($subscriptionIds)) {
                $this->warn('no devices with a subscription id — push skipped');
            } else {
                $notification = $masjid->notifications()->create([
                    'title' => 'Summer Programs 2026 📅',
                    'message' => 'Tue: Prophetic Leadership • Wed: Youth Night • Thu: Book Study • Fri: Surah Al-Kahf • Sat: Soccer. Tap for details!',
                ]);
                SendMasjidNotificationJob::dispatch($notification, $masjid, $subscriptionIds);
                $this->info('dispatched announcement push to ' . count($subscriptionIds) . ' device(s)');
            }
        }

        return self::SUCCESS;
    }
}
