<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GroupPost — one entry in a group's PRIVATE activity feed (PLAN T-005b).
 *
 * The "class story": what happened in today's lesson, optionally with photos.
 * It is never public and never tenant-wide. WHO may read it is decided per
 * request by App\Support\GroupAudience — the group's leaders and members, plus
 * the guardians of its participants whose recorded consent covers the
 * disclosure. See .claude/rules/groups.md.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope and the
 * server-derived creating hook. masjid_id stays fillable so system/super code
 * (seeders, the purge command) can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 *
 * The author is a `User`, not a `Contact`, and that is deliberate: a Contact
 * cannot authenticate anywhere in this application, so attributing a post to one
 * would record a claim the server never verified. See the create_group_posts
 * migration docblock.
 */
class GroupPost extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'author_user_id',
        'title',
        'body',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'retained_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // RETENTION IS A DEFAULT, NOT A HOPE. These posts are about children, so
        // "keep forever unless somebody remembers to say otherwise" is the wrong
        // default. Stamped here rather than in the controller so it holds for
        // every caller — imports and seeders included. A caller that sets
        // retained_until itself is respected; config 0 means "keep until
        // somebody decides", which leaves the column null and the row outside
        // the purge sweep entirely.
        static::creating(function (self $post): void {
            if ($post->retained_until !== null) {
                return;
            }

            $days = (int) config('groups.feed.retention_days', 0);

            if ($days > 0) {
                $post->retained_until = now()->addDays($days)->toDateString();
            }
        });

        // A DB-level cascade fires no model events, so if the parent row's
        // deletion is what removes the attachment rows, the bytes are orphaned
        // on disk forever (.claude/rules/private-uploads.md). Delete them
        // through the model, BEFORE the cascade can beat us to it, so each
        // attachment's own deleting hook runs and reaches the disk.
        //
        // Only on a FORCE delete: a soft delete is the mis-click guard, and
        // destroying a term of classroom photographs is exactly what it exists
        // to prevent. Bytes go when the retention purge says they go.
        static::deleting(function (self $post): void {
            if (! $post->isForceDeleting()) {
                return;
            }

            $post->attachments()->get()->each->delete();
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The admin account that wrote it; null once that account is deleted. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GroupPostAttachment::class);
    }

    /**
     * Posts whose retention window has closed, soft-deleted ones included — the
     * sweep `groups:purge-feed` runs. A null retained_until is never due: it
     * means nobody has set a window, not "purge me now".
     */
    public function scopeDueForPurge(Builder $query, $asOf = null): Builder
    {
        return $query->withTrashed()
            ->whereNotNull('retained_until')
            ->whereDate('retained_until', '<=', $asOf ?? now()->toDateString());
    }

    /**
     * Destroy this post for good: the row, its attachment rows, and the bytes.
     *
     * The deletion goes through forceDelete() rather than a query-builder delete
     * precisely so the `deleting` hook above runs — the whole point of the
     * retention path is that it reaches the disk.
     */
    public function purge(): void
    {
        $this->forceDelete();
    }
}
