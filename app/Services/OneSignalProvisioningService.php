<?php

namespace App\Services;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
 *   2. without the Organization API key (ONESIGNAL_ORG_API_KEY) or organisation
 *      id nothing is sent;
 *   3. only one call per organisation runs at a time (a lock; a call that cannot
 *      get it in time is TRANSIENT). Pretending takes no lock: it writes nothing;
 *   4. iOS needs the APNs key and the organisation's ios_bundle_id; Android needs
 *      the FCM service account;
 *   5. an app with a key, already configured for every requested platform, is
 *      left alone;
 *   6. an organisation with ANY subscribed device is refused when the call would
 *      move its sends (creating the app, or minting the key that makes sends go
 *      through it): those devices may have registered under the shared app,
 *      nothing records which, and sends would move to an app with none of them.
 *      Resolving such an organisation is an operator decision. Adding a platform
 *      to an app that already has its key moves nothing, so it is not refused;
 *   7. then: add a missing platform to the existing app, mint a missing key, or
 *      create the app and mint its key. Before creating, OneSignal's own list of
 *      apps is read: an app an earlier call made but never heard back about is
 *      taken over (ADOPTED) rather than duplicated, and if the list cannot be
 *      read nothing is created.
 *
 * OneSignal's current API (documentation.onesignal.com, read 2026-09-27):
 * `Authorization: Key <Organization API key>`; the app is created without a REST
 * key, and one is minted with POST /apps/{id}/auth/tokens, whose
 * `formatted_token` is returned once and never again. The APNs p8 and the FCM
 * JSON are sent base64-encoded.
 *
 * The key is stored encrypted (the model cast), never logged and never returned.
 * A key that could not be minted is KEY_PENDING, not a success: the app id stays
 * stored so the next call mints instead of creating. Failures are logged at
 * warning (production's level) with the organisation, the outcome and the HTTP
 * status only.
 */
class OneSignalProvisioningService
{
    public const PLATFORMS = ['ios', 'android'];

    /**
     * The longest one call holds an organisation's lock: a list, a create and a
     * mint, at the 30-second request timeout each, are 90 seconds.
     */
    private const LOCK_SECONDS = 120;

    /** How long a call waits for another call on the same organisation before giving up as transient. */
    private const LOCK_WAIT_SECONDS = 15;

    /** OneSignal's limit on an app's name. */
    private const NAME_LIMIT = 128;

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
        if (blank(config('services.onesignal.org_api_key')) || blank(config('services.onesignal.org_id'))) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::NOT_CONFIGURED,
                message: 'ONESIGNAL_ORG_API_KEY (an Organization API key) and ONESIGNAL_ORG_ID must both be set.'));
        }

        if ($pretend) {
            return $this->provision($org, $requested, true, $iosBundleId);
        }

        // 3. Two calls for one organisation would each pass the guards on the same
        // row and both create (or both mint) before either had stored its id.
        try {
            return Cache::lock("onesignal:ensure-app:{$org->id}", self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->provision($org, $requested, false, $iosBundleId));
        } catch (LockTimeoutException) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::TRANSIENT,
                message: "Another provisioning call for organisation {$org->id} is running; try again in a moment."));
        }
    }

    /**
     * Whether another organisation already holds $bundleId (the column is unique).
     * The route and the command both refuse before calling ensureApp.
     */
    public function bundleIdIsTakenByAnother(string $bundleId, Masjid $org): bool
    {
        return MasjidAppPublishing::where('ios_bundle_id', $bundleId)
            ->where('masjid_id', '!=', $org->id)->exists();
    }

    /** Guards 4 to 7, and the work. Inside the lock unless pretending. */
    private function provision(Masjid $org, array $requested, bool $pretend, ?string $iosBundleId): OneSignalResult
    {
        // Read here, inside the lock: what a call that just finished stored is what this one must see.
        $publishing = $org->appPublishing()->first();

        // 4. Each platform's push credentials.
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

        // 5. Nothing to do.
        if (filled($appId) && $hasKey && $missing === []) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::EXISTS, $appId, true, $configured));
        }

        // 6. The live-audience guard, when this call would move the organisation's sends:
        // it creates the app, or mints the key that makes sends go through one. An app
        // that already has its key already receives them, so a platform added to it moves nothing.
        if (blank($appId) || ! $hasKey) {
            $subscribed = MobileAppUser::query()->withoutGlobalScopes()
                ->where('masjid_id', $org->id)
                ->whereNotNull('onesignal_subscription_id')
                ->count();
            if ($subscribed > 0) {
                return $this->finish($org, new OneSignalResult(OneSignalResult::HAS_AUDIENCE, $appId, $hasKey, $configured,
                    message: "Organisation {$org->id} has {$subscribed} subscribed device(s), which may be on the shared app."));
            }
        }

        // 7. What would be done, when pretending. It cannot say whether a create would
        // adopt an app an earlier call made: that is a request.
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

    /** No app yet: take over the one an earlier call made, or create it, then mint its key. */
    private function create(Masjid $org, array $requested, ?string $bundleId): OneSignalResult
    {
        // A create whose answer never arrived (a timeout, a dropped connection) may
        // have made the app, and nothing stored its id. Making another on the retry
        // would orphan it, so look first, and create only when the list was read.
        $named = $this->appsNamedFor($org);
        if ($named instanceof OneSignalResult) {
            return $named;
        }
        if (count($named) > 1) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::AMBIGUOUS_APP,
                message: 'OneSignal holds '.count($named)." apps named for organisation {$org->id} (".implode(', ', $named).'). '
                    .'An operator must delete the extras in OneSignal, or store the right one\'s id on the organisation, before this can continue.'));
        }
        if (count($named) === 1) {
            MasjidAppPublishing::updateOrCreate(['masjid_id' => $org->id], [
                'onesignal_app_id' => $named[0],
                'onesignal_platforms' => [],
            ]);

            return $this->complete($org, $named[0], false, [], $requested, $bundleId, adopted: true);
        }

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

        $key = $this->mintKey($org, $appId, $requested);
        if ($key instanceof OneSignalResult) {
            return $key;
        }
        $this->storeKey($org, $key);

        return $this->finish($org, new OneSignalResult(OneSignalResult::CREATED, $appId, true, $requested));
    }

    /**
     * An app exists: mint its missing key and/or add its missing platforms.
     *
     * @param  bool  $adopted  the app was found by name just now, not stored before
     */
    private function complete(Masjid $org, string $appId, bool $hasKey, array $configured, array $missing,
        ?string $bundleId, bool $adopted = false): OneSignalResult
    {
        if (! $hasKey) {
            $key = $this->mintKey($org, $appId, $configured);
            if ($key instanceof OneSignalResult) {
                return $key;
            }
            $this->storeKey($org, $key);
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

        if ($adopted) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::ADOPTED, $appId, true, $now,
                message: 'An app an earlier attempt made was adopted; its REST key was minted and its platforms set.'));
        }

        return $this->finish($org, new OneSignalResult(OneSignalResult::PLATFORM_ADDED, $appId, true, $now,
            message: $hasKey ? '' : 'Its REST key was minted too.'));
    }

    /**
     * The ids of the apps in the OneSignal organisation that are this organisation's in
     * this environment, or why the list could not be read (nothing is then created).
     *
     * Matched on the name's environment prefix and organisation-id suffix (appName()),
     * not the whole name: the organisation may be renamed between attempts.
     *
     * @return list<string>|OneSignalResult
     */
    private function appsNamedFor(Masjid $org): array|OneSignalResult
    {
        $response = $this->send('get', $this->appsUrl());
        if (! $response instanceof Response || ! $response->successful()) {
            return $this->failed($org, $response, 'listing the existing apps');
        }

        // GET /apps answers with a JSON array of apps.
        $apps = $response->json();
        if (! is_array($apps) || ! array_is_list($apps)) {
            return $this->finish($org, new OneSignalResult(OneSignalResult::REJECTED, httpStatus: $response->status(),
                message: "OneSignal's list of apps was not in the expected shape, so no app was created."));
        }

        [$prefix, $suffix] = [$this->appNamePrefix(), $this->appNameSuffix($org)];
        $ids = [];
        foreach ($apps as $app) {
            $name = is_array($app) ? ($app['name'] ?? null) : null;
            $id = is_array($app) ? ($app['id'] ?? null) : null;
            if (is_string($name) && is_string($id) && $id !== ''
                && str_starts_with($name, $prefix) && str_ends_with($name, $suffix)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $platforms  what the app is configured for, for the result
     * @return string|OneSignalResult the minted key, or KEY_PENDING
     */
    private function mintKey(Masjid $org, string $appId, array $platforms): string|OneSignalResult
    {
        $name = 'manara-'.app()->environment().'-masjid-'.$org->id;
        $response = $this->send('post', $this->appsUrl()."/{$appId}/auth/tokens", ['name' => $name]);
        $token = $response instanceof Response ? $response->json('formatted_token') : null;
        if (! $response instanceof Response || ! $response->successful() || ! is_string($token) || $token === '') {
            $status = $response instanceof Response ? $response->status() : null;
            $refused = $status !== null && $status < 500 && $status !== 429;

            return $this->finish($org, new OneSignalResult(OneSignalResult::KEY_PENDING, $appId, false, $platforms, $status,
                'The OneSignal app exists but its REST key could not be minted ('.($status === null ? 'no response' : "HTTP {$status}").'); '
                .'sends stay on the shared app until it is. Run the call again to mint it.'
                .($refused ? ' OneSignal refused the request, so check the Organization API key first.' : '')));
        }

        return $token;
    }

    /** Store the minted key, and mark the app as Studio's: that is what makes its sends use `Key`. */
    private function storeKey(Masjid $org, string $key): void
    {
        $publishing = MasjidAppPublishing::where('masjid_id', $org->id)->firstOrFail();
        $publishing->forceFill([
            'onesignal_rest_api_key' => $key,
            'onesignal_provisioned_at' => $publishing->onesignal_provisioned_at ?? now(),
        ])->save();
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

    /** Every request here carries the Organization API key, never ONESIGNAL_USER_AUTH_KEY. */
    private function send(string $method, string $url, array $body = []): Response|ConnectionException
    {
        try {
            $request = Http::withHeaders(['Authorization' => 'Key '.config('services.onesignal.org_api_key')])
                ->acceptJson()->asJson()->timeout(30);

            return $method === 'get' ? $request->get($url) : $request->{$method}($url, $body);
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

    /**
     * `Manara · {name} · #{id}` in production, `Manara [{env}] · {name} · #{id}` anywhere
     * else. Staging is a copy of production (same organisation ids) and may share the
     * OneSignal organisation, so an environment's marker keeps it from ever adopting
     * another's app. Only the name is cut to fit the limit: the id suffix is what a
     * retry finds the app by.
     */
    private function appName(Masjid $org): string
    {
        [$prefix, $suffix] = [$this->appNamePrefix(), $this->appNameSuffix($org)];
        $room = max(0, self::NAME_LIMIT - mb_strlen($prefix) - mb_strlen($suffix));

        return $prefix.mb_substr((string) $org->name, 0, $room).$suffix;
    }

    private function appNamePrefix(): string
    {
        return app()->environment('production') ? 'Manara · ' : 'Manara ['.app()->environment().'] · ';
    }

    private function appNameSuffix(Masjid $org): string
    {
        return " · #{$org->id}";
    }

    private function appsUrl(): string
    {
        return rtrim((string) config('services.onesignal.apps_api_url', 'https://api.onesignal.com/apps'), '/');
    }
}
