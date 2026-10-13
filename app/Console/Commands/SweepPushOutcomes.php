<?php

namespace App\Console\Commands;

use App\Models\PushSend;
use App\Services\PushOutcomeScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SweepPushOutcomes extends Command
{
    protected $signature = 'push:sweep-outcomes';
    protected $description = 'Recover missing push delivery checks (at most 100 abandoned attempts and 100 missing checks per run).';

    public function handle(PushOutcomeScheduler $scheduler): int
    {
        if (!Schema::hasTable('push_sends')) {
            return self::SUCCESS;
        }
        // Silent senders are fail-soft: a lost ledger write can outlive the queue job itself.
        $abandoned = PushSend::withoutMasjidScope()->whereHas('groups', fn ($q) => $q->withoutGlobalScopes()
            ->where('status', 'sending')->whereNull('provider_message_id')->where('attempted_at', '<=', now()->subMinutes(25)))
            ->orderBy('id')->limit(100)->pluck('id');
        foreach ($abandoned as $id) {
            try {
                DB::transaction(function () use ($id) {
                    $send = PushSend::withoutMasjidScope()->lockForUpdate()->find($id);
                    if ($send) {
                        $send->groups()->withoutGlobalScopes()->where('masjid_id', $send->masjid_id)
                            ->where('status', 'sending')->whereNull('provider_message_id')
                            ->where('attempted_at', '<=', now()->subMinutes(25))->update(['status' => 'unknown']);
                        $send->aggregate();
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('Push attempt outcome unavailable', ['push_send_id' => $id]);
            }
        }
        $sends = PushSend::withoutMasjidScope()->where(fn ($q) => $q->whereNull('readback_scheduled_at')
            ->orWhere('readback_scheduled_at', '<=', now()->subMinutes(25)))
            ->whereHas('groups', fn ($q) => $q->withoutGlobalScopes()->whereNotNull('provider_message_id')
                ->where('status', 'checking')->where('checks', '<', 2))->orderBy('id')->limit(100)->pluck('id');
        foreach ($sends as $id) {
            try {
                $scheduler->schedule($id);
            } catch (\Throwable $e) {
                Log::warning('Push outcome enqueue unavailable', ['push_send_id' => $id]);
            }
        }
        return self::SUCCESS;
    }
}
