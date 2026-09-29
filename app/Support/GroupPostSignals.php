<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\GroupPost;
use App\Models\GroupPostRead;
use App\Models\GroupPostReaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Reactions on the posts of a class story, as ONE viewer may see them
 * (owner, 2026-09-29).
 *
 * The story's counterpart of GroupMessageSignals, and the same naming rule —
 * which is Reactions::summarize(), shared, not copied:
 *
 *   - A STAFF viewer (office or teacher) is shown every name.
 *   - A PARENT is shown STAFF names, their own reaction as `mine`, and every
 *     other family as a COUNT only. A class story goes to the whole class, so a
 *     name would tell one family which other families are in the room.
 *
 * The CALLER must already have decided the viewer may read the feed
 * (GroupAudience::DISCLOSURE_FEED). This class never widens what is visible; it
 * only decides how much of the post's own activity is described.
 *
 * The viewer is never listed among the people who reacted — the screen says
 * "You" from `mine`.
 */
final class GroupPostSignals
{
    /**
     * @param iterable<GroupPost> $posts
     * @return array<int, array{reactions: list<array<string,mixed>>}> keyed by post id
     */
    public static function forPosts(iterable $posts, ?Authenticatable $viewer): array
    {
        $posts = collect($posts)->filter(fn ($p) => $p instanceof GroupPost)->values();

        if ($posts->isEmpty()) {
            return [];
        }

        $viewerIsParent = $viewer instanceof Contact;
        $viewerUserId = $viewer instanceof User ? (int) $viewer->getKey() : null;
        $viewerContactId = $viewerIsParent ? (int) $viewer->getKey() : null;

        $reactions = GroupPostReaction::query()
            ->whereIn('group_post_id', $posts->pluck('id')->all())
            ->with(['user:id,name', 'contact:id,first_name,last_name'])
            ->orderBy('id')
            ->get()
            ->groupBy('group_post_id');

        $out = [];

        foreach ($posts as $post) {
            $out[(int) $post->id] = [
                'reactions' => Reactions::summarize(
                    $reactions->get($post->id, collect()),
                    $viewerIsParent, $viewerUserId, $viewerContactId
                ),
            ];
        }

        return $out;
    }

    /**
     * "Seen by 4 of 7 parents", per post, for a STAFF viewer (owner, 2026-09-29).
     *
     * THIS IS STAFF-ONLY BY CONSTRUCTION. Only the admin/teacher serializer calls
     * it; the family serializer never builds `seen_by`, `seen_count` or
     * `audience_count`, and GroupPostReadsTest walks the family JSON to prove
     * it. A parent is never
     * told another parent opened anything, or how many did.
     *
     * The AUDIENCE is GroupAudience::storyGuardianContacts(): consented, current
     * guardians with a live family login. A read row counts only while its reader
     * is still in that audience, so `seen_count` can never exceed
     * `audience_count`: a family that left, withdrew consent or lost its login
     * stops being counted on either side of the fraction.
     *
     * A story that PREDATES `$since` and has no read on it is `tracked => false`,
     * not "0 seen": nothing is recorded before the switch goes on, so 0 there
     * means "not kept", and a staff screen must not say "no parent opened it".
     * `since` is a school-local `Y-m-d`.
     *
     * @param iterable<GroupPost> $posts
     * @param \Illuminate\Support\Collection<int,Contact> $audience keyed by contact id
     * @return array<int, array{seen_by: list<array{name:string, seen_at:?string}>, seen_count:int, audience_count:int, tracked:bool, since:?string}>
     *         keyed by post id
     */
    public static function seenFor(iterable $posts, $audience, ?CarbonImmutable $since = null, ?string $timezone = null): array
    {
        $posts = collect($posts)->filter(fn ($p) => $p instanceof GroupPost)->values();

        if ($posts->isEmpty()) {
            return [];
        }

        $reads = $audience->isEmpty()
            ? collect()
            : GroupPostRead::query()
                ->whereIn('group_post_id', $posts->pluck('id')->all())
                ->whereIn('contact_id', $audience->keys()->all())
                ->orderBy('first_seen_at')
                ->orderBy('id')
                ->get()
                ->groupBy('group_post_id');

        $out = [];

        foreach ($posts as $post) {
            $seenBy = [];

            foreach ($reads->get($post->id, collect()) as $read) {
                /** @var Contact|null $contact */
                $contact = $audience->get($read->contact_id);

                $seenBy[] = [
                    'name' => Reactions::nameOf(null, $contact, true),
                    'seen_at' => optional($read->first_seen_at)->toIso8601String(),
                ];
            }

            $untracked = $since !== null
                && $seenBy === []
                && $post->created_at !== null
                && $post->created_at->lt($since);

            $out[(int) $post->id] = [
                'seen_by' => $seenBy,
                'seen_count' => count($seenBy),
                'audience_count' => $audience->count(),
                'tracked' => ! $untracked,
                'since' => $untracked ? $since->setTimezone($timezone ?: config('app.timezone'))->toDateString() : null,
            ];
        }

        return $out;
    }

    /**
     * When recording began for THIS school (the bound tenant), or null when that
     * is not yet known.
     *
     * `groups.story_reads.since` if the owner set it (a day, read as the school's
     * own midnight); otherwise the school's earliest recorded read, which needs
     * no setting and is exact about what was kept. Before any read exists there
     * is nothing to date, and no story is marked.
     */
    public static function trackingSince(?string $timezone = null): ?CarbonImmutable
    {
        $timezone = $timezone ?: (string) config('app.timezone');
        $configured = config('groups.story_reads.since');

        if (is_string($configured) && trim($configured) !== '') {
            try {
                return CarbonImmutable::parse(trim($configured), $timezone)->startOfDay();
            } catch (\Throwable) {
                // A malformed date is ignored, not fatal: fall back to the data.
            }
        }

        $first = GroupPostRead::query()->min('first_seen_at');

        return $first !== null ? CarbonImmutable::parse($first) : null;
    }

    /**
     * The four reactions on one post, for the response to a tap.
     *
     * @return list<array<string,mixed>>
     */
    public static function reactionsFor(GroupPost $post, ?Authenticatable $viewer): array
    {
        return self::forPosts([$post], $viewer)[(int) $post->id]['reactions'] ?? [];
    }
}
