<?php

namespace App\Services;

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
    public function send(?Masjid $masjid, array $payload, #[\SensitiveParameter] array $legacyConfig, string $kind, ?int $notificationId = null, ?int $timeout = null, ?string $requestKey = null): array
    {
        [$legacyApp, $legacyKey] = $legacyConfig;
        $url = config('onesignal.api_url');
        $ids = $payload['include_subscription_ids'] ?? null;
        if (!$masjid) {
            // Unscoped legacy helpers have no queued sender or tenant ledger.
            return $this->post($url, $legacyKey, $payload, $timeout);
        }
        if (DB::transactionLevel() > 0 && !app()->runningUnitTests()) {
            // A savepoint cannot commit an attempt independently of its caller's rollback.
            throw new \RuntimeException('Push sends require an independent committed attempt.');
        }
        if (!Schema::hasColumn('mobile_app_users', 'push_app_id') || !Schema::hasTable('push_apps')
            || !Schema::hasTable('push_sends') || !Schema::hasTable('push_send_groups')) {
            // No ledger means no durable attempt claim, even for a legacy phone. Refuse before any POST.
            return ['id' => null, 'not_sent' => true, 'reason' => 'push_registry_unavailable'];
        }
        $send = $notificationId !== null
            ? PushSend::firstOrCreate(['notification_id' => $notificationId], ['masjid_id' => $masjid->id, 'kind' => $kind])
            : ($requestKey !== null
                ? PushSend::firstOrCreate(['request_key' => $requestKey], ['masjid_id' => $masjid->id, 'kind' => $kind])
                : PushSend::create(['masjid_id' => $masjid->id, 'kind' => $kind]));
        DB::transaction(function () use ($send, $masjid, $ids, &$payload, $legacyApp) {
            $locked = PushSend::whereKey($send->id)->lockForUpdate()->firstOrFail();
            if ($locked->groups()->exists()) {
                return;
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
            foreach ($buckets as $routingKey => $bucket) {
                $legacy = $bucket['app'] === null;
                $appId = $legacy ? $legacyApp : $bucket['app'];
                $registry = PushApp::where('provider_app_id', $appId)->first();
                $locked->groups()->create([
                    'masjid_id' => $masjid->id, 'routing_key' => $routingKey, 'provider_app_id' => $appId,
                    'app_label' => $registry?->label ?? ($legacy ? 'Legacy organisation account' : 'Unknown app'),
                    'legacy' => $legacy, 'targeted' => $bucket['targeted'] ?? count($bucket['ids']),
                    'subscription_ids' => $bucket['ids'], 'status' => 'pending',
                ]);
            }
        });
        $firstResponse = null;
        foreach ($send->groups()->orderBy('id')->get() as $group) {
            $legacy = $group->legacy;
            $appId = $group->provider_app_id;
            $registry = PushApp::where('provider_app_id', $appId)->first();
            $key = $registry && !$registry->active ? null : ($legacy && $legacyApp === $appId ? $legacyKey : $registry?->rest_api_key);
            // This transaction ends before the HTTP request. A failed ledger write cannot undo the claim.
            $claimed = DB::transaction(function () use ($group, $key) {
                $locked = $group->newQuery()->lockForUpdate()->findOrFail($group->id);
                if ($locked->provider_message_id || in_array($locked->status, ['unknown', 'rejected', 'unknown_app', 'complete'], true)) {
                    return false;
                }
                if ($locked->status === 'sending') {
                    $locked->forceFill(['status' => 'unknown'])->save();
                    return false;
                }
                // Old groups without a snapshot cannot safely be reconstructed from today's registrations.
                if ($locked->status !== 'pending') {
                    $locked->forceFill(['status' => 'unknown'])->save();
                    return false;
                }
                if (blank($key)) {
                    $locked->forceFill(['status' => 'unknown_app'])->save();
                    return false;
                }
                if (!$locked->forceFill(['status' => 'sending', 'attempted_at' => now()])->save()) {
                    throw new \RuntimeException('Push attempt claim could not be stored.');
                }
                return true;
            });
            if (!$claimed) {
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
            if ($group->subscription_ids !== null) {
                unset($body['filters'], $body['included_segments']);
                $body['include_subscription_ids'] = $group->subscription_ids;
            }
            $response = $this->post($groupUrl, $key, $body, $timeout);
            $messageId = $response['id'] ?? null;
            if (is_string($messageId) && $messageId !== '' && strlen($messageId) <= 128) {
                $group->forceFill(['provider_message_id' => $messageId, 'status' => 'checking'])->save();
                $firstResponse ??= $response;
            } else {
                $group->forceFill(['status' => ($response['rejected'] ?? false) ? 'rejected' : 'unknown'])->save();
            }
        }
        $send->aggregate();
        app(PushOutcomeScheduler::class)->schedule($send->id);
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
            return $response->successful() && is_array($json) ? ['id' => $json['id'] ?? null] : ['id' => null, 'rejected' => $response->status() >= 400 && $response->status() < 500];
        } catch (\Throwable $e) {
            Log::warning('Push provider send could not be completed');
            return ['id' => null];
        }
    }
}
