<?php

namespace App\Console\Commands;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageReaction;
use App\Models\GroupPost;
use App\Models\GroupPostReaction;
use App\Models\GroupThread;
use App\Services\Groups\GroupNotificationRecipientResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The reaction DIGEST (owner, 2026-09-29; T-002.2).
 *
 * Tells the AUTHOR of a class story or a message, once, in a content-free email,
 * that there are new reactions. It reverses the 2026-09-21 rule that "reactions
 * notify nobody" — the owner asked for notifications on reactions — but keeps
 * that rule's reason: a push per 👍 would bury the replies. So a tap dispatches
 * nothing (GroupMessageReactionsTest pins it), and THIS sweep is the only thing
 * that ever speaks about a reaction.
 *
 * ## What it sends
 *
 * ONE email per author per class per run: "You have new reactions", with the
 * class name and a sign-in link. No names, no emoji, no counts and no content —
 * the details are shown after sign-in, where consent and identity are checked
 * (GroupUpdateNudgeMail). There is no staff push: the staff app is parked.
 *
 * ## Who hears
 *
 * The author only. Never the author's own reaction to their own thing. Never the
 * rest of the class.
 *
 * ## The settle window
 *
 * A reaction must have stood for `groups.reactions.settle_minutes` (default 10,
 * an ESTIMATE — production has no reactions to measure) before it counts: a tap
 * taken back within it is deleted and never announced, and a burst of reactions
 * on one story is one email, not several.
 *
 * ## Consent is re-checked at SEND time, on both ends
 *
 * A reaction row outlives the standing of the person who made it, and so does the
 * author's. Here the REACTOR must still be entitled to read what they reacted to
 * (a guardian whose consent was withdrawn, or whose family left, between the tap
 * and the digest is not counted), and the job re-checks that the RECIPIENT may
 * still read it (App\Services\Groups\GroupNotificationRecipientResolver::
 * reactionRecipient) at the moment it sends.
 *
 * ## At most once
 *
 * Each row is CLAIMED by an UPDATE guarded by `notified_at IS NULL` before its
 * email is dispatched, so an overlapping run (or a second box) cannot send it
 * twice. The price is the honest one: a crash between the claim and the send
 * loses that digest rather than repeating it. A row that is skipped — the
 * author's own reaction, a deleted story, a reactor who left, an author with no
 * address — is claimed too, so it is not re-examined every hour forever.
 *
 * Runs UNBOUND, crossing organisations explicitly with withoutMasjidScope().
 * `--masjid=` narrows to one; `--dry-run` reports without claiming or sending.
 * One log line per run: WARNING level, because production runs LOG_LEVEL=warning
 * and an info line would be written and dropped.
 */
class NotifyReactions extends Command
{
    protected $signature = 'groups:notify-reactions
                            {--settle= : Minutes a reaction must have stood before it counts (default: groups.reactions.settle_minutes)}
                            {--masjid= : Limit the sweep to one organization}
                            {--dry-run : Report what would be sent without claiming or sending anything}';

    protected $description = 'Email the author of a class story or message, once, that there are new reactions (content-free digest).';

    public function handle(GroupNotificationRecipientResolver $resolver): int
    {
        $settle = $this->option('settle') !== null
            ? max(0, (int) $this->option('settle'))
            : max(0, (int) config('groups.reactions.settle_minutes', 10));
        $cutoff = now()->subMinutes($settle);
        $narrow = $this->option('masjid') !== null;
        $masjidId = (int) $this->option('masjid');
        $dryRun = (bool) $this->option('dry-run');

        /** @var Collection<int, array<string,mixed>> $items */
        $items = collect()
            ->merge($this->postReactions($cutoff, $narrow, $masjidId))
            ->merge($this->messageReactions($cutoff, $narrow, $masjidId));

        $skipped = 0;
        /** @var array<string, array<string,mixed>> $digests keyed by recipient|group */
        $digests = [];

        foreach ($items as $item) {
            $verdict = $this->judge($item, $resolver);

            if ($verdict === null) {
                $skipped += $this->claim($item, $dryRun) ? 1 : 0;

                continue;
            }

            $digests[$verdict['key']] ??= $verdict + ['rows' => []];
            $digests[$verdict['key']]['rows'][] = $item;
        }

        $sent = 0;
        $reactions = 0;

        foreach ($digests as $digest) {
            // Claim first: only a row THIS run claimed may be announced by it.
            $claimed = collect($digest['rows'])->filter(fn (array $item): bool => $this->claim($item, $dryRun));

            if ($claimed->isEmpty()) {
                continue;
            }

            $reactions += $claimed->count();
            $sent++;

            if ($dryRun) {
                continue;
            }

            SendGroupNotificationJob::dispatch(
                (int) $digest['masjid_id'],
                (int) $digest['group_id'],
                GroupNotificationEvent::REACTION,
                recipientUserId: $digest['user_id'],
                recipientContactId: $digest['contact_id'],
                subjects: array_values(array_unique($claimed->map(fn (array $item): string => $item['subject'])->all())),
            );
        }

        $line = sprintf(
            'groups:notify-reactions: %s%d digest(s) for %d reaction(s); %d skipped without a send; settle=%dm%s',
            $dryRun ? '[dry-run] would send ' : 'sent ',
            $sent, $reactions, $skipped, $settle, $narrow ? "; masjid={$masjidId}" : ''
        );

        $this->info($line);
        Log::warning($line);

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function postReactions($cutoff, bool $narrow, int $masjidId): array
    {
        $rows = GroupPostReaction::withoutMasjidScope()
            ->whereNull('notified_at')
            ->where('created_at', '<=', $cutoff)
            ->when($narrow, fn ($q) => $q->where('masjid_id', $masjidId))
            ->orderBy('id')
            ->get();

        return $rows->map(function (GroupPostReaction $reaction): array {
            $post = GroupPost::withoutMasjidScope()->withTrashed()->find($reaction->group_post_id);
            $live = $post !== null && ! $post->trashed();

            return [
                'model' => $reaction,
                'subject' => 'story',
                'thread_id' => null,
                'masjid_id' => (int) $reaction->masjid_id,
                'group_id' => $post?->group_id !== null ? (int) $post->group_id : null,
                // A soft-deleted or purged story has nothing to show anybody.
                'live' => $live,
                'author_user_id' => $post?->author_user_id !== null ? (int) $post->author_user_id : null,
                'author_contact_id' => null,
                // A story that is not OUT yet (scheduled, or refused at release) has no
                // reader, so a reaction on it must not be announced to its author. Such a
                // row cannot normally exist (the reaction endpoints refuse an unpublished
                // story), and if one does it is left UNCLAIMED, not forgotten: once the
                // story is out the next sweep tells its author, exactly once.
                'deferred' => $live && ! $post->isPublished(),
            ];
        })->reject(fn (array $item): bool => $item['deferred'])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function messageReactions($cutoff, bool $narrow, int $masjidId): array
    {
        $rows = GroupMessageReaction::withoutMasjidScope()
            ->whereNull('notified_at')
            ->where('created_at', '<=', $cutoff)
            ->when($narrow, fn ($q) => $q->where('masjid_id', $masjidId))
            ->orderBy('id')
            ->get();

        return $rows->map(function (GroupMessageReaction $reaction): array {
            $message = GroupMessage::withoutMasjidScope()->find($reaction->group_message_id);
            $thread = $message !== null
                ? GroupThread::withoutMasjidScope()->withTrashed()->find($message->group_thread_id)
                : null;

            return [
                'model' => $reaction,
                'subject' => $thread !== null ? 'thread:'.$thread->id : 'thread:0',
                'thread_id' => $thread !== null ? (int) $thread->id : null,
                'masjid_id' => (int) $reaction->masjid_id,
                'group_id' => $thread !== null ? (int) $thread->group_id : null,
                'live' => $thread !== null && ! $thread->trashed(),
                'author_user_id' => $message?->author_user_id !== null ? (int) $message->author_user_id : null,
                'author_contact_id' => $message?->author_contact_id !== null ? (int) $message->author_contact_id : null,
            ];
        })->all();
    }

    /**
     * Should this reaction be announced, and to whom? Null means "no": it is
     * claimed and forgotten, not examined again.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function judge(array $item, GroupNotificationRecipientResolver $resolver): ?array
    {
        /** @var GroupPostReaction|GroupMessageReaction $reaction */
        $reaction = $item['model'];

        // Something that no longer exists, or whose class is gone.
        if (! $item['live'] || $item['group_id'] === null) {
            return null;
        }

        $hasAuthor = $item['author_user_id'] !== null || $item['author_contact_id'] !== null;

        if (! $hasAuthor) {
            return null;
        }

        // NEVER FOR THE AUTHOR'S OWN REACTION.
        $isOwn = ($reaction->user_id !== null && (int) $reaction->user_id === $item['author_user_id'])
            || ($reaction->contact_id !== null && (int) $reaction->contact_id === $item['author_contact_id']);

        if ($isOwn) {
            return null;
        }

        $group = Group::withoutMasjidScope()->find($item['group_id']);

        if ($group === null) {
            return null;
        }

        // RE-CHECK THE REACTOR. Consent withdrawn, or a family that left, between
        // the tap and this sweep: their reaction no longer counts.
        if (! $resolver->principalMayStillRead(
            $group,
            $reaction->user_id !== null ? (int) $reaction->user_id : null,
            $reaction->contact_id !== null ? (int) $reaction->contact_id : null,
            $item['thread_id'],
        )) {
            return null;
        }

        return [
            'key' => ($item['author_user_id'] !== null ? 'u'.$item['author_user_id'] : 'c'.$item['author_contact_id']).'|'.$group->id,
            'masjid_id' => (int) $group->masjid_id,
            'group_id' => (int) $group->id,
            'user_id' => $item['author_user_id'],
            'contact_id' => $item['author_user_id'] === null ? $item['author_contact_id'] : null,
        ];
    }

    /**
     * Stamp the row as announced. True only if THIS call did it — the UPDATE is
     * guarded by `notified_at IS NULL`, so of two overlapping runs exactly one
     * wins each row. A dry run claims nothing and reports true.
     *
     * @param array<string,mixed> $item
     */
    private function claim(array $item, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        /** @var Model $reaction */
        $reaction = $item['model'];

        return $reaction::withoutMasjidScope()
            ->whereKey($reaction->getKey())
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]) === 1;
    }
}
