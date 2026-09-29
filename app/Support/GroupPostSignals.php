<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\GroupPost;
use App\Models\GroupPostReaction;
use App\Models\User;
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
     * The four reactions on one post, for the response to a tap.
     *
     * @return list<array<string,mixed>>
     */
    public static function reactionsFor(GroupPost $post, ?Authenticatable $viewer): array
    {
        return self::forPosts([$post], $viewer)[(int) $post->id]['reactions'] ?? [];
    }
}
