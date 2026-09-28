<?php

namespace App\Services;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One OneSignal app per organisation (Manara Studio D9, W2 S14).
 *
 * Every live app shares ONE OneSignal app and is told apart by a `masjid_id` tag.
 * A Studio-generated app gets its own, created here, and its id is built into the
 * app (W2 S15). Sends then route through it (OnesignalService::resolveConfig).
 *
 * ensureApp() is idempotent and guarded, in this order:
 *   1. an organisation on config('services.onesignal.never_provision') (the live
 *      apps on the shared app) is refused before anything else;
 *   2. without the Organization API key or organisation id nothing is sent;
 *   3. iOS needs the APNs key and the organisation's ios_bundle_id; Android needs
 *      the FCM service account;
 *   4. an app with a key, already configured for every requested platform, is
 *      left alone;
 *   5. an organisation with ANY subscribed device is refused before anything is
 *      created, minted or reconfigured: those devices may have registered under
 *      the shared app, nothing records which, and sends would move to an app with
 *      none of them. Resolving such an organisation is an operator decision;
 *   6. then: add a missing platform to the existing app, mint a missing key, or
 *      create the app and mint its key.
 *
 * OneSignal's current API (documentation.onesignal.com, read 2026-09-27):
 * `Authorization: Key <Organization API key>`; the app is created without a REST
 * key, and one is minted with POST /apps/{id}/auth/tokens, whose
 * `formatted_token` is returned once and never again. The APNs p8 and the FCM
 * JSON are sent base64-encoded.
 *
 * The key is stored encrypted (the model cast), never logged and never returned.
 * Failures are logged at warning (production's level) with the organisation,
 * the outcome and the HTTP status only.
 */
class OneSignalProvisioningService
{
    public const PLATFORMS = ['ios', 'android'];

    /**
     * @param  list<string>  $platforms  any of 'ios', 'android'
     * @param  bool  $pretend  evaluate every step up to the first request, send nothing
     * @param  string|null  $iosBundleId  the iOS bundle id to use when the organisation has none on
     *                                    file. It is stored only once the guards have passed and a
     *                                    request is about to be sent, so a refused or pretended call
     *                                    writes nothing; one already on file is never replaced.
     */
    public function ensureApp(Masjid $org, array $platforms, bool $pretend = false, ?string $iosBundleId = null): OneSignalResult
    {
        $requested = array_values(array_intersect(self::PLATFORMS, $platforms));
        if ($requested === [] || count($requested) !== count(array_unique($platforms))) {
            throw new \InvalidArgumentException('platforms must be a non-empty subset of ios, android');
        }

        // 1. The live organisations stay on the shared app.
        if (in_array((int) $org->id, array_map('intval', (array) config('services.onesignal.never_provision', [])), true)) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::REFUSED_LIVE_ORG,
                message: "Organisation {$org->id}'s live apps use the shared OneSignal app; it never gets one of its own here."));
        }

        // 2. No credentials, no request.
        $orgKey = (string) config('services.onesignal.user_auth_key');
        $orgId = (string) config('services.onesignal.org_id');
        if ($orgKey === '' || $orgId === '') {
            return $this->finish($org, new OneSignalResult(OneSignalResult::NOT_CONFIGURED,
                message: 'ONESIGNAL_USER_AUTH_KEY (the Organization API key) and ONESIGNAL_ORG_ID must both be set.'));
        }

        // 3. Each platform's push credentials.
        $publishing = $org->appPublishing()->first();
        $bundleId = filled($publishing?->ios_bundle_id) ? $publishing->ios_bundle_id : (filled($iosBundleId) ? $iosBundleId : null);
        if (in_array('ios', $requested, true)) {
            if (! $this->apnsConfigured()) {
                return $this->finish($org, new OneSignalResult(OneSignalResult::MISSING_APNS,
                    message: 'iOS needs ONESIGNAL_APNS_P8, ONESIGNAL_APNS_KEY_ID and ONESIGNAL_APNS_TEAM_ID.'));
            }
            if ($bundleId === null) {
                return $this->finish($org, new OneSignalResult(OneSignalResult::MISSING_APNS,
                    message: "Organisation {$org->id} has no iOS bundle id, which OneSignal's APNs configuration needs."));
            }
        }
        if (in_array('android', $requested, true) && blank(config('services.onesignal.fcm_v1_service_account_json'))) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::MISSING_FCM,
                message: 'Android needs ONESIGNAL_FCM_V1_SERVICE_ACCOUNT_JSON.'));
        }

        $appId = $publishing?->onesignal_app_id;
        $hasKey = filled($publishing?->getRawOriginal('onesignal_rest_api_key'));
        $configured = array_values(array_intersect(self::PLATFORMS, (array) ($publishing?->onesignal_platforms ?? [])));
        $missing = array_values(array_diff($requested, $configured));

        // 4. Nothing to do.
        if (filled($appId) && $hasKey && $missing === []) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::EXISTS, $appId, true, $configured));
        }

        // 5. The live-audience guard, before anything is created, minted or reconfigured.
        $subscribed = MobileAppUser::query()->withoutGlobalScopes()
            ->where('masjid_id', $org->id)
            ->whereNotNull('onesignal_subscription_id')
            ->count();
        if ($subscribed > 0) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::HAS_AUDIENCE, $appId, $hasKey, $configured,
                message: "Organisation {$org->id} has {$subscribed} subscribed device(s), which may be on the shared app."));
        }

        // 6. What would be done, when pretending.
        if ($pretend) {
            $outcome = blank($appId) ? OneSignalResult::CREATED
                : ($missing !== [] ? OneSignalResult::PLATFORM_ADDED : OneSignalResult::KEY_MINTED);

            return $this->finish($org, new OneSignalResult($outcome, $appId, $hasKey, $configured,
                message: 'Pretend: no request was sent.', pretended: true));
        }

        if ($bundleId !== null && blank($publishing?->ios_bundle_id)) {
            MasjidAppPublishing::updateOrCreate(['masjid_id' => $org->id], ['ios_bundle_id' => $bundleId]);
        }

        return blank($appId)
            ? $this->create($org, $requested, $bundleId)
            : $this->complete($org, $appId, $hasKey, $configured, $missing, $bundleId);
    }

    /** No app yet: create it, then mint its key. */
    private function create(Masjid $org, array $requested, ?string $bundleId): OneSignalResult
    {
        $body = ['name' => $this->appName($org), 'organization_id' => (string) config('services.onesignal.org_id')]
            + $this->platformFields($requested, $bundleId);

        $response = $this->send('post', $this->appsUrl(), $body);
        if (! $response instanceof Response || ! $response->successful() || blank($response->json('id'))) {
            return $this->failed($org, $response, 'creating the app');
        }
        $appId = (string) $response->json('id');

        // Stored before the key is minted, so a failed mint leaves an app a retry can finish.
        MasjidAppPublishing::updateOrCreate(['masjid_id' => $org->id], [
            'onesignal_app_id' => $appId,
            'onesignal_platforms' => $requested,
            'onesignal_provisioned_at' => now(),
        ]);

        $key = $this->mintKey($org, $appId);
        if ($key instanceof OneSignalResult) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::CREATED, $appId, false, $requested,
                $key->httpStatus, 'The app was created but its REST key could not be minted; sends stay where they were until a retry mints it.'));
        }
        MasjidAppPublishing::where('masjid_id', $org->id)->firstOrFail()
            ->forceFill(['onesignal_rest_api_key' => $key])->save();

        return $this->finish($org, new OneSignalResult(OneSignalResult::CREATED, $appId, true, $requested));
    }

    /** An app exists: mint its missing key and/or add its missing platforms. */
    private function complete(Masjid $org, string $appId, bool $hasKey, array $configured, array $missing,
        ?string $bundleId): OneSignalResult
    {
        if (! $hasKey) {
            $key = $this->mintKey($org, $appId);
            if ($key instanceof OneSignalResult) {
                return $key;
            }
            MasjidAppPublishing::where('masjid_id', $org->id)->firstOrFail()
                ->forceFill(['onesignal_rest_api_key' => $key])->save();
        }

        if ($missing === []) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::KEY_MINTED, $appId, true, $configured));
        }

        $response = $this->send('put', $this->appsUrl()."/{$appId}", $this->platformFields($missing, $bundleId));
        if (! $response instanceof Response || ! $response->successful()) {
            return $this->failed($org, $response, 'adding '.implode(' and ', $missing).' to the app', $appId, true, $configured);
        }
        $now = array_values(array_intersect(self::PLATFORMS, array_merge($configured, $missing)));
        MasjidAppPublishing::where('masjid_id', $org->id)->firstOrFail()
            ->forceFill(['onesignal_platforms' => $now])->save();

        return $this->finish($org, new OneSignalResult(OneSignalResult::PLATFORM_ADDED, $appId, true, $now,
            message: $hasKey ? '' : 'Its REST key was minted too.'));
    }

    /** @return string|OneSignalResult the minted key, or the failure */
    private function mintKey(Masjid $org, string $appId): string|OneSignalResult
    {
        $name = 'manara-'.app()->environment().'-masjid-'.$org->id;
        $response = $this->send('post', $this->appsUrl()."/{$appId}/auth/tokens", ['name' => $name]);
        $token = $response instanceof Response ? $response->json('formatted_token') : null;
        if (! $response instanceof Response || ! $response->successful() || ! is_string($token) || $token === '') {
            return $this->failed($org, $response, 'minting the REST key', $appId);
        }

        return $token;
    }

    /**
     * The APNs and FCM fields for $platforms, base64-encoded as OneSignal requires.
     *
     * @return array<string, string>
     */
    private function platformFields(array $platforms, ?string $bundleId): array
    {
        $fields = [];
        if (in_array('ios', $platforms, true)) {
            $env = (string) config('services.onesignal.apns_env', 'production');
            $fields += [
                'apns_key_id' => (string) config('services.onesignal.apns_key_id'),
                'apns_team_id' => (string) config('services.onesignal.apns_team_id'),
                'apns_bundle_id' => (string) $bundleId,
                'apns_p8' => base64_encode((string) config('services.onesignal.apns_p8')),
                'apns_env' => in_array($env, ['development', 'sandbox'], true) ? 'development' : 'production',
            ];
        }
        if (in_array('android', $platforms, true)) {
            $fields['fcm_v1_service_account_json'] = base64_encode((string) config('services.onesignal.fcm_v1_service_account_json'));
        }

        return $fields;
    }

    private function send(string $method, string $url, array $body): Response|ConnectionException
    {
        try {
            return Http::withHeaders(['Authorization' => 'Key '.config('services.onesignal.user_auth_key')])
                ->acceptJson()->asJson()->timeout(30)->{$method}($url, $body);
        } catch (ConnectionException $e) {
            return $e;
        }
    }

    /** A 429, a 5xx or no answer is transient; any other refusal is OneSignal rejecting the request. */
    private function failed(Masjid $org, Response|ConnectionException $response, string $doing,
        ?string $appId = null, bool $hasKey = false, array $platforms = []): OneSignalResult
    {
        $status = $response instanceof Response ? $response->status() : null;
        $transient = $status === null || $status === 429 || $status >= 500;
        $outcome = $transient ? OneSignalResult::TRANSIENT : OneSignalResult::REJECTED;

        return $this->finish($org, new OneSignalResult($outcome, $appId, $hasKey, $platforms, $status,
            "OneSignal did not complete {$doing} (".($status === null ? 'no response' : "HTTP {$status}").').'));
    }

    private function finish(Masjid $org, OneSignalResult $result): OneSignalResult
    {
        if (! $result->succeeded()) {
            // Organisation, outcome and status only: never a key, never a body.
            Log::warning('onesignal: ensure-app did not provision', [
                'masjid_id' => $org->id,
                'outcome' => $result->outcome,
                'status' => $result->httpStatus,
            ]);
        }

        return $result;
    }

    private function apnsConfigured(): bool
    {
        return filled(config('services.onesignal.apns_p8'))
            && filled(config('services.onesignal.apns_key_id'))
            && filled(config('services.onesignal.apns_team_id'));
    }

    private function appName(Masjid $org): string
    {
        return mb_substr("Manara · {$org->name} · #{$org->id}", 0, 128);
    }

    private function appsUrl(): string
    {
        return rtrim((string) config('services.onesignal.apps_api_url', 'https://api.onesignal.com/apps'), '/');
    }
}
