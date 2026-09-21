<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupThreadRead — how far one user has read one thread (PLAN T-005c).
 *
 * The minimal unread primitive: a single last_read_at bookmark per
 * (thread, user), moved forward whenever the user views the thread (and when
 * they write to it — you have read what you just wrote). "Unread" is then
 * simply "the thread's latest message is newer than my bookmark". No counters
 * and no push; the mobile app (T-015) consumes this same row.
 *
 * READ RECEIPTS (owner, 2026-09-21) are derived from this row, not stored
 * beside it: a reader has seen a message when their bookmark `covers()` it.
 * Who may be SHOWN another person's bookmark is App\Support\GroupMessageSignals'
 * decision — staff see everyone's, a parent sees only staff's.
 *
 * NOT an authorization record. Whether a user may read the thread at all is
 * App\Support\GroupAudience's decision; this row only remembers WHEN an
 * entitled reader last did. A bookmark on a thread the user can no longer read
 * grants nothing.
 *
 * Tenant-scoped (BelongsToMasjid, denormalised masjid_id): which thread a user
 * was reading is itself tenant data.
 */
class GroupThreadRead extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_thread_id',
        'user_id',
        'contact_id',
        'last_read_at',
        'last_read_message_id',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'last_read_message_id' => 'integer',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(GroupThread::class, 'group_thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The parent who read, when the reader was a parent (T-015f). */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Has this reader been shown `$message`?
     *
     * The message id they were last SERVED up to is exact; a bookmark written
     * before that column existed answers by time instead, which is what it
     * always meant. See the 2026-09-21 migration.
     */
    public function covers(GroupMessage $message): bool
    {
        if ($this->last_read_message_id !== null) {
            return (int) $message->id <= (int) $this->last_read_message_id;
        }

        return $this->last_read_at !== null
            && $message->created_at !== null
            && $this->last_read_at->greaterThanOrEqualTo($message->created_at);
    }

    /**
     * Move a reader's bookmark: the time to now, and the message high-water mark
     * FORWARD only. Re-opening page one of a long conversation must not un-read
     * the messages further down that this reader has already been shown.
     *
     * Exactly one of $userId / $contactId. masjid_id is stamped by the creating
     * hook from the bound tenant.
     */
    public static function advance(int $threadId, ?int $userId, ?int $contactId, ?int $upToMessageId): void
    {
        $key = ['group_thread_id' => $threadId] + ($userId !== null
            ? ['user_id' => $userId]
            : ['contact_id' => $contactId]);

        $read = static::query()->firstOrNew($key);
        $read->last_read_at = now();

        if ($upToMessageId !== null
            && ($read->last_read_message_id === null || $upToMessageId > (int) $read->last_read_message_id)) {
            $read->last_read_message_id = $upToMessageId;
        }

        $read->save();
    }
}
