<?php

namespace App\Jobs;

use App\Models\Masjid;
use App\Models\PushSend;
use App\Services\OnesignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Read once after three minutes, and once fifteen minutes later only for unfinished groups. Ids only. */
class ReadPushOutcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $sendId, public int $check = 1) {}

    public function handle(OnesignalService $onesignal): void
    {
        $send = PushSend::withoutMasjidScope()->find($this->sendId);
        if (!$send || !in_array($this->check, [1, 2], true)) {
            return;
        }
        $processed = false;
        foreach ($send->groups()->withoutGlobalScopes()->where('masjid_id', $send->masjid_id)->get() as $group) {
            DB::transaction(function () use ($group, $send, $onesignal, &$processed) {
                $locked = $group->newQueryWithoutScopes()->lockForUpdate()->findOrFail($group->id);
                if (!$locked->provider_message_id || $locked->status !== 'checking' || $locked->checks >= $this->check) {
                    return;
                }
                $processed = true;
                $key = $onesignal->credentialsForOutcome(Masjid::find($send->masjid_id), $locked->provider_app_id, $locked->legacy);
                $details = $key ? $onesignal->getNotificationDetails($locked->provider_message_id, $locked->provider_app_id, $key) : [];
                $valid = $this->countValue($details['successful'] ?? null) !== null
                    && $this->countValue($details['errored'] ?? null) !== null;
                $processing = $valid && array_key_exists('remaining', $details) && $details['remaining'] === null
                    && $details['successful'] === 0 && $details['errored'] === 0
                    && ($this->countValue($details['failed'] ?? null) ?? 0) === 0;
                $done = $valid && !$processing && ((is_numeric($details['completed_at'] ?? null) && $details['completed_at'] > 0)
                    || (array_key_exists('remaining', $details) && $this->countValue($details['remaining']) === 0));
                $ongoing = $valid && !$done && ($processing
                    || ($this->countValue($details['remaining'] ?? null) !== null && $details['remaining'] > 0));
                $platforms = [];
                if ($valid) {
                    // Whitelist numeric counts and known platform names; never persist the provider response envelope.
                    foreach (is_array($details['platform_delivery_stats'] ?? null) ? $details['platform_delivery_stats'] : [] as $platform => $counts) {
                        if (!in_array($platform, ['ios', 'android', 'amazon', 'windows_phone', 'chrome_web_push', 'safari_web_push', 'firefox_web_push'], true)
                            || !is_array($counts)) {
                            continue;
                        }
                        foreach (['targeted', 'successful', 'errored', 'failed', 'received'] as $field) {
                            if (($count = $this->countValue($counts[$field] ?? null)) !== null) {
                                $platforms[$platform][$field === 'successful' ? 'delivered' : $field] = $count;
                            }
                        }
                        if (isset($platforms[$platform]['failed'])) {
                            $platforms[$platform]['errored'] = ($platforms[$platform]['errored'] ?? 0) + $platforms[$platform]['failed'];
                            unset($platforms[$platform]['failed']);
                        }
                    }
                }
                $locked->forceFill([
                    'checks' => $this->check,
                    'status' => $done ? 'complete' : ($ongoing && $this->check === 1 ? 'checking' : 'unknown'),
                    'delivered' => $valid ? (int) $details['successful'] : null,
                    'errored' => $valid ? (int) $details['errored'] + ($this->countValue($details['failed'] ?? null) ?? 0) : null,
                    'platform_counts' => $platforms ?: null,
                ])->save();
                if (!$valid) {
                    Log::warning('Push outcome unknown', ['push_send_id' => $send->id]);
                }
            });
        }
        $send->aggregate();
        if ($processed) {
            PushSend::withoutMasjidScope()->whereKey($send->id)->update(['readback_scheduled_at' => null]);
            try {
                app(\App\Services\PushOutcomeScheduler::class)->schedule($send->id);
            } catch (\Throwable $e) {
                // The provider check succeeded. Keep the next check recoverable during a queue outage.
                Log::warning('Push outcome enqueue unavailable', ['push_send_id' => $send->id]);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $send = PushSend::withoutMasjidScope()->find($this->sendId);
        if ($send) {
            $send->groups()->withoutGlobalScopes()->where('masjid_id', $send->masjid_id)->where('status', 'checking')
                ->update(['status' => 'unknown']);
            $send->aggregate();
        }
        Log::warning('Push outcome job could not complete', ['push_send_id' => $this->sendId]);
    }

    private function countValue(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
