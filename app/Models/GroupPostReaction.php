<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\Reactions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupPostReaction — one person's 🤲 / 👍 / 💯 / ❓ on one class story post
 * (owner, 2026-09-29).
 *
 * The same four keys as a message reaction, from ONE list (App\Support\Reactions)
 * so the two surfaces cannot drift.
 *
 * WHO MAY REACT is whoever may READ THE FEED — GroupAudience::DISCLOSURE_FEED —
 * plus the realm's own write gate (`permission:manage contacts`,
 * `teacher.leads`, or the family guard). A guardian therefore needs recorded
 * feed consent and to still be in the class; media consent is not asked, because
 * a reaction is to the words. It is decided in the controllers, where publishing
 * is, not here.
 *
 * The reacting person is a staff User OR a guardian Contact, never both and
 * never neither (booted()); the principal comes from the token, never from the
 * payload.
 *
 * A reaction has no body, so it is not retained or scrubbed like the post is.
 * It does NOT notify anybody at the moment of the tap: the author hears about
 * new reactions once, in a content-free email digest (`groups:notify-reactions`,
 * which stamps `notified_at`).
 */
class GroupPostReaction extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_post_id',
        'reaction',
        'user_id',
        'contact_id',
    ];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $reaction): void {
            if (($reaction->user_id === null) === ($reaction->contact_id === null)) {
                throw new \LogicException('A reaction belongs to exactly one staff user or one parent.');
            }

            if (! Reactions::isAllowed($reaction->reaction)) {
                throw new \LogicException('That reaction is not one of the four a class story allows.');
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(GroupPost::class, 'group_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
