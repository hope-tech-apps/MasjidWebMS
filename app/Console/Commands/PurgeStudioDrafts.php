<?php

namespace App\Console\Commands;

use App\Models\StudioDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention sweep for Manara Studio drafts (config/studio.php,
 * `drafts.retention_days`).
 *
 * A draft holds the client admin's name, email and phone, and its logo sits on
 * the private disk, which the backups do not cover (.claude/rules/backups.md).
 * An onboarding abandoned in March must not keep that on the server for ever,
 * so a draft nobody has touched for the window is deleted.
 *
 * Only `draft` rows. A provisioned draft is the record of what Step 3 created
 * and is kept. Every delete goes through the model, one row at a time, so
 * StudioDraft's `deleting` hook removes the logo bytes; a query-level delete
 * would leave them on disk with nothing pointing at them.
 *
 * A draft has no tenant, so there is no scope to bypass. Counts go to the log as
 * well as stdout because `schedule:run` discards stdout, and a sweep that
 * silently stopped finding rows must not look like one that never ran. The line
 * is a warning, as app:legacy-features-report's is, because production runs
 * LOG_LEVEL=warning and an info line would be written and dropped.
 *
 * SCHEDULED DAILY in routes/console.php.
 */
class PurgeStudioDrafts extends Command
{
    protected $signature = 'studio:purge-drafts
                            {--days= : Delete drafts untouched for this many days (default: studio.drafts.retention_days)}
                            {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Delete Manara Studio drafts, and their logos, that nobody has touched within the retention window.';

    public function handle(): int
    {
        // One day is the tightest window accepted: zero would delete a draft
        // somebody is editing right now.
        $days = max(1, (int) ($this->option('days') ?: config('studio.drafts.retention_days', 90)));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = StudioDraft::query()
            ->where('status', StudioDraft::STATUS_DRAFT)
            ->where('updated_at', '<', $cutoff);

        $count = 0;

        if ($dryRun) {
            $count = $query->count();
        } else {
            $query->chunkById(100, function ($drafts) use (&$count) {
                foreach ($drafts as $draft) {
                    $draft->delete();
                    $count++;
                }
            });
        }

        $summary = sprintf(
            '%s %d Studio draft(s) untouched since %s.',
            $dryRun ? 'Would purge' : 'Purged',
            $count,
            $cutoff->toDateTimeString(),
        );

        $this->info($summary);

        Log::warning($summary, [
            'command' => 'studio:purge-drafts',
            'deleted' => $dryRun ? 0 : $count,
            'days' => $days,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
