<?php

namespace App\Services;

use App\Models\SplashAnnouncement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors a SplashAnnouncement to OneSignal as an In-App Message (IAM).
 *
 * Why IAM and not a push notification:
 *   - Push notifications fire to the OS tray and are great for "now" events
 *     (Adhan, contact request reply). They get dismissed by the OS.
 *   - IAMs are modal banners the SDK displays when the user *opens* the app.
 *     They support scheduling, an image, a CTA button, and once-per-session
 *     display rules — exactly what a splash announcement needs.
 *
 * Per-masjid targeting:
 *   The mobile app already tags devices with `masjid_id` via the existing
 *   OneSignal SDK integration (see OnesignalService::notifyAllOfMasjid).
 *   IAMs target the same tag with a "tag equals" trigger so only devices
 *   subscribed to that masjid see the message.
 *
 * Fail-soft:
 *   The admin flow shouldn't break if OneSignal is down or misconfigured.
 *   Errors are logged via App\Support\Errors-style Log::error and the local
 *   row still saves — admins can re-sync later via a "Resync" button or by
 *   editing+saving the splash. The Nuxt site uses our own endpoint so it
 *   isn't affected by OneSignal outages.
 */
class OnesignalInAppMessageService
{
    protected ?string $api_url;
    protected ?string $app_id;
    protected ?string $auth_key;

    public function __construct()
    {
        // OneSignal IAM endpoints (/apps/{id}/in_app_messages) require the
        // account-scoped User Auth Key (also called "Organization REST API
        // Key" on newer accounts), NOT the app-scoped REST API Key.
        //
        // Calling IAM endpoints with the REST API key returns:
        //   "Please include a case-sensitive header of Authorization: Basic
        //    <YOUR-USER-AUTH-KEY-HERE> with a valid User Auth key."
        //
        // We fall back to the REST API key if User Auth Key isn't set, just
        // so isConfigured() returns true in the legacy path — but it'll fail
        // at the request, log the OneSignal error, and the local splash row
        // still saves (fail-soft).
        $this->app_id = config('onesignal.app_id');
        $this->auth_key = config('onesignal.user_auth_key')
            ?: config('onesignal.app_rest_api_key');

        $notificationsUrl = config('onesignal.api_url');
        // Strip the trailing /notifications (or anything after the host) and
        // build /apps/{app_id}/in_app_messages — the canonical IAM endpoint.
        $this->api_url = $this->app_id
            ? rtrim(preg_replace('#/[^/]+/?$#', '', (string) $notificationsUrl), '/') . "/apps/{$this->app_id}/in_app_messages"
            : null;
    }

    /** True if the env config is complete enough to call OneSignal at all. */
    protected function isConfigured(): bool
    {
        return !empty($this->api_url) && !empty($this->app_id) && !empty($this->auth_key);
    }

    /**
     * Where a splash's in-app message lives, and how to authenticate there.
     *
     * An organisation with its own OneSignal app (W2 S14) has its devices in THAT
     * app, so its in-app messages are managed there, with the Organization API key
     * and the `Key` scheme OneSignal's current API documents. Every other
     * organisation is on the shared app, exactly as before (same URL, same
     * `Basic` header). The shared configuration still gates everything
     * (isConfigured), as it gates every send in OnesignalService.
     *
     * An in-app message created on the shared app before the organisation got its
     * own is not moved; Studio creates the app before the organisation has devices.
     *
     * @return array{0:string,1:string}|null [messages URL, Authorization header], null when the dedicated app has no Organization key to use
     */
    protected function targetFor(SplashAnnouncement $splash): ?array
    {
        $publishing = $splash->masjid?->appPublishing()->first();
        if ($publishing !== null && $publishing->hasOwnOnesignalApp()) {
            $orgKey = config('onesignal.user_auth_key');
            if (empty($orgKey)) {
                return null;
            }
            $base = rtrim(preg_replace('#/[^/]+/?$#', '', (string) config('onesignal.api_url')), '/');

            return ["{$base}/apps/{$publishing->onesignal_app_id}/in_app_messages", 'Key ' . $orgKey];
        }

        return [$this->api_url, 'Basic ' . $this->auth_key];
    }

    /**
     * Build the IAM payload from a splash row.
     *
     * OneSignal's IAM schema is large; we use only the subset we need:
     *  - name        — admin-side label, shows up in OneSignal dashboard
     *  - schedule    — start/end window
     *  - triggers    — fires on app open IF the device has the masjid tag
     *  - contents    — title + body + image + CTA button
     */
    protected function buildPayload(SplashAnnouncement $splash): array
    {
        $imageUrl = optional($splash->image)->getFullUrl();

        $payload = [
            'name' => "Splash #{$splash->id} — masjid {$splash->masjid_id}",
            'enabled' => (bool) $splash->is_active,

            // Show on every app open within the window. The mobile SDK's
            // built-in "once per session" caps the actual visible frequency.
            'triggers' => [[[
                'kind' => 'session_time',
                'property' => 'session_time',
                'operator' => 'greater',
                'value' => 0,
            ]]],

            // Audience filter: only devices whose `masjid_id` tag matches.
            'filters' => [[
                'field' => 'tag',
                'key' => 'masjid_id',
                'relation' => '=',
                'value' => (string) $splash->masjid_id,
            ]],

            // Scheduling.
            'start_time' => $splash->starts_at?->toIso8601String(),
            'end_time' => $splash->ends_at?->toIso8601String(),

            // Content. Single language ("en") is enough — the Nuxt side
            // doesn't localize either. Add more keys here if i18n lands.
            'contents' => [
                'en' => [
                    'headline' => $splash->title,
                    'body' => strip_tags((string) $splash->body),
                    'image_url' => $imageUrl,
                ],
            ],
        ];

        if ($splash->cta_label && $splash->cta_url) {
            $payload['contents']['en']['actions'] = [[
                'id' => "splash_cta_{$splash->id}",
                'name' => $splash->cta_label,
                'url' => $splash->cta_url,
                'url_target' => 'browser',
                'close' => true,
            ]];
        }

        return $payload;
    }

    /**
     * Create-or-update sync. Called from the admin controller after every save.
     * Returns the OneSignal IAM id (caller persists it to the row), or null
     * on any failure mode — caller treats null as "no sync, keep going".
     */
    public function sync(SplashAnnouncement $splash): ?string
    {
        if (!$this->isConfigured()) {
            Log::warning('OneSignal IAM sync skipped — credentials not configured', [
                'splash_id' => $splash->id,
            ]);
            return $splash->onesignal_iam_id;
        }

        $target = $this->targetFor($splash);
        if ($target === null) {
            Log::warning('OneSignal IAM sync skipped — the organisation has its own app but no Organization API key is configured', [
                'splash_id' => $splash->id,
            ]);
            return $splash->onesignal_iam_id;
        }
        [$url, $authorization] = $target;

        try {
            $payload = $this->buildPayload($splash);

            $request = Http::withHeaders([
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
            ]);

            if ($splash->onesignal_iam_id) {
                $response = $request->put("{$url}/{$splash->onesignal_iam_id}", $payload);
            } else {
                $response = $request->post($url, $payload);
            }

            if (!$response->successful()) {
                Log::error('OneSignal IAM sync failed', [
                    'splash_id' => $splash->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return $splash->onesignal_iam_id;
            }

            return $response->json('id') ?? $splash->onesignal_iam_id;
        } catch (\Throwable $e) {
            Log::error('OneSignal IAM sync threw', [
                'splash_id' => $splash->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return $splash->onesignal_iam_id;
        }
    }

    /**
     * Remove the mirrored IAM. Called on hard-delete. We never error out
     * here — failing to clean up OneSignal shouldn't block deleting the row.
     */
    public function remove(SplashAnnouncement $splash): void
    {
        if (!$this->isConfigured() || !$splash->onesignal_iam_id) {
            return;
        }
        $target = $this->targetFor($splash);
        if ($target === null) {
            return;
        }
        [$url, $authorization] = $target;

        try {
            Http::withHeaders([
                'Authorization' => $authorization,
            ])->delete("{$url}/{$splash->onesignal_iam_id}");
        } catch (\Throwable $e) {
            Log::error('OneSignal IAM delete threw', [
                'splash_id' => $splash->id,
                'iam_id' => $splash->onesignal_iam_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
