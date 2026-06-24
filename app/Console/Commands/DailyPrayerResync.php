<?php

namespace App\Console\Commands;

use App\Jobs\SendPrayerSyncJob;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Console\Command;

/**
 * Daily "re-sync" nudge.
 *
 * Once a day, sends every masjid's devices a silent (content-available) push so
 * they wake in the background, re-pull the latest prayer/iqama times, and re-arm
 * their rolling 6-day local-notification window — even for users who haven't
 * opened the app. This keeps the buffer maximally fresh; it COMPLEMENTS (does
 * not replace) the buffer, which still covers the days a silent push doesn't
 * land (force-quit app, Do Not Disturb, low-power, no network).
 *
 * Silent + queued + fail-soft (via SendPrayerSyncJob). Only devices that have
 * reported an OneSignal subscription id are targetable.
 */
class DailyPrayerResync extends Command
{
    protected $signature = 'prayers:daily-resync {--dry-run : Log targets without sending}';

    protected $description = 'Daily silent push that wakes devices to re-pull prayer times and re-arm their notification window.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (Masjid::all() as $masjid) {
            $subscriptionIds = MobileAppUser::where('masjid_id', $masjid->id)
                ->whereNotNull('onesignal_subscription_id')
                ->pluck('onesignal_subscription_id')
                ->filter()
                ->values()
                ->toArray();

            if (empty($subscriptionIds)) {
                continue;
            }

            $this->info("masjid {$masjid->id}: " . count($subscriptionIds) . ' device(s)' . ($dryRun ? ' [dry-run]' : ''));

            if (!$dryRun) {
                SendPrayerSyncJob::dispatch($masjid->id, $subscriptionIds);
            }
        }

        return self::SUCCESS;
    }
}
