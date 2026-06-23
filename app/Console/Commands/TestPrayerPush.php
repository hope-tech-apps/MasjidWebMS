<?php

namespace App\Console\Commands;

use App\Services\OnesignalService;
use Illuminate\Console\Command;

/**
 * Fires ONE immediate prayer-style push to a single device, to verify the
 * end-to-end push + custom-sound path on a real phone without waiting for a
 * real prayer time or touching any other device. Safe testing aid for the
 * Phase-3 backstop.
 *
 *   php artisan prayers:test-push <device_id>            # adhan sound
 *   php artisan prayers:test-push <device_id> --iqama    # iqama sound
 */
class TestPrayerPush extends Command
{
    protected $signature = 'prayers:test-push {device_id} {--iqama : Use the iqama sound/text instead of adhan}';

    protected $description = 'Send one immediate prayer push to a single device (delivery + sound test).';

    public function handle(OnesignalService $onesignal): int
    {
        $deviceId = $this->argument('device_id');
        $iqama = (bool) $this->option('iqama');

        $subscriptionId = \App\Models\MobileAppUser::where('device_id', $deviceId)
            ->value('onesignal_subscription_id');

        if (empty($subscriptionId)) {
            $this->error("No onesignal_subscription_id stored for device {$deviceId}. Open the app once so it reports a heartbeat, then retry.");
            return self::FAILURE;
        }

        $title = $iqama ? 'Iqama time for Test' : "It's time for Test";
        $body = 'Phase-3 backstop test — if you see this with the adhan sound, the server-side path works.';
        $sound = $iqama ? 'iqamah.wav' : 'adhan.wav';

        $response = $onesignal->sendPrayerAlert([$subscriptionId], $title, $body, $sound, ['type' => 'prayer_test']);

        $this->info("Sent to device {$deviceId} (subscription {$subscriptionId})");
        $this->line('OneSignal response: ' . json_encode($response));

        return self::SUCCESS;
    }
}
