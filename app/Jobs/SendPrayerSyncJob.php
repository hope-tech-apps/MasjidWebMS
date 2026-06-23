<?php

namespace App\Jobs;

use App\Services\OnesignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a silent (content-available) "prayer_sync" data push to a masjid's
 * devices, asking them to re-pull the latest prayer/iqama times in the
 * background and re-arm their local notification schedule.
 *
 * Dispatched when an admin changes iqama times (IqamaTimeSettingsController),
 * so the change propagates to users WITHOUT them reopening the app — the local
 * notifications stay primary (precise timing + per-prayer sound), this just
 * keeps them fresh.
 *
 * Off the request path (queued) so the admin's save returns immediately, and
 * fail-soft so a OneSignal outage never affects the save. REQUIRES the running
 * queue worker (systemd `masjid-queue.service`).
 */
class SendPrayerSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    /**
     * @param string[] $externalIds OneSignal external_id aliases (device IDs).
     */
    public function __construct(
        public int $masjidId,
        public array $externalIds,
    ) {}

    public function handle(OnesignalService $onesignal): void
    {
        if (empty($this->externalIds)) {
            return;
        }

        $onesignal->sendDataSync($this->externalIds, ['masjid_id' => $this->masjidId]);
    }

    public function failed(Throwable $e): void
    {
        Log::warning("SendPrayerSyncJob failed for masjid {$this->masjidId}: " . $e->getMessage());
    }
}
