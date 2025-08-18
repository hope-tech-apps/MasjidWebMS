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
            'Authorization' => 'Key ' . $this->app_key,
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
                'masjid' => $masjid,
                'notification' => $notification
            ]
        ]);

        return $response->json();
    }

    public function notifyAllOfMasjid(Masjid $masjid, Notification $notification, array $external_ids)
    {
        try {

            $response = Http::withHeaders([
                'Authorization' => 'Key ' . $this->app_key,
                'Content-Type' => 'application/json'
            ])->post($this->api_url, [
                'app_id' => $this->app_id,
                // 'included_segments' => ['Active Subscriptions'],
                'include_aliases' => [
                    'external_id' => $external_ids
                ],
                'headings' => [
                    'en' => $notification->title,
                ],
                'contents' => [
                    'en' => $notification->message,
                ],
                'target_channel' => 'push',
                'data' => [
                    'masjid' => $masjid,
                    'notification' => $notification
                ]
            ]);

            return $response->json();
            
        } catch (\Exception $e) {
            throw $e;
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
