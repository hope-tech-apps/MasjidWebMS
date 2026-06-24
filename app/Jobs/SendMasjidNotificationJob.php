<?php

namespace App\Jobs;

use App\Models\Masjid;
use App\Models\Notification;
use App\Services\OnesignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches a notification through OneSignal's REST API asynchronously.
 *
 * The admin's "send notification" request was previously waiting on the OneSignal
 * HTTP call before returning, which made the dashboard hang for as long as OneSignal
 * took to respond. This job moves the dispatch off the request path:
 *
 *   - Admin POST hits NotificationsController::save → creates the Notification record →
 *     dispatches this job → returns 202 immediately.
 *   - Worker picks up the job → calls OneSignal → on success updates the Notification
 *     with the OneSignal message ID; on failure retries with exponential backoff and
 *     deletes the notification record if all retries are exhausted.
 *
 * REQUIRES a running queue worker: `php artisan queue:work --queue=default` (production
 * deployments should run this via Supervisor or a systemd service).
 */
class SendMasjidNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 3 times before giving up. */
    public int $tries = 3;

    /** Allow each attempt up to 30 seconds — covers slow OneSignal responses. */
    public int $timeout = 30;

    public function __construct(
        public Notification $notification,
        public Masjid $masjid,
        /** @var array<int, string> OneSignal subscription (player) IDs. */
        public array $subscriptionIds
    ) {
    }

    /**
     * Exponential backoff: wait 5s, 15s, 30s between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(OnesignalService $onesignal): void
    {
        // Skip silently if there are no targeted devices — nothing to send.
        if (empty($this->subscriptionIds)) {
            Log::info('SendMasjidNotificationJob: no devices to notify', [
                'masjid_id' => $this->masjid->id,
                'notification_id' => $this->notification->id,
            ]);
            return;
        }

        $response = $onesignal->notifyAllOfMasjid($this->masjid, $this->notification, $this->subscriptionIds);

        if (isset($response['id']) && $response['id']) {
            $this->notification->onesignal_message_id = $response['id'];
            $this->notification->save();
            return;
        }

        // OneSignal returned a non-success payload — throw so the job retries.
        throw new \RuntimeException(
            'OneSignal dispatch returned no message ID. Response: ' . json_encode($response)
        );
    }

    /**
     * Called after all retries are exhausted. We delete the orphaned notification
     * record so admins aren't misled into thinking it was delivered.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SendMasjidNotificationJob failed permanently', [
            'masjid_id' => $this->masjid->id,
            'notification_id' => $this->notification->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->notification->delete();
    }
}
