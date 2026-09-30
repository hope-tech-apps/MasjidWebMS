<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\GroupMessageSchedule;
use App\Models\GroupPost;
use App\Services\Groups\GroupStoryPublisher;
use App\Services\Groups\GroupThreadWriter;
use App\Services\Groups\ScheduledSendGate;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `groups:publish-due` — releases what teachers scheduled (T-002.4, owner 2026-09-29).
 *
 * Every minute. Two jobs, and they are not the same kind of job:
 *
 * ## Class stories: the sweep GATES and ANNOUNCES; the clock and the announcement PUBLISH
 *
 * A scheduled story is a `group_posts` row with a future `published_at`. Families see
 * it only when its time has passed AND it has been announced (GroupPost::scopePublished
 * asks both), and only this sweep announces a scheduled story, after the gate said yes:
 *
 *   - it asks whether the author may still send it (the S15 rule: the author left the
 *     class) and marks a refused story FAILED with the reason. A failed story never
 *     becomes visible. Looking `groups.scheduling.lookahead_seconds` ahead only lets the
 *     office read that refusal a little before the time; it is NOT what keeps the story
 *     hidden, so a killed run, a held mutex or a cron outage delays a story and cannot
 *     leak one.
 *   - once the time has come it CLAIMS the story (which is what makes it visible) and
 *     sends the class-story email, once (GroupStoryPublisher::announce).
 *
 * ## New conversations: the sweep WRITES
 *
 * A scheduled conversation is a `group_message_schedules` row and nothing else, so it
 * only exists as a thread because this sweep wrote it. For each due row:
 *
 *   1. CLAIM it (status `scheduled` -> `sending`, an UPDATE guarded by both the status
 *      and the time), so two sweeps or a second server cannot both send it;
 *   2. RE-RUN THE GATES with the tenant bound to the row's own school: the author still
 *      teaches the class, and for a conversation about one child, the child is still on
 *      the roster and has not left. A refusal is status `failed` with a reason, never a
 *      silent skip and never a send;
 *   3. WRITE it through GroupThreadWriter, the same code that opens a conversation on a
 *      request, with the move to `sent` INSIDE its transaction. So a thread exists if and
 *      only if its schedule says `sent`: a run killed part-way leaves nothing to
 *      duplicate, and a claim that has sat in `sending` past `stale_claim_minutes` wrote
 *      nothing and is handed back to the queue.
 *
 * An unexpected error while writing is `failed` too ("edit it to try again"), and is
 * logged by CLASS NAME only: a database exception message carries its bindings, and the
 * bindings are the words a teacher wrote about a child.
 *
 * ## Tenancy and logging
 *
 * Runs UNBOUND and crosses schools explicitly with withoutMasjidScope(); each item is
 * then handled with the tenant bound to ITS OWN masjid_id and the previous binding put
 * back in a `finally`, because the writer's models stamp `masjid_id` from the bound
 * tenant. `--masjid=` narrows to one school; `--dry-run` reports and changes nothing.
 * ONE log line per run, at WARNING: production runs LOG_LEVEL=warning, and an info line
 * would be written and dropped, leaving no proof the sweep ever ran.
 */
class PublishDueGroupItems extends Command
{
    protected $signature = 'groups:publish-due
                            {--masjid= : Limit the sweep to one organization}
                            {--dry-run : Report what would be released without claiming, writing or sending anything}';

    protected $description = 'Release scheduled class stories and open scheduled conversations that are due.';

    public function handle(GroupStoryPublisher $stories, ScheduledSendGate $gate, GroupThreadWriter $writer): int
    {
        $now = now();
        $narrow = $this->option('masjid') !== null;
        $masjidId = (int) $this->option('masjid');
        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('groups.scheduling.batch', 200));

        $run = ['announced' => 0, 'refused' => 0, 'sent' => 0, 'failed' => 0, 'reclaimed' => 0, 'errors' => 0];

        // A claim that never finished wrote nothing (the write and `sent` are one
        // transaction), so it is safe to hand back.
        $run['reclaimed'] = $this->reclaimStale($narrow, $masjidId, $dryRun);

        foreach ($this->dueStories($now, $narrow, $masjidId, $batch) as $post) {
            try {
                $this->releaseStory($post, $now, $stories, $dryRun, $run);
            } catch (Throwable $e) {
                $run['errors']++;
                Log::warning('groups:publish-due: story '.$post->id.' could not be released ('.get_class($e).')');
            }
        }

        foreach ($this->dueMessageIds($now, $narrow, $masjidId, $batch) as $id) {
            try {
                $this->sendMessage($id, $now, $gate, $writer, $dryRun, $run);
            } catch (Throwable $e) {
                $run['errors']++;
                Log::warning('groups:publish-due: conversation '.$id.' could not be processed ('.get_class($e).')');
            }
        }

        $line = sprintf(
            'groups:publish-due: %sstories announced=%d refused=%d; conversations sent=%d failed=%d reclaimed=%d; errors=%d%s',
            $dryRun ? '[dry-run] would have: ' : '',
            $run['announced'], $run['refused'], $run['sent'], $run['failed'], $run['reclaimed'], $run['errors'],
            $narrow ? "; masjid={$masjidId}" : ''
        );

        $this->info($line);
        Log::warning($line);

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- stories

    /** @return \Illuminate\Support\Collection<int,GroupPost> */
    private function dueStories($now, bool $narrow, int $masjidId, int $batch)
    {
        // Not yet announced, not refused, and due within the look-ahead. A cancelled
        // (soft-deleted) story is outside the default scope and never comes back.
        return GroupPost::withoutMasjidScope()
            ->whereNull('announced_at')
            ->whereNull('publish_failed_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now->copy()->addSeconds(max(0, (int) config('groups.scheduling.lookahead_seconds', 120))))
            ->when($narrow, fn ($q) => $q->where('masjid_id', $masjidId))
            ->orderBy('published_at')
            ->orderBy('id')
            ->limit($batch)
            ->get();
    }

    /** @param array<string,int> $run */
    private function releaseStory(GroupPost $post, $now, GroupStoryPublisher $stories, bool $dryRun, array &$run): void
    {
        $this->withTenant((int) $post->masjid_id, function () use ($post, $now, $stories, $dryRun, &$run): void {
            $group = Group::withoutMasjidScope()->find($post->group_id);
            $reason = $stories->refusal($post, $group);

            if ($reason !== null) {
                if (! $dryRun) {
                    $stories->refuse($post, $reason);
                }
                $run['refused']++;

                return;
            }

            // Inside the look-ahead but not due: it has passed the gate, and waits.
            if ($post->published_at->gt($now)) {
                return;
            }

            if ($dryRun || $stories->announce($post)) {
                $run['announced']++;
            }
        });
    }

    // ----------------------------------------------------------- conversations

    private function reclaimStale(bool $narrow, int $masjidId, bool $dryRun): int
    {
        $stale = GroupMessageSchedule::withoutMasjidScope()
            ->where('status', GroupMessageSchedule::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(max(1, (int) config('groups.scheduling.stale_claim_minutes', 10))))
            ->when($narrow, fn ($q) => $q->where('masjid_id', $masjidId));

        if ($dryRun) {
            return $stale->count();
        }

        return $stale->update(['status' => GroupMessageSchedule::STATUS_SCHEDULED, 'updated_at' => now()]);
    }

    /** @return \Illuminate\Support\Collection<int,int> */
    private function dueMessageIds($now, bool $narrow, int $masjidId, int $batch)
    {
        return GroupMessageSchedule::withoutMasjidScope()
            ->due($now)
            ->when($narrow, fn ($q) => $q->where('masjid_id', $masjidId))
            ->orderBy('send_at')
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id');
    }

    /** @param array<string,int> $run */
    private function sendMessage(int $id, $now, ScheduledSendGate $gate, GroupThreadWriter $writer, bool $dryRun, array &$run): void
    {
        if ($dryRun) {
            $run['sent']++;

            return;
        }

        // 1. CLAIM. Guarded by the status AND the time: an item edited (moved later,
        // or cancelled) after this run listed it is not the item that was listed.
        $claimed = GroupMessageSchedule::withoutMasjidScope()
            ->whereKey($id)
            ->where('status', GroupMessageSchedule::STATUS_SCHEDULED)
            ->where('send_at', '<=', $now)
            ->update(['status' => GroupMessageSchedule::STATUS_SENDING, 'updated_at' => now()]) === 1;

        if (! $claimed) {
            return;
        }

        $item = GroupMessageSchedule::withoutMasjidScope()->findOrFail($id);

        $this->withTenant((int) $item->masjid_id, function () use ($item, $gate, $writer, &$run): void {
            $group = Group::withoutMasjidScope()->find($item->group_id);

            // 2. THE GATES, asked again now.
            $reason = $group === null
                ? 'The class no longer exists.'
                : ($gate->authorRefusal($item->author_user_id !== null ? (int) $item->author_user_id : null, $group)
                    ?? $gate->aboutRefusal($group, $item->about_membership_id !== null ? (int) $item->about_membership_id : null, $item->isAboutOneChild()));

            if ($reason !== null) {
                $this->markFailed($item, $reason);
                $run['failed']++;

                return;
            }

            // 3. WRITE it, with `sent` inside the transaction.
            try {
                $writer->open(
                    $group,
                    (int) $item->author_user_id,
                    (string) $item->subject,
                    (string) $item->scope,
                    $gate->aboutMembership($group, $item->about_membership_id !== null ? (int) $item->about_membership_id : null),
                    (string) $item->body,
                    [],
                    null,
                    function ($thread) use ($item): void {
                        $marked = GroupMessageSchedule::withoutMasjidScope()
                            ->whereKey($item->id)
                            ->where('status', GroupMessageSchedule::STATUS_SENDING)
                            ->update([
                                'status' => GroupMessageSchedule::STATUS_SENT,
                                'sent_thread_id' => $thread->id,
                                'failure_reason' => null,
                                'updated_at' => now(),
                            ]);

                        if ($marked !== 1) {
                            // Rolls the thread back: never a conversation whose schedule
                            // does not say it was sent.
                            throw new \RuntimeException('The schedule was no longer claimed.');
                        }
                    }
                );

                $run['sent']++;
            } catch (Throwable $e) {
                $this->markFailed($item, 'It could not be sent because of an error, so nothing was sent. Edit it to try again.');
                $run['failed']++;
                Log::warning('groups:publish-due: conversation '.$item->id.' failed to write ('.get_class($e).')');
            }
        });
    }

    private function markFailed(GroupMessageSchedule $item, string $reason): void
    {
        GroupMessageSchedule::withoutMasjidScope()
            ->whereKey($item->id)
            ->where('status', GroupMessageSchedule::STATUS_SENDING)
            ->update([
                'status' => GroupMessageSchedule::STATUS_FAILED,
                'failure_reason' => mb_substr($reason, 0, 500),
                'updated_at' => now(),
            ]);
    }

    /**
     * Run `$callback` with the tenant bound to `$masjidId`, and restore whatever was
     * bound before. The models the writer creates stamp `masjid_id` from the bound
     * tenant, and GroupAudience reads through it, so an item handled unbound would be
     * stamped with nothing or read as "no standing".
     */
    private function withTenant(int $masjidId, callable $callback): void
    {
        $tenant = app(TenantContext::class);
        $previousId = $tenant->get();
        $previousMembership = $tenant->membership();

        $tenant->set($masjidId);

        try {
            $callback();
        } finally {
            if ($previousId === null) {
                $tenant->forgetTenant();
            } elseif ($previousMembership !== null) {
                $tenant->setFromMembership($previousMembership);
            } else {
                $tenant->set($previousId);
            }
        }
    }
}
