<?php

namespace App\Support;

use App\Models\Donation;
use App\Models\Fund;
use App\Models\IqamaTimeRange;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\SplashAnnouncement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * What keeps moving when a SuperAdmin switches a module off, in sentences.
 *
 * Served as `facts` on each entry of GET /api/admin/masjids/{id}/capabilities
 * (MasjidsController::capabilities), printed under the switch and repeated in
 * its confirm dialog, so a switch-off is decided with the numbers in front of
 * whoever flips it. Only giving, prayer_times and splash have facts; every
 * other key answers [], the five app-only worship modules included — their
 * switch decides one row of the app menu and there is nothing else to count.
 *
 * A fact is not always a number: prayer_times names the org-type floor the app
 * menu applies on top of the switch, so a SuperAdmin flipping it for a school
 * is not left thinking the app will start showing a prayer table.
 *
 * Every query filters by masjid_id by hand: the panel is a SuperAdmin request,
 * which binds no tenant, so a tenant scope would filter nothing. And a fact is
 * never worth an error page: a query that throws gives [] for that module and
 * one warning line (production is LOG_LEVEL=warning), and the panel still loads.
 */
final class ModuleFacts
{
    /** @return list<string> */
    public static function for(Masjid $masjid, string $key): array
    {
        try {
            return match ($key) {
                'giving' => self::giving($masjid),
                'prayer_times' => self::prayerTimes($masjid),
                'splash' => self::splash($masjid),
                default => [],
            };
        } catch (\Throwable $e) {
            Log::warning('Module facts could not be read', [
                'masjid_id' => (int) $masjid->id,
                'module' => $key,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** @return list<string> */
    private static function giving(Masjid $masjid): array
    {
        $facts = [];

        $facts[] = $masjid->canAcceptDonations()
            ? 'Stripe can take card gifts for this organisation'
            : 'Stripe is not connected';

        // The same two counts the switch-off precondition refuses on: gifts
        // Stripe can bill (cancel first), and checkout pages still open (wait).
        $live = GivingSwitch::liveSubscriptionCount($masjid);
        $facts[] = self::counted($live, 'monthly gift can', 'monthly gifts can') . ' still charge donors';

        $openCheckouts = GivingSwitch::openCheckoutCount($masjid);
        $facts[] = self::counted($openCheckouts, 'monthly-gift checkout page', 'monthly-gift checkout pages')
            . ' opened in the last 24 hours can still start a monthly gift';

        // Checkout sessions last Stripe's default 24 hours, so a gift started
        // before the flip can still complete after it.
        $pending = Donation::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $facts[] = self::counted($pending, 'gift', 'gifts') . ' started in the last 24 hours may still complete';

        // Imported Wix history is not a gift this module recorded.
        $recorded = Donation::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('status', 'succeeded')
            ->withoutHistorical()
            ->where('created_at', '>=', now()->subYear())
            ->count();
        $facts[] = self::counted($recorded, 'gift', 'gifts') . ' recorded in the last 12 months';

        $funds = Fund::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('is_active', true)
            ->count();
        $facts[] = self::counted($funds, 'active fund', 'active funds');

        return $facts;
    }

    /** @return list<string> */
    private static function prayerTimes(Masjid $masjid): array
    {
        $facts = [];

        // The app's prayer table has a second rule this switch cannot lift: the
        // app menu computes it as isMasjid() AND this module, so switching it ON
        // for a school or community organisation gives them the reminders, the
        // refresh and the website times — and still no table in the app. Said
        // first, because it is the condition the rest of these lines sit under
        // (owner decision 4, 2026-09-15: the panel must not hide a second rule).
        if (! $masjid->isMasjid()) {
            $facts[] = 'App prayer table: masjids only';
        }

        $setting = IqamaTimeSetting::where('masjid_id', $masjid->id)->first();

        if ($setting === null) {
            $facts[] = 'No prayer settings saved yet';
        } else {
            $lastFixed = IqamaTimeRange::where('iqama_time_setting_id', $setting->id)->max('end_date');

            // The mode decides whether the ranges are in use, as it does for the
            // apps and the push (IqamaResolver::usesRanges). On Minutes After
            // Adhan the website alone still prints a covering range (see
            // IqamaResolver::coveringTime), so the sentence says exactly that
            // rather than "set until" a date nothing but the website honours.
            $onRanges = IqamaResolver::for($setting, $masjid->timezone)->usesRanges();

            $facts[] = match (true) {
                $lastFixed === null => 'No fixed iqama dates',
                $onRanges => 'Fixed iqama times are set until ' . Carbon::parse($lastFixed)->format('F j, Y') . '; after that the website and apps show minutes after adhan',
                default => 'Fixed iqama times are stored until ' . Carbon::parse($lastFixed)->format('F j, Y') . ' but not in use: the apps and prayer reminders show minutes after adhan, while the website still shows a stored time on the days it covers',
            };
        }

        // Exactly the devices the backstop (prayers:send-due) targets: a
        // subscription id, and a heartbeat that has gone quiet for STALE_DAYS.
        // NULL last_active_at is never targeted there, so never counted here.
        $staleDays = PrayerPushes::STALE_DAYS;
        $stale = self::subscribedDevices($masjid)
            ->whereNotNull('last_active_at')
            ->where('last_active_at', '<', now()->subDays($staleDays))
            ->count();
        $facts[] = $stale === 1
            ? "1 phone that has not opened the app for {$staleDays} days gets Manara's backup prayer reminders"
            : "{$stale} phones that have not opened the app for {$staleDays} days get Manara's backup prayer reminders";

        // The daily resync (prayers:daily-resync) targets every subscribed device.
        $subscribed = self::subscribedDevices($masjid)->count();
        $facts[] = $subscribed === 1
            ? '1 phone gets the daily background refresh'
            : "{$subscribed} phones get the daily background refresh";

        // The resolution OnesignalService::resolveConfig uses: the organisation's
        // own app when it has one, otherwise the shared platform app.
        $publishing = $masjid->appPublishing()->first();

        if ($publishing !== null && $publishing->hasOwnOnesignalApp()) {
            $facts[] = 'This organisation has its own OneSignal app; prayer pushes go through it';
        } elseif (filled(config('onesignal.api_url')) && filled(config('onesignal.app_id')) && filled(config('onesignal.app_rest_api_key'))) {
            $facts[] = 'This organisation has no OneSignal app of its own; pushes use the platform app';
        } else {
            $facts[] = 'This organisation has no OneSignal app of its own, and no platform OneSignal app is configured, so no prayer pushes are sent';
        }

        return $facts;
    }

    /** @return list<string> */
    private static function splash(Masjid $masjid): array
    {
        // A live splash keeps showing until its end date whatever the switch
        // says (owner, 2026-09-14), so the date is the fact.
        $live = SplashAnnouncement::where('masjid_id', $masjid->id)
            ->active()
            ->orderByDesc('ends_at')
            ->first();

        if ($live === null) {
            return ['No live splash'];
        }

        $endsAt = $live->ends_at->copy()->setTimezone(MasjidTime::zoneFor($masjid->id));

        return ['A splash is live until ' . $endsAt->format('F j, Y g:i A')];
    }

    private static function subscribedDevices(Masjid $masjid)
    {
        return MobileAppUser::where('masjid_id', $masjid->id)
            ->whereNotNull('onesignal_subscription_id')
            ->where('onesignal_subscription_id', '!=', '');
    }

    private static function counted(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }
}
