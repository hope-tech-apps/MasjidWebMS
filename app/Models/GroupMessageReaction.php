<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\Reactions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupMessageReaction — one person's 🤲 / 👍 / 💯 / ❓ on one message
 * (owner, 2026-09-21).
 *
 * THE SET IS FIXED, AND IT IS THIS CONSTANT. Four reactions, in this order, and
 * no others: a conversation about a child is not the place for an open emoji
 * picker, and the owner chose these four — 🤲 is the "Ameen" a parent answers a
 * du'a with. The server refuses any other key; the screens draw their buttons
 * from `meta.reactions`, which is this list.
 *
 * WHO MAY REACT is whoever may REPLY — GroupAudience::mayReceiveThread(), plus
 * the realm's own write gate (`permission:manage contacts`, `teacher.leads`, or
 * the family guard) — and never a closed conversation. It is decided in the
 * controllers exactly where replying is, not here.
 *
 * The reacting person is a staff User OR a guardian Contact, never both and
 * never neither (booted()); the principal comes from the token, never from the
 * payload. A reaction is not a message: it has no body, so it is not retained
 * or scrubbed like one.
 *
 * NOTIFICATIONS (owner, 2026-09-29 — this reverses "reactions notify nobody",
 * 2026-09-21): a tap still dispatches nothing, because a push per 👍 would bury
 * the replies. The AUTHOR of the message hears about new reactions once, in the
 * hourly content-free digest `groups:notify-reactions`, which stamps
 * `notified_at` to claim each row. See App\Console\Commands\NotifyReactions.
 */
class GroupMessageReaction extends Model
{
    use BelongsToMasjid;

    /**
     * The set lives in App\Support\Reactions, shared with the class story's
     * reactions (GroupPostReaction) so the two surfaces cannot drift. These stay
     * as aliases: they are what every existing caller and test names.
     */
    public const REACTIONS = Reactions::REACTIONS;

    public const LABELS = Reactions::LABELS;

    protected $fillable = [
        'masjid_id',
        'group_message_id',
        'reaction',
        'user_id',
        'contact_id',
    ];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public static function isAllowed(mixed $key): bool
    {
        return Reactions::isAllowed($key);
    }

    /**
     * The list a client draws its buttons from.
     *
     * @return list<array{key:string, emoji:string, label:string}>
     */
    public static function catalogue(): array
    {
        return Reactions::catalogue();
    }

    protected static function booted(): void
    {
        static::saving(function (self $reaction): void {
            if (($reaction->user_id === null) === ($reaction->contact_id === null)) {
                throw new \LogicException('A reaction belongs to exactly one staff user or one parent.');
            }

            if (! self::isAllowed($reaction->reaction)) {
                throw new \LogicException('That reaction is not one of the four this conversation allows.');
            }
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(GroupMessage::class, 'group_message_id');
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
