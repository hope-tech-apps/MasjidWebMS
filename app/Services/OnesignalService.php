<?php

namespace App\Services;

use App\Models\Masjid;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OnesignalService
{

    protected $api_url;
    protected $app_id;
    protected $app_key;
    protected $default_channel_id;

    /**
     * Constructs on ANY configuration, including none.
     *
     * It used to throw when the shared api_url/app_id/REST key were blank. That
     * is a bad shape for a dependency that is type-hinted into `handle()`:
     * `prayers:send-due` is scheduled every minute, so a deployment without
     * OneSignal credentials — every non-production environment — raised an
     * unhandled exception sixty times an hour and the scheduler never completed
     * cleanly. The failure also arrived at the CONSTRUCTOR, i.e. before any
     * caller could decide whether it even wanted to send anything.
     *
     * Fail-closed is preserved and moved one layer in: with blank credentials
     * every send method logs one warning and returns a "not sent" result
     * WITHOUT touching the network (see notConfiguredResult()). Nothing can
     * reach a real device by accident; it simply stops being an exception.
     */
    public function __construct()
    {
        $this->api_url = config('onesignal.api_url');
        $this->app_id = config('onesignal.app_id');
        $this->app_key = config('onesignal.app_rest_api_key');
    }

    /**
     * Are the SHARED app credentials present?
     *
     * Deliberately the shared app and not the per-masjid one, because these
     * three values are exactly the condition the constructor used to throw on —
     * so gating every send on them keeps "blank shared config sends nothing"
     * true, which is what a staging box restored from a production dump needs.
     * A masjid row that still carries its own provisioned app id and REST key
     * must NOT become a way to push to real phones from a deployment that was
     * given no OneSignal configuration of its own.
     */
    public function isConfigured(): bool
    {
        return !empty($this->api_url) && !empty($this->app_id) && !empty($this->app_key);
    }

    /**
     * The shaped result every send method returns when credentials are absent.
     *
     * Shape rules, both load-bearing:
     *   - `id` is present and null, because that is the key callers read to
     *     decide whether OneSignal accepted the send (SendMasjidNotificationJob).
     *     A missing key and a null key must not read differently.
     *   - `not_sent` + `reason` let a caller distinguish "we chose not to send"
     *     from "OneSignal rejected it", which the old null return could not.
     *
     * Logged once per call at warning level, with a COUNT and never the
     * recipients themselves: subscription ids identify a device, and titles and
     * bodies can carry a person's name.
     *
     * $recipients is null for the segment/tag broadcast, whose audience size
     * only OneSignal knows — saying "0 recipients" there would be a lie.
     *
     * @return array{id:null, not_sent:true, reason:string, recipients:int|null}
     */
    private function notConfiguredResult(string $method, ?int $recipients): array
    {
        $dropped = $recipients === null ? 'a broadcast' : "{$recipients} recipients";

        Log::warning("onesignal: not configured, dropped {$dropped}", [
            'method' => $method,
        ]);

        return [
            'id' => null,
            'not_sent' => true,
            'reason' => 'onesignal_not_configured',
            'recipients' => $recipients,
        ];
    }

    /**
     * Resolve which OneSignal (app_id, REST key) to send THROUGH for a masjid.
     *
     * Tenant-scoping rule:
     *   - If the masjid has its OWN provisioned OneSignal app (both app id and
     *     REST key on file — masjid_app_publishing), use THAT. This is hard,
     *     app-level isolation: that REST key can only reach subscribers under
     *     that app. It is also a correctness requirement, because OneSignal
     *     subscription IDs are scoped to a single app.
     *   - Otherwise fall back to the SHARED/global app (current behavior),
     *     preserved exactly so nothing breaks for the live fleet.
     *
     * The masjid is always a server-derived model (the bound tenant / route
     * masjid), never client input — a masjid can never route through another's
     * app.
     *
     * @return array{0:string,1:string,2:bool} [appId, restKey, isDedicated]
     */
    protected function resolveConfig(?Masjid $masjid): array
    {
        if ($masjid) {
            $config = $masjid->relationLoaded('appPublishing')
                ? $masjid->appPublishing
                : $masjid->appPublishing()->first();

            if ($config && $config->hasOwnOnesignalApp()) {
                // onesignal_rest_api_key is transparently decrypted by the cast.
                return [$config->onesignal_app_id, $config->onesignal_rest_api_key, true];
            }
        }

        return [$this->app_id, $this->app_key, false];
    }

    /**
     * The `masjid_id` tag audience filter. On the SHARED app this is what scopes
     * a broadcast to ONLY that masjid's tagged subscribers (the mobile app tags
     * every subscription with masjid_id). The masjid id is server-derived from
     * the passed model — never client-supplied.
     *
     * NOTE: OneSignal treats "specific devices" (include_subscription_ids) and
     * "filters" as mutually exclusive targeting methods, so this filter is used
     * for the segment/broadcast path only. The subscription-id send paths are
     * already per-masjid (their ids are pulled from the masjid's own devices
     * server-side), so they don't — and can't — also carry this filter.
     *
     * @return array<int, array<string, string>>
     */
    protected function masjidTagFilter(Masjid $masjid): array
    {
        return [[
            'field' => 'tag',
            'key' => 'masjid_id',
            'relation' => '=',
            'value' => (string) $masjid->id,
        ]];
    }

    /**
     * Broadcast to a masjid's whole audience.
     *
     * Tenant-scoped:
     *   - Dedicated per-masjid app  -> the entire app IS the masjid, so segment
     *     targeting ("Active Subscriptions") is safe.
     *   - Shared/global app         -> segment targeting would hit EVERY masjid,
     *     so we constrain to the `masjid_id` tag filter instead — the broadcast
     *     reaches ONLY this masjid's tagged subscribers.
     *
     * The masjid id in the filter is server-derived from the $masjid model.
     */
    public function notifyAll(Masjid $masjid, Notification $notification)
    {
        if (!$this->isConfigured()) {
            return $this->notConfiguredResult('notifyAll', null);
        }

        [$appId, $appKey, $isDedicated] = $this->resolveConfig($masjid);

        $payload = [
            'app_id' => $appId,
            'headings' => [
                'en' => $notification->title,
            ],
            'contents' => [
                'en' => $notification->message,
            ],
            'data' => [
                'masjid_id' => $masjid->id,
                'notification_id' => $notification->id,
            ],
        ];

        if ($isDedicated) {
            $payload['included_segments'] = ['Active Subscriptions'];
        } else {
            // Shared app: audience-scope to this masjid's tagged subscribers.
            $payload['filters'] = $this->masjidTagFilter($masjid);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $appKey,
            'Content-Type' => 'application/json'
        ])->post($this->api_url, $payload);

        return $response->json();
    }

    /**
     * Send to a masjid's specific devices (by OneSignal subscription id).
     *
     * Tenant-scoped two ways: (1) the subscription ids are always pulled from
     * the masjid's OWN devices server-side by the caller, so only its audience
     * is targeted; (2) the credentials are resolved per-masjid — a masjid with
     * its own provisioned app sends through THAT app (also required, since
     * subscription ids are app-scoped), otherwise the shared app.
     */
    public function notifyAllOfMasjid(Masjid $masjid, Notification $notification, array $subscription_ids, ?string $imageUrl = null)
    {
        if (!$this->isConfigured()) {
            return $this->notConfiguredResult(
                'notifyAllOfMasjid',
                count(array_filter($subscription_ids)),
            );
        }

        try {
            [$appId, $appKey] = $this->resolveConfig($masjid);

            $payload = [
                'app_id' => $appId,
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
            ];

            // Rich push image when the notification carries one: big_picture on
            // Android, ios_attachments on iOS.
            if (!empty($imageUrl)) {
                $payload['big_picture'] = $imageUrl;
                $payload['ios_attachments'] = ['id1' => $imageUrl];
            }

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $appKey,
                'Content-Type' => 'application/json'
            ])->post($this->api_url, $payload);

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
     * @param Masjid|null $masjid When given (server-derived), route through the
     *        masjid's OWN OneSignal app if it has one; otherwise the shared app.
     *        Required for delivery once a masjid is on its own app, since
     *        subscription ids are app-scoped. Defaults null -> current behavior.
     */
    public function sendDataSync(array $subscription_ids, array $data = [], ?Masjid $masjid = null)
    {
        $subscription_ids = array_values(array_filter($subscription_ids));

        if (empty($subscription_ids)) {
            return null;
        }

        // AFTER the empty check on purpose: with no devices there is nothing to
        // drop and no call to suppress, so the pre-existing null return stands
        // and an unconfigured deployment does not log a warning per idle sweep.
        if (!$this->isConfigured()) {
            return $this->notConfiguredResult('sendDataSync', count($subscription_ids));
        }

        [$appId, $appKey] = $this->resolveConfig($masjid);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $appKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($this->api_url, [
                'app_id' => $appId,
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
     * @param Masjid|null $masjid When given (server-derived), route through the
     *        masjid's OWN OneSignal app if it has one; otherwise the shared app.
     *        Required for delivery once a masjid is on its own app, since
     *        subscription ids are app-scoped. Defaults null -> current behavior.
     */
    public function sendPrayerAlert(array $subscription_ids, string $title, string $body, ?string $iosSound = null, array $data = [], ?string $iosCategory = null, ?Masjid $masjid = null)
    {
        $subscription_ids = array_values(array_filter($subscription_ids));

        if (empty($subscription_ids)) {
            return null;
        }

        // See sendDataSync: guard placed after the empty check so the idle case
        // keeps returning null and logs nothing.
        if (!$this->isConfigured()) {
            return $this->notConfiguredResult('sendPrayerAlert', count($subscription_ids));
        }

        [$appId, $appKey] = $this->resolveConfig($masjid);

        try {
            $payload = [
                'app_id' => $appId,
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
                'Authorization' => 'Basic ' . $appKey,
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
        // Not a send, so it does not use the "not sent" shape — but it must
        // still not build a request against a blank api_url, which throws.
        // An empty array is the same "nothing to report" a lookup miss gives.
        if (!$this->isConfigured()) {
            Log::warning('onesignal: not configured, notification lookup skipped');

            return [];
        }

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
