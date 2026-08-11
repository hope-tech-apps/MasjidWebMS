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
 * GroupThread — one conversation inside a group (PLAN T-005c).
 *
 * The teacher <-> parent channel. The `scope` column IS the disclosure shape:
 *
 *   - SCOPE_GROUP: announcement-discussion, same audience as the private feed;
 *   - SCOPE_PARTICIPANT: a private conversation about ONE member, named
 *     explicitly by `about_membership_id` (the member's own participant
 *     membership row), readable only by the group's leaders and the specific
 *     guardian/member it concerns.
 *
 * WHO may read a thread is decided per request by App\Support\GroupAudience —
 * never here, never in a controller — so the list, one thread, and a message
 * write cannot drift apart. See .claude/rules/groups.md.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope and the
 * server-derived creating hook. masjid_id stays fillable so system/super code
 * (seeders, the purge command) can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 */
class GroupThread extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    /**
     * Thread scopes. PHP constants, NOT a DB enum — the same reasoning as
     * GroupMembership::ROLES: adding a scope must never require
     * `ALTER TABLE … MODIFY` on a live table (.claude/rules/migrations.md).
     * Validated at the request boundary with Rule::in(...).
     */
    public const SCOPE_GROUP = 'group';
    public const SCOPE_PARTICIPANT = 'participant';

    public const SCOPES = [
        self::SCOPE_GROUP,
        self::SCOPE_PARTICIPANT,
    ];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'created_by_user_id',
        'subject',
        'scope',
        'about_membership_id',
        'closed_at',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'retained_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // RETENTION IS A DEFAULT, NOT A HOPE — the same stance as GroupPost.
        // These conversations are about children, so "keep forever unless
        // somebody remembers to say otherwise" is the wrong default. Stamped in
        // the model so it holds for every caller, seeders included. A caller
        // that sets retained_until itself is respected; config 0 leaves the
        // column null and the row outside the purge sweep entirely.
        static::creating(function (self $thread): void {
            if ($thread->retained_until !== null) {
                return;
            }

            $days = (int) config('groups.messaging.retention_days', 0);

            if ($days > 0) {
                $thread->retained_until = now()->addDays($days)->toDateString();
            }
        });
    }

    /**
     * The stored scope, degraded to PARTICIPANT when unrecognized — the same
     * defensive read as Group::kind(), but pointed the SAFE way: an unknown
     * scope must fail CLOSED. Degrading to `group` would widen a private
     * conversation to the whole feed audience; degrading to `participant`
     * narrows it to the group's leaders (the target lookup then finds nobody
     * else), which discloses nothing that was not already theirs to see.
     */
    public function threadScope(): string
    {
        $scope = $this->attributes['scope'] ?? null;

        return in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_PARTICIPANT;
    }

    public function isGroupWide(): bool
    {
        return $this->threadScope() === self::SCOPE_GROUP;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The admin account that opened it; null once that account is deleted. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * On a participant-scoped thread, the participant MEMBERSHIP of the member
     * this conversation is about — an explicit edge, mirroring how a guardian
     * row names its ward. Null on group-wide threads, and null again if the
     * member has since been removed from the roster (the FK nulls on delete),
     * in which case GroupAudience fails closed to leaders-only.
     */
    public function aboutMembership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'about_membership_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GroupMessage::class);
    }

    /** Per-user read bookmarks (last_read_at), for unread markers. */
    public function reads(): HasMany
    {
        return $this->hasMany(GroupThreadRead::class);
    }

    /**
     * Threads whose retention window has closed, soft-deleted ones included —
     * the sweep `groups:purge-feed` runs, identical in shape to
     * GroupPost::scopeDueForPurge. A null retained_until is never due: it means
     * nobody has set a window, not "purge me now".
     */
    public function scopeDueForPurge(Builder $query, $asOf = null): Builder
    {
        return $query->withTrashed()
            ->whereNotNull('retained_until')
            ->whereDate('retained_until', '<=', $asOf ?? now()->toDateString());
    }

    /**
     * Destroy this thread for good, messages and read markers with it.
     *
     * forceDelete() is enough HERE, unlike GroupPost::purge(): the DB cascade
     * onto group_messages and group_thread_reads fires no model events, but no
     * model events are needed — messages carry no bytes on disk (attachments
     * are deliberately out of this slice), so cascading rows orphans nothing.
     * If thread attachments ever arrive, this method is where the model-driven
     * teardown goes, exactly as GroupPost does it.
     */
    public function purge(): void
    {
        $this->forceDelete();
    }
}
