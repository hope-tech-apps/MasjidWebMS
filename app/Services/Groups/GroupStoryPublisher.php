<?php

namespace App\Services\Groups;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Group;
use App\Models\GroupPost;

/**
 * Releasing a class story that was scheduled (T-002.4).
 *
 * VISIBILITY AND ANNOUNCEMENT ARE TWO DIFFERENT THINGS, and only one of them is this
 * class's business. Whether a family can SEE a story is the clock:
 * GroupPost::scopePublished() asks `published_at <= now`, on every family read, with
 * no sweep in the loop. This class does the other half: the class-story EMAIL, sent
 * once, and the refusal of a story whose author may no longer send it.
 *
 * announce() is the email. It CLAIMS the story with an UPDATE guarded by
 * `announced_at IS NULL` and dispatches only if that update changed a row, so two
 * overlapping sweeps, a retry and "Send now" racing the sweep all produce one email.
 * The price is the honest one every claim in this codebase pays: a crash between the
 * claim and the dispatch loses that email rather than repeating it (the story itself
 * is on screen regardless).
 *
 * refuse() is S15: the author left the class before the send time. The story is NOT
 * announced and, because scopePublished() excludes a failed row whatever the clock
 * says, never becomes visible when its time passes. The teacher and the office see it
 * in the Scheduled list with the reason, and may edit it (a new time puts it back) or
 * cancel it.
 */
class GroupStoryPublisher
{
    public function __construct(private ScheduledSendGate $gate)
    {
    }

    /**
     * Send the class-story email for a story that is out, at most once. True only when
     * THIS call claimed it.
     */
    public function announce(GroupPost $post): bool
    {
        $claimed = GroupPost::withoutMasjidScope()
            ->whereKey($post->getKey())
            ->whereNull('announced_at')
            ->whereNull('publish_failed_at')
            ->update(['announced_at' => now()]) === 1;

        if (! $claimed) {
            return false;
        }

        SendGroupNotificationJob::dispatch(
            (int) $post->masjid_id,
            (int) $post->group_id,
            GroupNotificationEvent::CLASS_STORY,
            aboutContactId: null,
            authorUserId: $post->author_user_id !== null ? (int) $post->author_user_id : null,
            authorContactId: null,
        );

        return true;
    }

    /** Record that a scheduled story will not go out, and why. Never announces. */
    public function refuse(GroupPost $post, string $reason): void
    {
        GroupPost::withoutMasjidScope()
            ->whereKey($post->getKey())
            ->whereNull('announced_at')
            ->update([
                'publish_failed_at' => now(),
                'publish_failure' => mb_substr($reason, 0, 255),
            ]);
    }

    /**
     * The gates a scheduled story is asked before it may go out. Null = it may.
     * (The author must still be able to write to this class; a story has no child.)
     */
    public function refusal(GroupPost $post, ?Group $group): ?string
    {
        if ($group === null) {
            return 'The class no longer exists.';
        }

        return $this->gate->authorRefusal(
            $post->author_user_id !== null ? (int) $post->author_user_id : null,
            $group
        );
    }
}
