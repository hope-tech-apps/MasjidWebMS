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
 * simply "the thread's latest message is newer than my bookmark". No counters,
 * no per-message receipts, no push — deliberately, per the T-005c scope; the
 * mobile app (T-015) consumes this same row.
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
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
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
}
