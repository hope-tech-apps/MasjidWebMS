<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The four reactions, and how a set of them is described to one viewer.
 *
 * ONE definition for the two surfaces that carry reactions: a message in a
 * teacher <-> family conversation (GroupMessageReaction) and a class story post
 * (GroupPostReaction, owner 2026-09-29). They used to be one model's constant;
 * a second surface that copied the list would be the way the two drift, so the
 * list and the summary live here and both models and both signal classes read
 * them.
 *
 * THE SET IS FIXED. Four keys, in this order, and no others: a conversation or a
 * story about a child is not the place for an open emoji picker, and the owner
 * chose these four — 🤲 is the "Ameen" a parent answers a du'a with. A key, never
 * the emoji, is what a URL, a unique index and a log line carry.
 *
 * The naming rule (summarize) is the other half of "one decision":
 *   - a STAFF viewer is shown every name;
 *   - a PARENT is shown STAFF names only, their own reaction as `mine`, and
 *     every other family's reaction as a COUNT — on a class-wide surface a name
 *     would reveal which families are in the room.
 */
final class Reactions
{
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

    /**
     * The four reactions on ONE subject, always all four and in catalogue
     * order, so a screen can draw its buttons straight from the payload.
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $rows  reaction rows with `user` / `contact` loaded
     * @return list<array<string,mixed>>
     */
    public static function summarize($rows, bool $viewerIsParent, ?int $viewerUserId, ?int $viewerContactId): array
    {
        $summary = [];

        foreach (self::REACTIONS as $key => $emoji) {
            $mine = false;
            $by = [];
            $count = 0;

            foreach ($rows as $row) {
                if ($row->reaction !== $key) {
                    continue;
                }

                $count++;

                $isViewer = ($viewerUserId !== null && (int) $row->user_id === $viewerUserId)
                    || ($viewerContactId !== null && (int) $row->contact_id === $viewerContactId);

                if ($isViewer) {
                    $mine = true;

                    continue;
                }

                $isParent = $row->contact_id !== null;

                // Counted, not named, for a parent looking at another parent.
                if ($viewerIsParent && $isParent) {
                    continue;
                }

                $by[] = ['name' => self::nameOf($row->user, $row->contact, $isParent), 'is_parent' => $isParent];
            }

            $summary[] = [
                'key' => $key,
                'emoji' => $emoji,
                'count' => $count,
                'mine' => $mine,
                'by' => $by,
            ];
        }

        return $summary;
    }

    /** A name, never an id; a blank record still reads as a person rather than vanishing. */
    public static function nameOf(?User $user, ?Contact $contact, bool $isParent): string
    {
        $name = $isParent
            ? trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''))
            : trim((string) ($user?->name ?? ''));

        return $name !== '' ? $name : ($isParent ? 'A parent' : 'Staff');
    }
}
