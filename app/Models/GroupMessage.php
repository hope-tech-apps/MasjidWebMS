<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupMessage — one message inside a group thread (PLAN T-005c).
 *
 * Body text only; the feed owns media and thread attachments are deferred
 * entirely. WHO may write one is "whoever may READ the thread, holding the
 * roster permission" — decided by App\Support\GroupAudience plus the
 * `permission:manage contacts` route gate, never here.
 *
 * The author is a `User`, not a `Contact`, for the same reason as GroupPost: a
 * Contact cannot authenticate anywhere in this application, so attributing a
 * message to one would record a claim the server never verified.
 *
 * No SoftDeletes: deletion follows the THREAD. Hiding a conversation is the
 * thread's soft delete; destroying it is the retention purge; there is no
 * per-message eraser to quietly rewrite what was said to a parent.
 *
 * Tenant-scoped by a denormalised masjid_id (BelongsToMasjid), so message
 * queries scope without joining through threads.
 */
class GroupMessage extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * Writing a message bumps the thread's updated_at, so "recently active
     * first" in the thread list is one ORDER BY on a real column instead of a
     * MAX() join per page.
     */
    protected $touches = ['thread'];

    protected $fillable = [
        'masjid_id',
        'group_thread_id',
        'author_user_id',
        'body',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(GroupThread::class, 'group_thread_id');
    }

    /** The admin account that wrote it; null once that account is deleted. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
