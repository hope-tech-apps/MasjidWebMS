<?php

namespace App\Console\Commands;

use App\Models\BehaviorAward;
use App\Models\GroupPost;
use App\Models\GroupThread;
use Illuminate\Console\Command;

/**
 * Retention purge for group feeds and messaging threads (PLAN T-005b/T-005c).
 *
 * .claude/rules/groups.md, obligation 3: retention is "a nullable
 * `retained_until` plus a purge that reaches the disk (a DB cascade fires no
 * model events, so it orphans bytes forever)". This is that purge, and T-005c
 * folded the messaging threads into the SAME sweep rather than a parallel
 * command: retention over a group's content is one policy, not one per table.
 *
 * It force-deletes every post whose retention window has closed — soft-deleted
 * ones included, because a post an admin removed last month is exactly the one
 * that should not linger — THROUGH THE MODEL, so GroupPost's `deleting` hook
 * removes the attachment rows and each attachment's own hook removes the bytes.
 * A `DELETE FROM group_posts WHERE ...` would be faster and would leave every
 * photograph on disk forever.
 *
 * Threads sweep on the same window. Their messages and read markers go by DB
 * cascade off GroupThread::purge(), which is SAFE for them in a way it is not
 * for posts: a thread message is rows only (attachments are deliberately out of
 * T-005c), so a cascade that fires no model events orphans nothing.
 *
 * Runs UNBOUND (no tenant on a console request), so the sweep is deliberately
 * explicit about crossing organizations via withoutMasjidScope() rather than
 * relying on the absence of a binding — see .claude/rules/tenant-scoping.md.
 * `--masjid=` narrows it to one organization when a single tenant asks.
 *
 * Not scheduled by default. Retention is a policy an organization owns, so the
 * cadence is an operator's decision; add it to routes/console.php when the
 * policy is agreed.
 */
class PurgeGroupFeed extends Command
{
    protected $signature = 'groups:purge-feed
                            {--before= : Purge posts/threads/awards retained only until this date (default: today)}
                            {--masjid= : Limit the sweep to one organization}
                            {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Force-delete group feed posts, messaging threads and behaviour awards past their retention date, including feed images on the private disk.';

    public function handle(): int
    {
        $before = (string) ($this->option('before') ?: now()->toDateString());
        $masjidId = $this->option('masjid');
        $dryRun = (bool) $this->option('dry-run');

        $query = GroupPost::withoutMasjidScope()
            ->dueForPurge($before)
            ->when($masjidId, fn ($q) => $q->where('masjid_id', (int) $masjidId))
            ->orderBy('id');

        $posts = 0;
        $images = 0;

        // Chunked by id so a large sweep never holds the whole feed in memory,
        // and so force-deleting rows underneath the cursor cannot make it skip.
        $query->chunkById(100, function ($due) use (&$posts, &$images, $dryRun) {
            foreach ($due as $post) {
                $attachmentCount = $post->attachments()->count();

                if (! $dryRun) {
                    // purge() -> forceDelete() -> the deleting hook -> each
                    // attachment's deleting hook -> the bytes.
                    $post->purge();
                }

                $posts++;
                $images += $attachmentCount;
            }
        });

        // The thread sweep, on the same window and the same unbound-but-
        // explicit footing. Counted before deletion so a dry run reports the
        // same numbers the real run would.
        $threadQuery = GroupThread::withoutMasjidScope()
            ->dueForPurge($before)
            ->when($masjidId, fn ($q) => $q->where('masjid_id', (int) $masjidId))
            ->orderBy('id');

        $threads = 0;
        $messages = 0;

        $threadQuery->chunkById(100, function ($due) use (&$threads, &$messages, $dryRun) {
            foreach ($due as $thread) {
                $messageCount = $thread->messages()->count();

                if (! $dryRun) {
                    // purge() -> forceDelete(); messages and read markers go by
                    // DB cascade — rows only, nothing on disk to reach.
                    $thread->purge();
                }

                $threads++;
                $messages += $messageCount;
            }
        });

        // Behaviour awards (T-013) join the SAME sweep for the same reason the
        // threads did: retention over a group's content is one policy, not one
        // per table. Rows only — an award carries nothing on disk — so
        // BehaviorAward::purge() is a plain forceDelete, as it is for threads.
        // Revoked (soft-deleted) awards are included, because an award a teacher
        // withdrew last month is exactly the one that should not linger.
        $awardQuery = BehaviorAward::withoutMasjidScope()
            ->dueForPurge($before)
            ->when($masjidId, fn ($q) => $q->where('masjid_id', (int) $masjidId))
            ->orderBy('id');

        $awards = 0;

        $awardQuery->chunkById(100, function ($due) use (&$awards, $dryRun) {
            foreach ($due as $award) {
                if (! $dryRun) {
                    $award->purge();
                }

                $awards++;
            }
        });

        $this->info(sprintf(
            '%s %d post(s) and %d image(s), %d thread(s) and %d message(s), %d behaviour award(s)%s.',
            $dryRun ? 'Would purge' : 'Purged',
            $posts,
            $images,
            $threads,
            $messages,
            $awards,
            $masjidId ? " for masjid {$masjidId}" : ''
        ));

        return self::SUCCESS;
    }
}
