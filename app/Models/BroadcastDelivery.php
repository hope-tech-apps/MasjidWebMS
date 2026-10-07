<?php

namespace App\Models;

use App\Enums\BroadcastChannel;
use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BroadcastDelivery — what happened on ONE channel of ONE broadcast (T-008).
 *
 * The reason an admin can be told "push delivered to 412 devices, email failed"
 * instead of being left to guess. Each row is written and committed
 * independently of its siblings; see the no-rollback note on
 * App\Services\Broadcast\BroadcastDispatcher and in the table's migration.
 *
 * `skipped` deserves its own word. A masjid whose congregation has installed the
 * app zero times has nothing to push to; an audience in which nobody recorded an
 * email address has nothing to mail. Neither is an error and neither should show
 * red — but both must be visible, because "sent to 0 people" is a fact the admin
 * needs before they assume the message landed.
 */
class BroadcastDelivery extends Model
{
    use HasFactory, BelongsToMasjid;

    /** Queued/created, not yet attempted. */
    public const STATUS_PENDING = 'pending';

    /** Durably recorded before entering the driver. */
    public const STATUS_SENDING = 'sending';

    /** Driver began, but its outcome was lost; never retry. */
    public const STATUS_INTERRUPTED = 'interrupted';

    /** The claimed send ended before this driver started; terminal. */
    public const STATUS_NOT_SENT = 'not_sent';

    /** The scheduled broadcast was cancelled before any channel started. */
    public const STATUS_CANCELLED = 'cancelled';

    /** The channel accepted the message. */
    public const STATUS_SENT = 'sent';

    /** The channel raised an error; `error` carries the sanitised reason. */
    public const STATUS_FAILED = 'failed';

    /** Nothing to deliver to — not a failure. `note` says why. */
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'broadcast_id',
        'masjid_id',
        'channel',
        'status',
        'target_count',
        'reference_id',
        'reference',
        'note',
        'error',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'target_count' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /** No recorded outcome from an earlier attempt, including partially written results. */
    public function isUntouchedPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->target_count === 0
            && $this->reference_id === null
            && $this->reference === null
            && $this->note === null
            && $this->error === null
            && $this->delivered_at === null;
    }

    public function channelType(): ?BroadcastChannel
    {
        return BroadcastChannel::tryFrom((string) $this->channel);
    }
}
