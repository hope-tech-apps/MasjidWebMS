<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\GroupMessage;
use App\Models\GroupMessageReaction;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Reactions and read receipts on the messages of one thread, as ONE viewer may
 * see them (owner, 2026-09-21).
 *
 * Every realm's serializer — admin, teacher and family — goes through this, so
 * "whose name may this person be shown?" is decided once:
 *
 *   - A STAFF viewer (office or teacher) is shown every name: which parents
 *     and which colleagues reacted, and which of them have read each message.
 *     They can already read the whole conversation and its roster, so a name
 *     here discloses nothing they could not already see.
 *   - A PARENT is shown STAFF names only — "the teacher has read it", "Ustadh
 *     Bilal reacted 👍" — plus whether they themselves reacted. Another
 *     family member's reaction is COUNTED but never named, and a parent is not
 *     shown when any other parent read anything. On a class-wide conversation
 *     that would tell one family which other families are in the room and
 *     reading; on a private one it would tell one guardian when the child's
 *     other guardian opened it, which is not always a safe thing to tell.
 *
 * The CALLER must already have decided the viewer may read the thread
 * (GroupAudience::mayReceiveThread). This class never widens what is visible;
 * it only decides how much of the thread's own activity is described.
 *
 * A receipt is DERIVED, never stored: a reader has seen a message when their
 * GroupThreadRead bookmark covers it (GroupThreadRead::covers()). Nobody is
 * listed as having read their own message, and the viewer is never listed
 * among the people who reacted or read — the screen says "You" from `mine`.
 */
final class GroupMessageSignals
{
    /**
     * @param iterable<GroupMessage> $messages
     * @return array<int, array{reactions: list<array<string,mixed>>, read_by: list<array{name:string, is_parent:bool}>}>
     *         keyed by message id
     */
    public static function forMessages(GroupThread $thread, iterable $messages, ?Authenticatable $viewer): array
    {
        $messages = collect($messages)->filter(fn ($m) => $m instanceof GroupMessage)->values();

        if ($messages->isEmpty()) {
            return [];
        }

        $viewerIsParent = $viewer instanceof Contact;
        $viewerUserId = $viewer instanceof User ? (int) $viewer->getKey() : null;
        $viewerContactId = $viewerIsParent ? (int) $viewer->getKey() : null;

        $reactions = GroupMessageReaction::query()
            ->whereIn('group_message_id', $messages->pluck('id')->all())
            ->with(['user:id,name', 'contact:id,first_name,last_name'])
            ->orderBy('id')
            ->get()
            ->groupBy('group_message_id');

        $reads = GroupThreadRead::query()
            ->where('group_thread_id', $thread->id)
            // A parent is never told about another parent's reading.
            ->when($viewerIsParent, fn ($q) => $q->whereNotNull('user_id'))
            ->with(['user:id,name', 'contact:id,first_name,last_name'])
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($messages as $message) {
            $out[(int) $message->id] = [
                'reactions' => self::reactionSummary(
                    $reactions->get($message->id, collect()),
                    $viewerIsParent, $viewerUserId, $viewerContactId
                ),
                'read_by' => self::readers($message, $reads, $viewerUserId, $viewerContactId),
            ];
        }

        return $out;
    }

    /**
     * The four reactions on one message, always all four and in catalogue
     * order, so a screen can draw its buttons straight from the payload.
     *
     * @return list<array<string,mixed>>
     */
    public static function reactionsFor(GroupMessage $message, ?Authenticatable $viewer): array
    {
        return self::forMessages($message->thread, [$message], $viewer)[(int) $message->id]['reactions'] ?? [];
    }

    /**
     * @param \Illuminate\Support\Collection<int, GroupMessageReaction> $rows
     * @return list<array<string,mixed>>
     */
    private static function reactionSummary($rows, bool $viewerIsParent, ?int $viewerUserId, ?int $viewerContactId): array
    {
        $summary = [];

        foreach (GroupMessageReaction::REACTIONS as $key => $emoji) {
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

    /**
     * @param \Illuminate\Support\Collection<int, GroupThreadRead> $reads already narrowed for a parent viewer
     * @return list<array{name:string, is_parent:bool}>
     */
    private static function readers(GroupMessage $message, $reads, ?int $viewerUserId, ?int $viewerContactId): array
    {
        $readers = [];

        foreach ($reads as $read) {
            $isParent = $read->contact_id !== null;

            // The author has "read" their own message by writing it; saying so
            // would make every message look seen.
            $isAuthor = ($read->user_id !== null && (int) $read->user_id === (int) $message->author_user_id)
                || ($isParent && (int) $read->contact_id === (int) $message->author_contact_id);

            $isViewer = ($viewerUserId !== null && (int) $read->user_id === $viewerUserId)
                || ($viewerContactId !== null && (int) $read->contact_id === $viewerContactId);

            if ($isAuthor || $isViewer || ! $read->covers($message)) {
                continue;
            }

            $readers[] = ['name' => self::nameOf($read->user, $read->contact, $isParent), 'is_parent' => $isParent];
        }

        return $readers;
    }

    /** A name, never an id; a blank record still reads as a person rather than vanishing. */
    private static function nameOf(?User $user, ?Contact $contact, bool $isParent): string
    {
        $name = $isParent
            ? trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''))
            : trim((string) ($user?->name ?? ''));

        return $name !== '' ? $name : ($isParent ? 'A parent' : 'Staff');
    }
}
