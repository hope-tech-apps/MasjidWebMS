<?php

namespace App\Services;

use App\Models\Masjid;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class OnesignalService
{

    protected $api_url;
    protected $app_id;
    protected $app_key;
    protected $default_channel_id;

    public function __construct()
    {
        $this->api_url = config('onesignal.api_url');
        $this->app_id = config('onesignal.app_id');
        $this->app_key = config('onesignal.app_rest_api_key');

        if (empty($this->api_url) || empty($this->app_id) || empty($this->app_key)) {
            throw new \RuntimeException('Missing some Onesignal app configurations.');
        }
    }

    public function notifyAll(Masjid $masjid, Notification $notification)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->app_key,
            'Content-Type' => 'application/json'
        ])->post($this->api_url, [
            'app_id' => $this->app_id,
            'included_segments' => ['Active Subscriptions'],
            'headings' => [
                'en' => $notification->title,
            ],
            'contents' => [
                'en' => $notification->message,
            ],
            'data' => [
                'masjid_id' => $masjid->id,
                'notification_id' => $notification->id,
            ]
        ]);

        return $response->json();
    }

    public function notifyAllOfMasjid(Masjid $masjid, Notification $notification, array $subscription_ids)
    {
        try {

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->app_key,
                'Content-Type' => 'application/json'
            ])->post($this->api_url, [
                'app_id' => $this->app_id,
                // Subscription IDs, NOT external_id aliases — aliases don't
                // resolve in OneSignal's notification API (invalid_aliases).
                'include_subscription_ids' => array_values(array_filter($subscription_ids)),
                'headings' => [
                    'en' => $notification->title,
                ],
                'contents' => [
                    'en' => $notification->message,
                ],
                'target_channel' => 'push',
                'data' => [
                    'masjid_id' => $masjid->id,
                    'notification_id' => $notification->id,
                ]
            ]);

            return $response->json();
            
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Sends a SILENT (content-available) data push so devices wake in the
     * background and re-pull prayer/iqama times without the user opening the
     * app. No headings/contents/sound — it is purely a background trigger; the
     * app handles `data.type == "prayer_sync"` by re-fetching settings and
     * re-arming its local notification schedule.
     *
     * Fail-soft: returns null (and never throws) so callers — e.g. the admin
     * saving iqama times — are never blocked or broken by a OneSignal hiccup.
     *
     * @param string[] $subscription_ids OneSignal subscription (player) IDs.
     */
    public function sendDataSync(array $subscription_ids, array $data = [])
    {
        $subscription_ids = array_values(array_filter($subscription_ids));

        if (empty($subscription_ids)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->app_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($this->api_url, [
                'app_id' => $this->app_id,
                // Subscription IDs, NOT external_id aliases — aliases don't
                // resolve in OneSignal's notification API (invalid_aliases).
                'include_subscription_ids' => $subscription_ids,
                'target_channel' => 'push',
                // No alert/sound => silent. content_available wakes the app
                // in the background on iOS (aps.content-available = 1).
                'content_available' => true,
                'data' => array_merge(['type' => 'prayer_sync'], $data),
            ]);

            return $response->json();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'OneSignal sendDataSync failed: ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Sends a VISIBLE prayer-time push (adhan or iqama) with a custom iOS sound.
     * Used by the server-side backstop (`prayers:send-due`) to reach devices
     * that have gone dark — their local notifications have lapsed — so they
     * still get a reminder at prayer time.
     *
     * Fail-soft: logs and returns null on error (never throws), so one bad send
     * can't break the per-minute scheduler loop for other prayers/masjids.
     *
     * NOTE: iOS plays `ios_sound` only if the named file is bundled in the app
     * and ≤30s (same cap as a background local-notification sound).
     *
     * @param string[] $subscription_ids OneSignal subscription (player) IDs.
     * @param string|null $iosCategory iOS notification category id (e.g.
     *        "PRAYER_ADHAN") so long-pressing the push shows its actions
     *        (the "Play Full Adhan" button). The app must register the category.
     */
    public function sendPrayerAlert(array $subscription_ids, string $title, string $body, ?string $iosSound = null, array $data = [], ?string $iosCategory = null)
    {
        $subscription_ids = array_values(array_filter($subscription_ids));

        if (empty($subscription_ids)) {
            return null;
        }

        try {
            $payload = [
                'app_id' => $this->app_id,
                // Subscription IDs, NOT external_id aliases (which don't resolve).
                'include_subscription_ids' => $subscription_ids,
                'target_channel' => 'push',
                'headings' => ['en' => $title],
                'contents' => ['en' => $body],
                'data' => array_merge(['type' => 'prayer_alert'], $data),
            ];

            if (!empty($iosSound)) {
                $payload['ios_sound'] = $iosSound;
            }

            if (!empty($iosCategory)) {
                $payload['ios_category'] = $iosCategory;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->app_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($this->api_url, $payload);

            return $response->json();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'OneSignal sendPrayerAlert failed: ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Get details of a specific notification by its message ID.
     *
     * @param string $messageId The ID of the notification.
     * @return array
     */
    public function getNotificationDetails($messageId)
    {
        // Construct the URL for the View Message API
        $url = "{$this->api_url}/{$messageId}?app_id={$this->app_id}";

        // Make the GET request to the OneSignal API
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->app_key,
            'Content-Type' => 'application/json',
        ])->get($url);

        // Return the response as an array
        return $response->json();
    }
}
