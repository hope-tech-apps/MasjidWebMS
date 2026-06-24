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
 * Events: one Event per occurrence for the 5 dated programs (the Events tab
 * shows a rolling ~1-week window). `start` is stored as local wall-clock
 * "Y-m-d H:i:s" (matches the app's toDate parser + admin convention).
 *
 * Announcements: one per program, each with its poster. IMPORTANT — the app's
 * announcement detail page renders the `details` field (not `text`), so the
 * full description goes in `details`. Posters are attached from --posters-dir.
 *
 * Idempotent (clears prior seeded content first). --clear removes it. --push
 * sends one announcement push (subscription-id targeted).
 */
class SeedSummerPrograms2026 extends Command
{
    protected $signature = 'events:seed-summer-2026 {--masjid=1} {--push : Also send one announcement push} {--posters-dir= : Directory of poster PNGs to attach} {--clear : Remove the seeded content and exit}';

    protected $description = 'Seed the June–July 2026 summer programs into Events + per-program Announcements (idempotent).';

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
            ['Book Study', 'A Beautiful Patience — 40 Life Lessons. Led by Sister Zahra & Zonash.', 'Musallah', '19:00', 60,
                ['2026-07-02', '2026-07-09', '2026-07-16', '2026-07-23', '2026-07-30']],
            ['Lessons from Surah Al-Kahf', 'Weekly lessons from Surah Al-Kahf.', 'Musallah', '19:00', 60,
                ['2026-06-26', '2026-07-10', '2026-07-17', '2026-07-24', '2026-07-31']],
            ['Soccer (Girls and Boys)', 'Summer Youth Soccer for girls and boys. Register for details.', 'Masjid Playground', '10:30', 90,
                ['2026-06-27']],
            ['Soccer (Girls and Boys)', 'Summer Youth Soccer for girls and boys. Register for details.', 'TBD', '10:30', 90,
                ['2026-07-11', '2026-07-18', '2026-07-25']],
            ['Lessons from Surah Al-Kahf', 'Tentative — holiday weekend, TBD based on availability.', 'TBD', '19:00', 60,
                ['2026-07-03']],
            ['Soccer (Girls and Boys)', 'Tentative — holiday weekend, TBD based on availability.', 'TBD', '10:30', 90,
                ['2026-07-04']],
        ];

        // [title, details (full — shown on the detail page), posterFile]
        $announcements = [
            ['Summer Programs 2026',
                'Our summer programs are here! Qur\'an, Seerah, Book Study, Stories of the Sahaba, Soccer, Gardening, Fitness and more — running June 22 to July 31, 2026. See the Events tab for the weekly dates and times, in shaa Allah.',
                '00-summer-programs.svg.png'],
            ['Prophetic Leadership',
                'Prophetic Leadership — every Tuesday at 7:00 PM in the Musallah. June 23 – July 28, 2026.',
                '01-prophetic-leadership.svg.png'],
            ['Youth Night: Stories of the Sahaba',
                'Youth Night featuring Stories of the Sahaba — every Wednesday at 7:00 PM at the Youth Center. June 24 – July 29, 2026.',
                '02-youth-night.svg.png'],
            ['Book Study: A Beautiful Patience',
                'Book Study — "A Beautiful Patience: 40 Life Lessons." Every Thursday at 7:00 PM in the Musallah, led by Sister Zahra & Zonash. July 2 – July 30, 2026.',
                '03-book-study.svg.png'],
            ['Lessons from Surah Al-Kahf',
                'Lessons from Surah Al-Kahf — every Friday at 7:00 PM in the Musallah. June 26 – July 31, 2026.',
                '04-surah-al-kahf.svg.png'],
            ['Summer Youth Soccer',
                'Summer Youth Soccer for girls and boys — every Saturday at 10:30 AM. Register for details. June 27 – July 25, 2026.',
                '05-soccer.svg.png'],
            ['Seeds of Barakah — Gardening',
                'Seeds of Barakah, our gardening program — plant, grow, serve, and beautify the House of Allah. Dates to be announced, in shaa Allah.',
                '06-seeds-of-barakah.svg.png'],
            ['Fitness',
                'Fitness — move, strengthen, and thrive. Dates to be announced, in shaa Allah.',
                '07-fitness.svg.png'],
            ['Qur\'an',
                'Qur\'an program — recite, reflect, and connect. Dates to be announced, in shaa Allah.',
                '08-quran.svg.png'],
            ['Seerah',
                'Seerah — the life and legacy of the Prophet (peace be upon him). Dates to be announced, in shaa Allah.',
                '09-seerah.svg.png'],
        ];

        // Clean previously-seeded content so re-runs don't duplicate.
        $eventTitles = array_values(array_unique(array_map(fn ($p) => $p[0], $programs)));
        $deletedEvents = Event::where('masjid_id', $masjidId)
            ->whereBetween('start', ['2026-06-22 00:00:00', '2026-08-01 23:59:59'])
            ->whereIn('title', $eventTitles)
            ->delete();
        $annTitles = array_map(fn ($a) => $a[0], $announcements);
        Announcement::where('masjid_id', $masjidId)->whereIn('title', $annTitles)->forceDelete();
        $this->info("cleared {$deletedEvents} event(s) + " . count($annTitles) . ' announcement title(s)');

        if ($this->option('clear')) {
            MobileCache::flushMasjid($masjidId, MobileCache::EVENTS);
            MobileCache::flushMasjid($masjidId, MobileCache::ANNOUNCEMENTS);
            $this->info('cleared. exiting (--clear).');
            return self::SUCCESS;
        }

        // Events
        $createdEvents = 0;
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
                $createdEvents++;
            }
        }
        $this->info("created {$createdEvents} event(s)");

        // Announcements (+ posters)
        $postersDir = rtrim((string) $this->option('posters-dir'), '/');
        $createdAnn = 0;
        $firstAnnouncement = null;
        foreach ($announcements as [$title, $details, $posterFile]) {
            $ann = Announcement::create([
                'masjid_id' => $masjidId,
                'title' => $title,
                'details' => $details,
                'text' => $details,
                'start_date' => '2026-06-22',
                'end_date' => '2026-07-31',
            ]);
            $firstAnnouncement ??= $ann;

            if ($postersDir) {
                $path = "{$postersDir}/{$posterFile}";
                if (is_file($path)) {
                    $ann->addMedia($path)->preservingOriginal()->toMediaCollection('announcements');
                } else {
                    $this->warn("poster not found: {$path}");
                }
            }
            $createdAnn++;
        }
        $this->info("created {$createdAnn} announcement(s)");

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
