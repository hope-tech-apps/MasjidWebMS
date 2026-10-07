<?php

namespace App\Console\Commands;

use App\Models\Broadcast;
use App\Services\Broadcast\BroadcastDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Recovers dead claims only; it never schedules, requeues or sends a message. */
class SettleInterruptedBroadcasts extends Command
{
    protected $signature = 'broadcasts:settle-interrupted';

    protected $description = 'Settle stale broadcast sends without replaying any channel';

    public function handle(BroadcastDispatcher $dispatcher): int
    {
        $cutoff = Carbon::now()->subSeconds(BroadcastDispatcher::STALE_AFTER_SECONDS);
        $settled = 0;
        $failures = 0;
        Broadcast::withoutMasjidScope()->where('status', Broadcast::STATUS_SENDING)
            ->where(function ($query) use ($cutoff) {
                $query->where('sending_started_at', '<', $cutoff)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('sending_started_at')->where('updated_at', '<', $cutoff));
            })->chunkById(100, function ($broadcasts) use ($dispatcher, $cutoff, &$settled, &$failures): void {
                foreach ($broadcasts as $broadcast) {
                    try {
                        $settled += (int) $dispatcher->settleInterrupted($broadcast->id, staleBefore: $cutoff);
                    } catch (Throwable $e) {
                        $failures++;
                        Log::error('Broadcast recovery failed', ['broadcast_id' => $broadcast->id, 'exception' => $e::class]);
                    }
                }
            });
        Log::channel('monitors')->info('broadcasts:settle-interrupted', compact('settled', 'failures'));
        $this->line("Broadcast recovery: {$settled} settled, {$failures} failures.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
