<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupMessageSchedule — a NEW conversation that has been written and is waiting for
 * its time (T-002.4). See the create_group_message_schedules migration for why it is
 * a table of its own and never a group_messages row with a future time.
 *
 * Nothing about a schedule reaches a reader: it is not a thread, so no thread list,
 * unread bookmark, receipt, reaction, digest or family endpoint can see it. It becomes
 * one only when `groups:publish-due` (or nobody else) writes it through
 * GroupThreadWriter at its `send_at`.
 *
 * STATUS is a PHP constant set, never a DB enum:
 *   scheduled  waiting; the author and the office may edit or cancel it.
 *   sending    claimed by a sweep (an UPDATE guarded by status = scheduled); the write
 *              and the move to `sent` are ONE transaction, so a claim that goes stale
 *              wrote nothing and may be handed back.
 *   sent       became a thread (`sent_thread_id`). Final.
 *   failed     refused or failed at send time; `failure_reason` says why. The author
 *              or the office may edit it (which puts it back to `scheduled`) or cancel.
 *   cancelled  withdrawn. Final; kept until retention, not deleted, so the office can
 *              still say what was cancelled and by whom it was written.
 *
 * Tenant-scoped (BelongsToMasjid): masjid_id stays fillable so the console sweep, which
 * runs UNBOUND, can read across schools with withoutMasjidScope() and write with an
 * explicit id; a bound tenant always overrides it.
 */
class GroupMessageSchedule extends Model
{
    use BelongsToMasjid;

    public const KIND_THREAD = 'thread';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'author_user_id',
        'kind',
        'scope',
        'about_membership_id',
        'subject',
        'body',
        'send_at',
        'status',
        'failure_reason',
        'sent_thread_id',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'retained_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // BOUNDED BY DEFAULT, like the thread it becomes: words about a child are not
        // kept "until somebody remembers". Counted from the SEND time, so a message
        // scheduled a month ahead does not lose a month of its life.
        static::creating(function (self $schedule): void {
            if ($schedule->retained_until !== null) {
                return;
            }

            $days = (int) config('groups.messaging.retention_days', 0);

            if ($days > 0 && $schedule->send_at !== null) {
                $schedule->retained_until = $schedule->send_at->copy()->addDays($days)->toDateString();
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function aboutMembership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'about_membership_id');
    }

    public function sentThread(): BelongsTo
    {
        return $this->belongsTo(GroupThread::class, 'sent_thread_id');
    }

    /** Still ahead of us: waiting or failed. What the Scheduled list shows. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_FAILED]);
    }

    /** Due now and still waiting: the sweep's question. */
    public function scopeDue(Builder $query, $asOf = null): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('send_at', '<=', $asOf ?? now());
    }

    /** May still be changed by its author or the office. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_FAILED], true);
    }

    public function isAboutOneChild(): bool
    {
        return $this->scope === GroupThread::SCOPE_PARTICIPANT;
    }

    /** Rows whose retention window has closed. A NULL window is never due. */
    public function scopeDueForPurge(Builder $query, $asOf = null): Builder
    {
        return $query->whereNotNull('retained_until')
            ->whereDate('retained_until', '<=', $asOf ?? now()->toDateString());
    }
}
