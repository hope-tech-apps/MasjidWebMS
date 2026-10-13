<?php

namespace App\Services;

use App\Jobs\ReadPushOutcomeJob;
use App\Models\PushSend;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class PushOutcomeScheduler
{
    public function schedule(int $sendId): bool
    {
        return DB::transaction(function () use ($sendId) {
            $send = PushSend::withoutMasjidScope()->lockForUpdate()->find($sendId);
            if (!$send || ($send->readback_scheduled_at && $send->readback_scheduled_at->gt(now()->subMinutes(25)))) {
                return false;
            }
            $checks = $send->groups()->withoutGlobalScopes()->where('masjid_id', $send->masjid_id)
                ->whereNotNull('provider_message_id')->where('status', 'checking')->where('checks', '<', 2)->min('checks');
            if ($checks === null) {
                return false;
            }
            $job = (new ReadPushOutcomeJob($send->id, (int) $checks + 1))
                ->onConnection(config('queue.default') === 'sync' ? 'database' : config('queue.default'))
                ->delay(now()->addMinutes((int) $checks === 0 ? 3 : 15))->beforeCommit();
            // With the database queue both writes commit together. Other drivers stamp only after success.
            Bus::dispatch($job);
            $send->forceFill(['readback_scheduled_at' => now()])->save();
            return true;
        });
    }
}
