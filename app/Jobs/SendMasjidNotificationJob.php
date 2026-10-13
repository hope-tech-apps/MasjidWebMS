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
 *     retains the notification and its account outcomes if retries are exhausted.
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

    public int $notificationId = 0;
    public int $masjidId = 0;

    // Decode pre-deploy queued model identifiers. New jobs set ids only; these remain null.
    public ?Notification $notification = null;
    public ?Masjid $masjid = null;

    public function __construct(Notification $notification, Masjid $masjid, public array $subscriptionIds)
    {
        $this->notificationId = (int) $notification->id;
        $this->masjidId = (int) $masjid->id;
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
        $this->notificationId = $this->notificationId ?: (int) $this->notification?->id;
        $this->masjidId = $this->masjidId ?: (int) $this->masjid?->id;
        app(\App\Support\TenantContext::class)->runWithout(fn () => $this->send($onesignal));
    }

    private function send(OnesignalService $onesignal): void
    {
        $notification = Notification::where('masjid_id', $this->masjidId)->find($this->notificationId);
        $masjid = Masjid::find($this->masjidId);
        if (!$notification || !$masjid) {
            return;
        }

        // Skip silently if there are no targeted devices — nothing to send.
        if (empty($this->subscriptionIds)) {
            Log::info('SendMasjidNotificationJob: no devices to notify', [
                'masjid_id' => $masjid->id,
                'notification_id' => $notification->id,
            ]);
            return;
        }

        // A deployment with no OneSignal credentials cannot deliver this, and
        // no number of retries will change that — so do not spend three
        // attempts on it and then let failed() DELETE the admin's notification
        // as if it were an OneSignal rejection. The row is what the admin
        // created and can see; keep it, and say plainly why nothing was pushed.
        if (!$onesignal->isConfigured()) {
            Log::warning('SendMasjidNotificationJob: OneSignal not configured — nothing pushed', [
                'masjid_id' => $masjid->id,
                'notification_id' => $notification->id,
                'recipients' => count($this->subscriptionIds),
            ]);
            return;
        }

        // Include the notification's image (if any) so the push renders it —
        // big_picture on Android, ios_attachments on iOS.
        $imageUrl = $notification->getFirstMediaUrl('notifications') ?: null;

        $response = $onesignal->notifyAllOfMasjid($masjid, $notification, $this->subscriptionIds, $imageUrl);

        if (isset($response['id']) && $response['id']) {
            $notification->onesignal_message_id = $response['id'];
            $notification->save();
            return;
        }

        if ($response['recorded'] ?? false) {
            return;
        }

        // OneSignal returned a non-success payload — throw so the job retries.
        throw new \RuntimeException(
            'OneSignal dispatch returned no message ID.'
        );
    }

    /**
     * Called after all retries are exhausted. Retain the failure ledger for office inspection.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SendMasjidNotificationJob failed permanently', [
            'masjid_id' => $this->masjidId,
            'notification_id' => $this->notificationId,
            'error' => 'Push provider did not accept the send.',
        ]);

        $notificationId = $this->notificationId ?: (int) $this->notification?->id;
        $masjidId = $this->masjidId ?: (int) $this->masjid?->id;
        if (\Illuminate\Support\Facades\Schema::hasTable('push_sends')) {
            $send = \App\Models\PushSend::withoutMasjidScope()->where('masjid_id', $masjidId)
                ->where('notification_id', $notificationId)->first();
            if ($send) {
                // A worker can stop before storing a provider id. Its last attempt must not leave Checking forever.
                $send->groups()->withoutGlobalScopes()->where('masjid_id', $masjidId)
                    ->whereNull('provider_message_id')->whereIn('status', ['pending', 'sending', 'checking'])->update(['status' => 'unknown']);
                $send->aggregate();
                app(\App\Services\PushOutcomeScheduler::class)->schedule($send->id);
            }
        }

        // Retain the in-app inbox and the failure ledger; failure must remain visible to the office.
    }
}
