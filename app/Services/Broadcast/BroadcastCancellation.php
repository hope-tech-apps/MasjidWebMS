<?php

namespace App\Services\Broadcast;

use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use Illuminate\Support\Facades\DB;

/** Serialises cancellation with the dispatcher's send claim, within the bound tenant. */
class BroadcastCancellation
{
    /** @return array{broadcast: Broadcast, cancelled: bool, message: string} */
    public function cancel(int $broadcastId, int $actorId): array
    {
        return DB::transaction(function () use ($broadcastId, $actorId): array {
            // A locking read sees the latest row, even if this request read an older copy.
            $broadcast = Broadcast::query()->lockForUpdate()->findOrFail($broadcastId);
            // Old dispatchers kept the parent scheduled until rollup. Inspect fresh
            // outcomes under the parent lock before promising that nothing will send.
            $broadcast->load(['deliveries' => fn ($query) => $query->lockForUpdate()]);

            if (! $broadcast->isCancellable()) {
                return [
                    'broadcast' => $broadcast,
                    'cancelled' => false,
                    'message' => match ($broadcast->status) {
                        Broadcast::STATUS_CANCELLED => 'This broadcast is already cancelled. Nothing will be sent.',
                        Broadcast::STATUS_SENDING => 'This broadcast is sending and cannot be cancelled. Refresh to see the delivery status.',
                        Broadcast::STATUS_SENT => 'This broadcast has already been sent. Check the channel outcomes before composing a follow-up.',
                        Broadcast::STATUS_PARTIAL => 'This broadcast has already reached some channels. Check the channel outcomes before composing a follow-up.',
                        Broadcast::STATUS_FAILED => 'This broadcast has failed and cannot be cancelled. Check the channel outcomes before composing a replacement.',
                        Broadcast::STATUS_SCHEDULED => $broadcast->hasDeliveryAttempt()
                            ? 'This broadcast cannot be cancelled because an earlier attempt is recorded. Some channels may already have gone out; check the channel outcomes.'
                            : 'Only a scheduled broadcast can be cancelled. Refresh the list to see its current status.',
                        default => 'Only a scheduled broadcast can be cancelled. Refresh the list to see its current status.',
                    },
                ];
            }

            $broadcast->forceFill([
                'status' => Broadcast::STATUS_CANCELLED,
                'cancelled_by_user_id' => $actorId,
                'cancelled_at' => now(),
            ])->save();
            $broadcast->deliveries()->where('status', BroadcastDelivery::STATUS_PENDING)
                ->update(['status' => BroadcastDelivery::STATUS_CANCELLED]);

            return [
                'broadcast' => $broadcast,
                'cancelled' => true,
                'message' => 'Broadcast cancelled. Nothing will be sent.',
            ];
        });
    }
}
