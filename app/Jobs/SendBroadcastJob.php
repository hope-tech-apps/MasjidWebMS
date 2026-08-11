<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\Broadcast\BroadcastDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans a SCHEDULED broadcast out when its time arrives (T-008).
 *
 * This is the whole of the scheduling infrastructure: a delayed queue job.
 * Laravel already guarantees delayed dispatch, so there is no scheduler table,
 * no cron sweep and no second opinion about when a message goes out.
 *
 * ## It takes an ID, not a model
 *
 * `SerializesModels` would re-resolve a Broadcast THROUGH the BelongsToMasjid
 * global scope, and .claude/rules/tenant-scoping.md guarantees every job starts
 * with the tenant UNBOUND — so the re-resolve happens with no filter, which
 * works, but is a coincidence rather than a design. Passing the id and letting
 * BroadcastDispatcher bind the tenant explicitly (which it must do anyway,
 * before any driver resolves an audience) makes the tenant handling one
 * decision in one place.
 *
 * ## It does not retry the whole broadcast
 *
 * `$tries = 1`. Per-channel retry lives in the channels that own it — push
 * already retries with backoff inside SendMasjidNotificationJob — and a blanket
 * re-run of the fan-out is the one thing that could double-send. The dispatcher
 * is nonetheless written to be safe if it ever is re-run: it only touches
 * deliveries still `pending` or `failed`, never one already `sent`.
 */
class SendBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** See the class docblock: re-running the fan-out is not a retry strategy. */
    public int $tries = 1;

    /** Generous: the email channel may address a few thousand contacts. */
    public int $timeout = 300;

    public function __construct(public int $broadcastId)
    {
    }

    public function handle(BroadcastDispatcher $dispatcher): void
    {
        // Unbound tenant here by design, so the row is fetched without the
        // global scope; the dispatcher binds the tenant from the row itself.
        $broadcast = Broadcast::withoutMasjidScope()->find($this->broadcastId);

        if (! $broadcast) {
            // Deleted between scheduling and firing — nothing to send, and
            // nothing broken.
            Log::info('SendBroadcastJob: broadcast no longer exists', ['broadcast_id' => $this->broadcastId]);

            return;
        }

        $dispatcher->dispatch($broadcast);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendBroadcastJob failed', [
            'broadcast_id' => $this->broadcastId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
