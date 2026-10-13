<?php

namespace App\Services;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\PushApp;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Auto-provisions a per-masjid OneSignal app via the OneSignal "Create an app"
 * API, so onboarding a masjid needs NO manual OneSignal dashboard step.
 *
 * Why per-masjid apps:
 *   The fleet historically shares ONE OneSignal app (config/onesignal.php).
 *   Giving each masjid its OWN app is the strongest possible tenant isolation
 *   for push — a masjid's REST key can only ever reach subscribers registered
 *   under ITS app. It is also a correctness requirement once a masjid migrates,
 *   because OneSignal subscription IDs are scoped to a single app.
 *
 * Credentials (all org-level, machine-to-machine, wired like the Stripe keys):
 *   - services.onesignal.user_auth_key   — authorizes creating apps.
 *   - services.onesignal.apns_*          — team APNs .p8/key-id/team-id, to seed
 *                                          iOS push on the new app.
 *   - services.onesignal.fcm_v1_service_account_json — Android push seed.
 * None are hardcoded; see config/services.php + .env.example.
 *
 * On success the returned app id + app-scoped REST key are persisted (the REST
 * key ENCRYPTED) onto the masjid's masjid_app_publishing row. The REST key is
 * never returned to any caller — provisionApp() surfaces only the app id + a
 * has_key boolean.
 *
 * Fail-soft on config: when the org auth key isn't configured, provisionApp()
 * returns a clear error array rather than throwing, so callers (onboarding /
 * the admin endpoint) degrade gracefully.
 */
class OneSignalProvisioningService
{
    /**
     * Provision a brand-new OneSignal app for $masjid and persist its app id +
     * REST key onto the masjid's app-publishing config.
     *
     * @param  Masjid  $masjid    The tenant to provision for (server-derived).
     * @param  string  $bundleId  The iOS bundle id (apns_bundle_id) for the app.
     * @param  array   $overrides Optional per-call overrides:
     *                            - name: app display name (defaults to masjid name)
     *                            - apns_env: 'production' | 'sandbox'
     *                            - fcm_v1_service_account_json: raw JSON override
     * @param  string|null $apnsP8 Explicit Apple push key material; all three Apple arguments are required together.
     * @param  string|null $apnsKeyId Apple push key id. Omitted arguments use existing global settings.
     * @param  string|null $apnsTeamId Apple team owning this app's push key.
     * @return array{ok:bool, app_id?:string, has_onesignal_key?:bool, error?:string}
     */
    public function provisionApp(
        Masjid $masjid, string $bundleId, #[\SensitiveParameter] array $overrides = [],
        #[\SensitiveParameter] ?string $apnsP8 = null, #[\SensitiveParameter] ?string $apnsKeyId = null, #[\SensitiveParameter] ?string $apnsTeamId = null,
    ): array
    {
        $authKey = config('services.onesignal.user_auth_key');

        // Guard gracefully — never crash when the org creds aren't wired.
        if (empty($authKey)) {
            return [
                'ok' => false,
                'error' => 'OneSignal org auth key (ONESIGNAL_USER_AUTH_KEY) is not configured; cannot provision an app.',
            ];
        }

        if (trim($bundleId) === '') {
            return [
                'ok' => false,
                'error' => 'A bundle id (apns_bundle_id) is required to provision an iOS-capable OneSignal app.',
            ];
        }

        if ($apnsP8 !== null || $apnsKeyId !== null || $apnsTeamId !== null) {
            if (blank($apnsP8) || blank($apnsKeyId) || blank($apnsTeamId)) {
                return ['ok' => false, 'error' => 'Explicit Apple push credentials require key material, key id and team id together.'];
            }
            $overrides['apns_p8'] = $apnsP8;
            $overrides['apns_key_id'] = $apnsKeyId;
            $overrides['apns_team_id'] = $apnsTeamId;
        }
        $payload = $this->buildPayload($masjid, $bundleId, $overrides);
        $url = config('services.onesignal.apps_api_url', 'https://api.onesignal.com/apps');

        try {
            $response = Http::withHeaders([
                // The Apps API is authorized with the ORG-scoped user auth key
                // (matching OnesignalInAppMessageService's Basic scheme).
                'Authorization' => 'Basic ' . $authKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($url, $payload);
        } catch (\Throwable $e) {
            Log::error('OneSignal app provisioning threw', [
                'masjid_id' => $masjid->id,
                'exception' => get_class($e),
            ]);

            return ['ok' => false, 'error' => 'OneSignal request failed.'];
        }

        $body = $response->json();
        $appId = is_array($body) ? ($body['id'] ?? null) : null;
        // OneSignal returns the new app's REST API key as `basic_auth_key`.
        $restKey = is_array($body) ? ($body['basic_auth_key'] ?? null) : null;

        if (!$response->successful() || !is_string($appId) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $appId)
            || !is_string($restKey) || trim($restKey) === '') {
            Log::error('OneSignal app provisioning failed', [
                'masjid_id' => $masjid->id,
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'error' => 'OneSignal did not return a new app id + key (HTTP ' . $response->status() . ').',
            ];
        }

        // Persist onto the masjid's config. masjid_id is server-derived from the
        // passed model — never client input. The REST key is encrypted by the
        // model cast; the app id is a public identifier.
        try {
            DB::transaction(function () use ($masjid, $appId, $restKey, $overrides) {
                if (Schema::hasTable('push_apps')) {
                    PushApp::updateOrCreate(['provider_app_id' => $appId], [
                        'rest_api_key' => $restKey, 'label' => $overrides['name'] ?? $masjid->name, 'active' => true,
                    ]);
                }
                // Registry-only provisioning lets the owner prepare a new account before moving legacy installs.
                if ($overrides['attach_to_organisation'] ?? true) {
                    MasjidAppPublishing::updateOrCreate(['masjid_id' => $masjid->id], [
                        'onesignal_app_id' => $appId, 'onesignal_rest_api_key' => $restKey,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Persistence exceptions can include ciphertext bindings. Do not expose either key material or SQL.
            Log::error('OneSignal app credentials could not be stored', ['masjid_id' => $masjid->id]);
            return ['ok' => false, 'error' => 'The push app was created but its configuration could not be stored.'];
        }

        return [
            'ok' => true,
            'app_id' => $appId,
            'has_onesignal_key' => true,
        ];
    }

    /**
     * Build the "Create an app" request body. APNs and FCM blocks are only
     * included when their org creds are configured, so provisioning still works
     * with just one platform set up (or none — an app can be created name-only,
     * with push wired later in the dashboard).
     */
    protected function buildPayload(Masjid $masjid, string $bundleId, #[\SensitiveParameter] array $overrides): array
    {
        $payload = [
            'name' => $overrides['name'] ?? $masjid->name ?? ('Masjid ' . $masjid->id),
        ];

        $p8 = $overrides['apns_p8'] ?? config('services.onesignal.apns_p8');
        $keyId = $overrides['apns_key_id'] ?? config('services.onesignal.apns_key_id');
        $teamId = $overrides['apns_team_id'] ?? config('services.onesignal.apns_team_id');

        if (filled($p8) && filled($keyId) && filled($teamId)) {
            $payload['apns_p8'] = $p8;
            $payload['apns_key_id'] = $keyId;
            $payload['apns_team_id'] = $teamId;
            $payload['apns_bundle_id'] = $bundleId;
            $payload['apns_env'] = $overrides['apns_env']
                ?? config('services.onesignal.apns_env', 'production');
        }

        $fcm = $overrides['fcm_v1_service_account_json']
            ?? config('services.onesignal.fcm_v1_service_account_json');

        if (filled($fcm)) {
            $payload['fcm_v1_service_account_json'] = $fcm;
        }

        return $payload;
    }
}
