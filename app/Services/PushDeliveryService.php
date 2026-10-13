<?php

namespace App\Services;

use App\Jobs\ReadPushOutcomeJob;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\PushApp;
use App\Models\PushSend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Routes an already-resolved audience, leaving the transport's payload and legacy account intact. */
class PushDeliveryService
{
    public function send(?Masjid $masjid, array $payload, #[\SensitiveParameter] array $legacyConfig, string $kind, ?int $notificationId = null, ?int $timeout = null): array
    {
        [$legacyApp, $legacyKey] = $legacyConfig;
        $url = config('onesignal.api_url');
        $ids = $payload['include_subscription_ids'] ?? null;
        // Code precedes migrations during deploy. Preserve the old request in that window.
        if (!$masjid || !Schema::hasColumn('mobile_app_users', 'push_app_id')) {
            return $this->post($url, $legacyKey, $payload, $timeout);
        }
        if (!Schema::hasTable('push_apps') || !Schema::hasTable('push_sends') || !Schema::hasTable('push_send_groups')) {
            $knownPhones = MobileAppUser::whereNotNull('push_app_id');
            $ids === null ? $knownPhones->where('masjid_id', $masjid->id) : $knownPhones->whereIn('onesignal_subscription_id', $ids);
            if ($knownPhones->exists()) {
                // A partial migration must never reroute an app-aware phone through the legacy account.
                return ['id' => null, 'not_sent' => true, 'reason' => 'push_registry_unavailable'];
            }
            return $this->post($url, $legacyKey, $payload, $timeout);
        }
        $devices = MobileAppUser::whereNotNull('onesignal_subscription_id');
        if ($ids !== null) {
            // Audience ids were selected by the caller. A queued phone can switch organisations before send;
            // routing must still follow that phone, without adding any ids to the selected audience.
            $devices->whereIn('onesignal_subscription_id', $ids);
        } else {
            $devices->where('masjid_id', $masjid->id);
        }
        $appsBySubscription = $devices->pluck('push_app_id', 'onesignal_subscription_id');
        // The unused segment helper keeps its exact legacy request when no devices report an app.
        // Once app-aware devices exist it addresses the registered audience explicitly, like every live caller.
        if ($ids === null && $appsBySubscription->contains(fn ($app) => $app !== null)) {
            $ids = $appsBySubscription->keys()->all();
            unset($payload['filters'], $payload['included_segments']);
            $payload['include_subscription_ids'] = $ids;
        }
        $buckets = [];
        if ($ids === null) {
            $buckets['legacy'] = ['app' => null, 'ids' => null, 'targeted' => $appsBySubscription->count()];
        } else {
            foreach ($ids as $id) {
                $app = $appsBySubscription[$id] ?? null;
                $key = $app === null ? 'legacy' : 'app:'.$app;
                $buckets[$key]['app'] = $app;
                $buckets[$key]['ids'][] = $id;
            }
        }
        if (isset($buckets['legacy'], $buckets['app:'.$legacyApp])) {
            $sameAccount = PushApp::where('provider_app_id', $legacyApp)->where('active', true)->first();
            if ($sameAccount && filled($sameAccount->rest_api_key)) {
                // Keep the original audience order when null and app-aware installs share one account.
                $combined = array_merge($buckets['legacy']['ids'], $buckets['app:'.$legacyApp]['ids']);
                $buckets['legacy']['ids'] = array_values(array_filter($ids, fn ($id) => in_array($id, $combined, true)));
                unset($buckets['app:'.$legacyApp]);
            }
        }
        $send = $notificationId === null
            ? PushSend::create(['masjid_id' => $masjid->id, 'kind' => $kind])
            : PushSend::firstOrCreate(['notification_id' => $notificationId], ['masjid_id' => $masjid->id, 'kind' => $kind]);
        $firstResponse = null;
        $sendFailed = false;
        foreach ($buckets as $routingKey => $bucket) {
            $legacy = $bucket['app'] === null;
            $appId = $legacy ? $legacyApp : $bucket['app'];
            $registry = PushApp::where('provider_app_id', $appId)->first();
            $disabled = $registry && !$registry->active;
            $key = $disabled ? null : ($legacy ? $legacyKey : $registry?->rest_api_key);
            $group = $send->groups()->firstOrCreate(['routing_key' => $routingKey], [
                'masjid_id' => $masjid->id, 'provider_app_id' => $appId,
                'app_label' => $registry?->label ?? ($legacy ? 'Legacy organisation account' : 'Unknown app'),
                'legacy' => $legacy, 'targeted' => $bucket['targeted'] ?? count($bucket['ids']),
            ]);
            // Provider-accepted groups are not sent again on a job retry.
            if ($group->provider_message_id || $group->status === 'unknown_app') {
                continue;
            }
            if (blank($key)) {
                $group->forceFill(['status' => $legacy && !$disabled ? 'send_failed' : 'unknown_app'])->save();
                continue;
            }
            $groupUrl = $url;
            if (!$legacy && str_contains($url, '?')) {
                [$path, $queryString] = explode('?', $url, 2);
                parse_str($queryString, $query);
                if (array_key_exists('app_id', $query)) {
                    $query['app_id'] = $appId;
                    $groupUrl = $path.'?'.http_build_query($query);
                }
            }
            $body = $payload;
            $body['app_id'] = $appId;
            if ($bucket['ids'] !== null) {
                $body['include_subscription_ids'] = $bucket['ids'];
            }
            // Claim the group under its lock. A concurrent job cannot resend an accepted group.
            DB::transaction(function () use ($group, $groupUrl, $key, $body, $timeout, &$firstResponse, &$sendFailed) {
                $locked = $group->newQuery()->lockForUpdate()->findOrFail($group->id);
                if ($locked->provider_message_id) {
                    return;
                }
                $response = $this->post($groupUrl, $key, $body, $timeout);
                $messageId = $response['id'] ?? null;
                if (is_string($messageId) && $messageId !== '' && strlen($messageId) <= 128) {
                    $locked->forceFill(['provider_message_id' => $messageId, 'status' => 'checking'])->save();
                    $firstResponse ??= $response;
                } else {
                    $locked->forceFill(['status' => 'send_failed'])->save();
                    $sendFailed = true;
                }
            });
        }
        $send->aggregate();
        if ($send->groups()->whereNotNull('provider_message_id')->exists()) {
            // Atomic scheduling claim makes retrying the send idempotent too.
            $claimed = PushSend::whereKey($send->id)->whereNull('readback_scheduled_at')
                ->update(['readback_scheduled_at' => now()]);
            if ($claimed) {
                ReadPushOutcomeJob::dispatch($send->id)->onConnection(config('queue.default') === 'sync' ? 'database' : config('queue.default'))
                    ->delay(now()->addMinutes(3))->afterCommit();
            }
        }
        if ($sendFailed) {
            throw new \RuntimeException('Push provider did not accept one or more account groups.');
        }
        return $firstResponse ?? ['id' => $send->groups()->whereNotNull('provider_message_id')->value('provider_message_id'),
            'recorded' => true, 'push_send_id' => $send->id];
    }

    /** Never propagate provider bodies or transport exception messages into logs or failed-job payloads. */
    private function post(string $url, #[\SensitiveParameter] string $key, array $body, ?int $timeout): array
    {
        try {
            $request = Http::withHeaders(['Authorization' => 'Basic '.$key, 'Content-Type' => 'application/json']);
            if ($timeout !== null) {
                $request->timeout($timeout);
            }
            $response = $request->post($url, $body);
            $json = $response->json();
            return $response->successful() && is_array($json) ? ['id' => $json['id'] ?? null] : ['id' => null];
        } catch (\Throwable $e) {
            Log::warning('Push provider send could not be completed');
            return ['id' => null];
        }
    }
}
