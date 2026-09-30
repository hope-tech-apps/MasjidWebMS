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
        // Scheduling (T-002.4). See the add_scheduling_to_group_posts migration.
        'published_at',
        'announced_at',
        'publish_failed_at',
        'publish_failure',
    ];

    protected function casts(): array
    {
        return [
            'retained_until' => 'date',
            'published_at' => 'datetime',
            'announced_at' => 'datetime',
            'publish_failed_at' => 'datetime',
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
            // WHEN FAMILIES MAY SEE IT. An ordinary post is out the moment it is
            // written (its own `created_at` when a caller supplies one, so an
            // import keeps its order); a scheduled one carries a future time.
            // Stamped here so no writer can forget it, and `scopePublished()` is
            // the only door a family read uses.
            if ($post->published_at === null) {
                $post->published_at = $post->created_at ?? now();
            }

            // A story that is already out when it is written was announced by
            // whoever wrote it (the controller dispatches the email itself), so
            // the sweep must not announce it a second time. Only a story
            // scheduled for LATER is left unannounced, for the sweep to claim.
            if ($post->announced_at === null
                && $post->publish_failed_at === null
                && $post->published_at->lte(now())) {
                $post->announced_at = $post->published_at;
            }

            if ($post->retained_until !== null) {
                return;
            }

            $days = (int) config('groups.feed.retention_days', 0);

            if ($days > 0) {
                // Counted from the day it goes OUT, not the day it was typed: a
                // story scheduled a month ahead must not lose a month of its life.
                $post->retained_until = $post->published_at->copy()->addDays($days)->toDateString();
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
     * 🤲 👍 💯 ❓ on this post. Nothing here needs a model-level teardown: a
     * reaction holds no bytes and nothing hangs off it, so the DB cascade on
     * `group_post_reactions.group_post_id` is the whole story when retention
     * force-deletes the post.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(GroupPostReaction::class);
    }

    /**
     * THE ONE DOOR EVERY FAMILY READ GOES THROUGH: stories that are out.
     *
     * A story is out when its `published_at` has come, it was not refused at release
     * (`publish_failed_at`), AND it has been announced (`announced_at`). The last
     * condition is what makes the S15 author gate independent of the sweep's timing:
     * `announced_at` is stamped by an ordinary post's own write, and for a scheduled
     * story ONLY by GroupStoryPublisher::announce(), which the sweep calls after the
     * gate said yes. A sweep that is late, killed or not running at all therefore
     * DELAYS a story; it can never let one out that the gate has not passed, and it can
     * never leave a story on screen that the gate then pulls back.
     *
     * A NULL `published_at` is read as out: only a row written by code that predates
     * the column (the deploy seconds, an import with raw SQL) can carry one, and before
     * scheduling existed such a row was visible. Everything else in the system stamps
     * the column, and the migration backfilled `announced_at` for every existing row.
     *
     * Chained on `$group->posts()` at every parent-facing site (the feed, one
     * story, the seen POST, a reaction, an attachment, a playback ticket and the
     * playback stream). A family site that forgets it would serve tomorrow's
     * story today, and the only thing that would notice is a test that asks each
     * site for a future post (ScheduledClassStoryTest).
     */
    public function scopePublished(Builder $query, $asOf = null): Builder
    {
        $now = $asOf ?? now();

        return $query
            ->whereNull($query->getModel()->qualifyColumn('publish_failed_at'))
            ->where(function (Builder $when) use ($query, $now): void {
                $model = $query->getModel();
                $column = $model->qualifyColumn('published_at');
                $announced = $model->qualifyColumn('announced_at');

                $when->whereNull($column)->orWhere(function (Builder $out) use ($column, $announced, $now): void {
                    $out->where($column, '<=', $now)->whereNotNull($announced);
                });
            });
    }

    /** Stories waiting to go out: not refused, and either before their time or not yet announced. */
    public function scopeScheduled(Builder $query, $asOf = null): Builder
    {
        return $query
            ->whereNull($query->getModel()->qualifyColumn('publish_failed_at'))
            ->whereNotNull($query->getModel()->qualifyColumn('published_at'))
            ->where($this->notYetOut($query, $asOf ?? now()));
    }

    /** Stories NOT out: waiting for their time or their announcement, or refused at release. The Scheduled list. */
    public function scopeUnpublished(Builder $query, $asOf = null): Builder
    {
        $now = $asOf ?? now();
        $failed = $query->getModel()->qualifyColumn('publish_failed_at');
        $at = $query->getModel()->qualifyColumn('published_at');

        return $query->where(function (Builder $q) use ($failed, $at, $query, $now): void {
            $q->whereNotNull($failed)->orWhere(function (Builder $waiting) use ($at, $query, $now): void {
                $waiting->whereNotNull($at)->where($this->notYetOut($query, $now));
            });
        });
    }

    /** `published_at` in the future, or the story not announced yet (the negation of "out", for a stamped row). */
    private function notYetOut(Builder $query, $now): \Closure
    {
        $model = $query->getModel();
        $at = $model->qualifyColumn('published_at');
        $announced = $model->qualifyColumn('announced_at');

        return fn (Builder $q) => $q->where($at, '>', $now)->orWhereNull($announced);
    }

    /** Stories the sweep refused to release. */
    public function scopeFailedToPublish(Builder $query): Builder
    {
        return $query->whereNotNull($query->getModel()->qualifyColumn('publish_failed_at'));
    }

    /** The feed's order: when each story went OUT, newest first (id breaks a tie). */
    public function scopeNewestPublishedFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('COALESCE(group_posts.published_at, group_posts.created_at) DESC')
            ->orderByDesc('group_posts.id');
    }

    /** The row-level twin of scopePublished(): keep the two identical. */
    public function isPublished($asOf = null): bool
    {
        return $this->publish_failed_at === null
            && ($this->published_at === null
                || ($this->published_at->lte($asOf ?? now()) && $this->announced_at !== null));
    }

    /** Waiting to go out: the twin of scopeScheduled(). */
    public function isScheduled($asOf = null): bool
    {
        return $this->publish_failed_at === null
            && $this->published_at !== null
            && ($this->published_at->gt($asOf ?? now()) || $this->announced_at === null);
    }

    public function hasFailedToPublish(): bool
    {
        return $this->publish_failed_at !== null;
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
            ->whereDate('retained_until', '<=', $asOf ?? now()->toDateString())
            // Never a story still waiting to go out: its window may close before its
            // day through a legacy row or a bug, and deleting it would be silent. A
            // cancelled (trashed) or refused one is not waiting, so it still goes.
            ->where(function (Builder $q): void {
                $q->whereNotNull('announced_at')
                    ->orWhereNotNull('deleted_at')
                    ->orWhereNotNull('publish_failed_at');
            });
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
