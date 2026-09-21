<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
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
 * payload. A reaction is not a message: it sends no notification and has no
 * body, so it is not retained or scrubbed like one.
 */
class GroupMessageReaction extends Model
{
    use BelongsToMasjid;

    /** key => emoji, in display order. */
    public const REACTIONS = [
        'ameen' => '🤲',
        'thumbs_up' => '👍',
        'hundred' => '💯',
        'question' => '❓',
    ];

    /** What each one means, for a screen reader and a tooltip. */
    public const LABELS = [
        'ameen' => 'Ameen',
        'thumbs_up' => 'Thumbs up',
        'hundred' => '100',
        'question' => 'Question',
    ];

    protected $fillable = [
        'masjid_id',
        'group_message_id',
        'reaction',
        'user_id',
        'contact_id',
    ];

    public static function isAllowed(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::REACTIONS);
    }

    /**
     * The list a client draws its buttons from.
     *
     * @return list<array{key:string, emoji:string, label:string}>
     */
    public static function catalogue(): array
    {
        $out = [];

        foreach (self::REACTIONS as $key => $emoji) {
            $out[] = ['key' => $key, 'emoji' => $emoji, 'label' => self::LABELS[$key]];
        }

        return $out;
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
