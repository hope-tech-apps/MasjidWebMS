<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Prayer;
use App\Services\OnesignalService;
use App\Support\IqamaResolver;
use App\Support\PrayerPushes;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Server-side prayer backstop.
 *
 * Runs every minute (see routes/console.php). For each masjid it finds any
 * adhan / iqama time that has JUST occurred and pushes a reminder — but ONLY to
 * devices that have gone dark (no heartbeat for > PrayerPushes::STALE_DAYS), i.e.
 * whose local rolling-window notifications have lapsed. Active devices keep their
 * precise local notifications and are never targeted here, so there are no
 * duplicates.
 *
 * An organisation whose Prayer times module is switched off is skipped entirely
 * (PrayerPushes::allowedFor, fail-open).
 *
 * Timezone correctness: the stored `prayers_data` adhan values are full UTC
 * datetimes, so every comparison is done on absolute UTC instants. The iqama
 * instant comes from App\Support\IqamaResolver, the rule the website and the
 * apps follow: a fixed time from a range covering the row's day wins, otherwise
 * adhan + the prayer's offset. It used to be adhan + offset only, so a masjid on
 * fixed times had its dark devices told "the iqama has arrived" minutes before
 * (or after) the time on its own website. It is still NOT read from the H:i:s
 * `iqama_times_data`, which loses its date and is unsafe for late-night prayers
 * that cross midnight UTC.
 */
class SendDuePrayerNotifications extends Command
{
    protected $signature = 'prayers:send-due
        {--dry-run : Log what would send without actually sending}
        {--only-device= : Restrict recipients to this single device_id (testing)}
        {--ignore-staleness : Ignore the dark-device filter; send to all of the masjid (testing)}';

    protected $description = 'Push adhan/iqama at prayer time to devices that have gone dark, as a backstop to local notifications.';

    /** Fire if the prayer instant occurred within this many seconds (covers a late cron run). */
    private const WINDOW_SECONDS = 90;

    private const PRAYERS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

    /**
     * The notification channels the ANDROID app creates for itself, one per
     * sound (res/raw/adhan.mp3, res/raw/iqamah.mp3).
     *
     * On Android 8+ the channel — not the payload — decides what a notification
     * sounds like, so a push that names no channel plays the default system
     * tone. This backstop targets devices by subscription id with NO platform
     * filter, so until these were sent every Android handset got the adhan
     * reminder as a generic ping while iOS got the adhan.
     *
     * The strings must match the app's channel ids EXACTLY: OneSignal accepts an
     * id that names no channel on the handset and silently falls back to the
     * default tone. A channel's sound is also fixed for its lifetime, which is
     * what the `_v1` suffix is for — changing a sound means a new channel id on
     * both sides, never an edit to an existing one.
     *
     * PUBLIC so `prayers:test-push` can send on the SAME channels rather than
     * repeating the strings. That command exists to prove the sound on a real
     * handset, and a second copy of these ids is the one place a typo would
     * make the verification tool disagree with the thing it verifies.
     */
    public const ANDROID_CHANNEL_ADHAN = 'prayer_adhan_v1';

    public const ANDROID_CHANNEL_IQAMA = 'prayer_iqama_v1';

    /**
     * The once-per-prayer-day guard for one push.
     *
     * Keyed on the prayer's OWN day (the prayers row's date), never the UTC date
     * of the instant. The UTC date used to be the key, which was nearly harmless
     * while iqama was adhan + offset and moved about a minute a day. A fixed time
     * can jump: MEC's Isha is 8:45 PM EDT until 2026-10-31, which is 00:45 UTC on
     * Nov 1, and on Nov 1 it returns to adhan + 10, about 23:4x UTC the same UTC
     * date, so the first push's 26-hour guard silently swallowed the second.
     *
     * Public so the MEC apply script can tell whether this fix is deployed by
     * asking the code rather than grepping it.
     */
    public static function guardKey(int $masjidId, string $prayer, string $type, string $day): string
    {
        return "prayer_push:{$masjidId}:{$prayer}:{$type}:{$day}";
    }

    public function handle(OnesignalService $onesignal): int
    {
        $now = Carbon::now('UTC');
        $dryRun = (bool) $this->option('dry-run');
        $onlyDevice = $this->option('only-device');
        $ignoreStaleness = (bool) $this->option('ignore-staleness');

        // The ranges are eager-loaded with the settings: this runs every minute for
        // every masjid, and the resolver reads them for every prayer of every row.
        foreach (Masjid::with('iqamaTimeSettings.timeRanges', 'appPublishing')->get() as $masjid) {
            if (! PrayerPushes::allowedFor($masjid)) {
                continue;
            }

            // A masjid with no iqama row has never been sent anything here, adhan
            // included; that is unchanged.
            if (!$masjid->iqamaTimeSettings) {
                continue;
            }

            $iqamaTimes = IqamaResolver::for($masjid->iqamaTimeSettings, $masjid->timezone);

            // Fixed times need the masjid's own zone to become instants; without
            // one its iqama pushes stay at adhan + offset (IqamaResolver::
            // placesFixedTimes). Said once a day at warning level, which is the
            // level production logs, so the gap is findable without spamming a
            // line a minute.
            if ($iqamaTimes->usesRanges() && ! $iqamaTimes->placesFixedTimes()
                && Cache::add("prayer_push_zone_unset:{$masjid->id}:{$now->format('Y-m-d')}", true, now()->addHours(26))) {
                Log::warning(sprintf(
                    'prayers:send-due masjid=%d is on Specific Time Ranges but its timezone %s is not its own; iqama pushes use adhan + offset until it is set',
                    $masjid->id,
                    json_encode($masjid->timezone),
                ));
            }

            // A prayer instant can land on the UTC calendar day before/after the
            // local prayer day, so scan a 3-day window of rows around "now".
            $rows = Prayer::where('masjid_id', $masjid->id)
                ->whereBetween('date', [
                    $now->copy()->subDay()->format('Y-m-d'),
                    $now->copy()->addDay()->format('Y-m-d'),
                ])
                ->get();

            foreach ($rows as $row) {
                $data = $row->prayers_data; // decoded object (model accessor)
                if (!$data) {
                    continue;
                }

                // The row's date is the prayer's own day in the masjid's calendar
                // (the generator's date), which is the day a range is tested
                // against: an Isha after UTC midnight still belongs to it.
                $day = substr((string) $row->date, 0, 10);

                foreach (self::PRAYERS as $prayer) {
                    if (!isset($data->{$prayer})) {
                        continue;
                    }

                    $adhan = Carbon::parse($data->{$prayer})->utc();
                    $this->maybeSend($onesignal, $masjid, $prayer, 'adhan', $day, $adhan, $now, $dryRun, $onlyDevice, $ignoreStaleness);

                    $iqama = $iqamaTimes->iqamaAt($prayer, $day, $adhan);
                    $this->maybeSend($onesignal, $masjid, $prayer, 'iqama', $day, $iqama, $now, $dryRun, $onlyDevice, $ignoreStaleness);
                }
            }
        }

        return self::SUCCESS;
    }

    private function maybeSend(
        OnesignalService $onesignal,
        Masjid $masjid,
        string $prayer,
        string $type,
        string $day,
        Carbon $time,
        Carbon $now,
        bool $dryRun,
        ?string $onlyDevice,
        bool $ignoreStaleness,
    ): void {
        // Only fire if the prayer instant occurred within the last WINDOW_SECONDS.
        if (!$time->betweenIncluded($now->copy()->subSeconds(self::WINDOW_SECONDS), $now)) {
            return;
        }

        // Once-per-day idempotency guard so a late/overlapping run can't double-fire.
        $guard = self::guardKey($masjid->id, $prayer, $type, $day);
        if (Cache::has($guard)) {
            return;
        }

        // Only devices that have reported an OneSignal subscription id are
        // targetable (alias targeting doesn't resolve).
        $query = MobileAppUser::where('masjid_id', $masjid->id)
            ->whereNotNull('onesignal_subscription_id');
        if ($onlyDevice) {
            $query->where('device_id', $onlyDevice);
        } elseif (!$ignoreStaleness) {
            // Dark devices only: have checked in at least once (so they run a
            // heartbeat-capable build) but not within STALE_DAYS. NULL is
            // excluded on purpose — never risk double-notifying an active device.
            $query->whereNotNull('last_active_at')
                ->where('last_active_at', '<', $now->copy()->subDays(PrayerPushes::STALE_DAYS));
        }

        $subscriptionIds = $query->pluck('onesignal_subscription_id')->filter()->values()->toArray();

        $label = ucfirst($prayer);
        $title = $type === 'iqama' ? "Iqama time for {$label}" : "It's time for {$label}";
        $body = $type === 'iqama'
            ? "The iqama time for {$label} has arrived"
            : "The time for {$label} prayer has arrived";
        $sound = $type === 'iqama' ? 'iqamah.wav' : 'adhan.wav';

        // Kept next to $sound deliberately: the two are the same decision on two
        // platforms, and a new prayer sound that updates one and not the other
        // is a push that is right on iOS and a default ping on Android.
        $androidChannel = $type === 'iqama'
            ? self::ANDROID_CHANNEL_IQAMA
            : self::ANDROID_CHANNEL_ADHAN;

        Log::info(sprintf(
            'prayers:send-due %s %s masjid=%d recipients=%d%s',
            $type, $label, $masjid->id, count($subscriptionIds), $dryRun ? ' [dry-run]' : ''
        ));

        if ($dryRun) {
            return;
        }

        // Mark sent BEFORE the network call so an overlapping run can't double-fire.
        Cache::put($guard, true, now()->addHours(26));

        if (empty($subscriptionIds)) {
            return;
        }

        $onesignal->sendPrayerAlert(
            $subscriptionIds,
            $title,
            $body,
            $sound,
            ['masjid_id' => $masjid->id, 'prayer' => $prayer, 'kind' => $type],
            // Adhan pushes carry the category so long-press shows "Play Full Adhan".
            $type === 'adhan' ? 'PRAYER_ADHAN' : null,
            // Route through the masjid's own OneSignal app when provisioned.
            $masjid,
            // Android's equivalent of ios_sound + ios_category in one field.
            $androidChannel
        );
    }
}
