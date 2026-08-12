<?php

namespace App\Console\Commands;

use App\Models\BehaviorAward;
use App\Models\GroupPost;
use App\Models\GroupThread;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
 * SCHEDULED DAILY in routes/console.php. It used to say "not scheduled by
 * default; the cadence is an operator's decision", which sounds like deference
 * and was in practice a configured-but-unenforced retention policy: three
 * `retention_days` keys in config/groups.php, a `retained_until` stamped on
 * every row about a child, and no cron anywhere in the system that would ever
 * act on them. Deleting minors' records on a timer is the promise this codebase
 * makes; a promise nothing executes is worse than no promise. `--before=`,
 * `--masjid=` and `--dry-run` remain for a one-off operator run.
 *
 * IDEMPOTENT AND SAFE TO RUN TWICE, which is what makes a daily schedule
 * harmless: the sweep selects strictly on `retained_until <= $before`, and a
 * force-deleted row is gone from the table entirely, so a second run in the same
 * day selects nothing and reports zeros. A run that dies half way leaves the
 * rows it had not reached still due, and the next run takes them.
 *
 * Counts go to the LOG as well as stdout. Under `schedule:run` stdout is
 * discarded, so the console line alone would mean a sweep that silently stopped
 * finding rows — or silently deleted a term's worth — looks exactly like one
 * that never ran.
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

        // `!== null`, NOT truthiness. `--masjid=0` is a string "0", which is
        // FALSY in PHP, so `when($masjidId, …)` skipped the narrowing entirely
        // and swept EVERY tenant — on a command that force-deletes minors'
        // records. Verified against two seeded tenants before the fix: both had
        // their due rows deleted. `--masjid=abc` was always safe (truthy, casts
        // to 0, matches nothing), which is precisely what made `0` the sharp
        // edge: the one spelling that silently widens instead of narrowing.
        $narrowToMasjid = $masjidId !== null;
        $dryRun = (bool) $this->option('dry-run');

        $query = GroupPost::withoutMasjidScope()
            ->dueForPurge($before)
            ->when($narrowToMasjid, fn ($q) => $q->where('masjid_id', (int) $masjidId))
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
            ->when($narrowToMasjid, fn ($q) => $q->where('masjid_id', (int) $masjidId))
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
            ->when($narrowToMasjid, fn ($q) => $q->where('masjid_id', (int) $masjidId))
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

        // `hifz_entries` (T-014) is ABSENT FROM THIS SWEEP ON PURPOSE, and the
        // absence is a decision rather than an oversight — do not "finish the
        // job" by adding it. A feed post and a behaviour point describe a
        // moment; a memorisation record is an ACADEMIC record, the only evidence
        // of what a student has memorised, and a student's current position is
        // DERIVED from their sabak entries rather than stored. A sweep that
        // removed the newest sabak would therefore move a child backwards in the
        // mushaf without anyone touching their record. Those entries are bounded
        // by the ROSTER instead: the DB cascade off group_memberships takes them
        // with the student. See config/groups.php and .claude/rules/groups.md.

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

        // The scheduled run's only evidence. `schedule:run` throws stdout away,
        // so without this a sweep that silently found nothing for a month —
        // because a `retained_until` stopped being stamped, say — is
        // indistinguishable from one working perfectly. Always emitted, zeros
        // included, for exactly that reason.
        Log::info('Group retention sweep completed.', [
            'dry_run' => $dryRun,
            'before' => $before,
            'masjid_id' => $masjidId !== null ? (int) $masjidId : null,
            'posts' => $posts,
            'images' => $images,
            'threads' => $threads,
            'messages' => $messages,
            'behavior_awards' => $awards,
        ]);

        return self::SUCCESS;
    }
}
