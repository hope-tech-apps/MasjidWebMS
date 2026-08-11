<?php

namespace App\Console\Commands;

use App\Models\GroupPost;
use Illuminate\Console\Command;

/**
 * Retention purge for group feeds (PLAN T-005b).
 *
 * .claude/rules/groups.md, obligation 3: retention is "a nullable
 * `retained_until` plus a purge that reaches the disk (a DB cascade fires no
 * model events, so it orphans bytes forever)". This is that purge.
 *
 * It force-deletes every post whose retention window has closed — soft-deleted
 * ones included, because a post an admin removed last month is exactly the one
 * that should not linger — THROUGH THE MODEL, so GroupPost's `deleting` hook
 * removes the attachment rows and each attachment's own hook removes the bytes.
 * A `DELETE FROM group_posts WHERE ...` would be faster and would leave every
 * photograph on disk forever.
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
                            {--before= : Purge posts retained only until this date (default: today)}
                            {--masjid= : Limit the sweep to one organization}
                            {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Force-delete group feed posts past their retention date, including their images on the private disk.';

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

        $this->info(sprintf(
            '%s %d post(s) and %d image(s)%s.',
            $dryRun ? 'Would purge' : 'Purged',
            $posts,
            $images,
            $masjidId ? " for masjid {$masjidId}" : ''
        ));

        return self::SUCCESS;
    }
}
