<?php

namespace App\Console\Commands;

use App\Models\ContentTranslation;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention sweep for the parent portal's translation cache
 * (config/translation.php, `cache_days`).
 *
 * ## Why a cache needs a sweeper at all
 *
 * Because of what is in it. `content_translations` holds no English — the
 * migration explains why — but every row is the Arabic of something a teacher
 * wrote about somebody's child: a note in a class story, a message about a
 * child's week, a report-card remark. A row here is therefore the SAME KIND of
 * record as the `group_posts` row it came from, and `.claude/rules/groups.md`
 * requires those to be bounded rather than kept forever. `groups:purge-feed`
 * deletes the post; without this command its translation would outlive it in a
 * side table nobody thinks of as holding children's data, which is exactly how
 * a retention policy comes to be true of the main tables and false of the
 * system.
 *
 * That the copy is a derived one and lives in a table called a cache changes
 * nothing about who it describes. "It is only a cache" is the sentence that
 * leaves a term's worth of teachers' notes on disk for years.
 *
 * ## Disuse, not age
 *
 * The window runs from `last_used_at` — stamped on every cache HIT — and falls
 * back to `updated_at` for a row nobody ever came back for. A post families
 * re-read every week keeps its translation; a post nobody has opened since
 * March loses it, and if somebody does open it the next tap simply pays for the
 * call again. Nothing depends on these rows for correctness: the English is
 * still in the record, and a missing translation is a cache miss rather than a
 * hole in anyone's history. That is what makes deleting them safe in a way
 * deleting `hifz_entries` would not be (see PurgeGroupFeed, which excludes those
 * on purpose).
 *
 * ## Unbound, idempotent, and it logs
 *
 * Runs UNBOUND (a console request binds no tenant) and says so explicitly with
 * `runWithout()` rather than relying on the process happening to have bound
 * nothing — .claude/rules/tenant-scoping.md — so it is still correct if it is
 * ever invoked from inside a bound request. `--masjid=` narrows it to one
 * organisation, with the `!== null` test PurgeGroupFeed learned the hard way:
 * `--masjid=0` is a FALSY string, and a truthiness check there silently widens a
 * deleting command to every tenant.
 *
 * A deleted row is gone from the table, so a second run in the same window finds
 * nothing and reports zero; a run that dies half way leaves the rest still due.
 * Counts go to the LOG as well as stdout because `schedule:run` discards stdout,
 * and a sweep that silently stopped finding rows must not look like one that
 * never ran.
 *
 * SCHEDULED DAILY in routes/console.php, beside the other retention sweeps.
 */
class PurgeContentTranslations extends Command
{
    protected $signature = 'translations:purge
                            {--days= : Delete translations unused for this many days (default: translation.cache_days)}
                            {--masjid= : Limit the sweep to one organization}
                            {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Delete cached parent-portal translations that have not been served within the retention window.';

    public function handle(TenantContext $tenant): int
    {
        $days = (int) ($this->option('days') ?: config('translation.cache_days', 180));

        // A zero or negative window would mean "delete everything, including what
        // was served a second ago", which is a plausible typo on a command that
        // deletes. One day is the tightest the sweep will accept.
        $days = max(1, $days);

        $cutoff = now()->subDays($days);
        $masjidId = $this->option('masjid');
        $narrowToMasjid = $masjidId !== null;
        $dryRun = (bool) $this->option('dry-run');

        $deleted = $tenant->runWithout(function () use ($cutoff, $narrowToMasjid, $masjidId, $dryRun): int {
            $query = ContentTranslation::query()
                ->unusedSince($cutoff->toDateTimeString())
                ->when($narrowToMasjid, fn ($q) => $q->where('masjid_id', (int) $masjidId));

            // Counted before deleting so a dry run reports the number the real
            // run would delete, rather than the number it would have deleted if
            // it had also deleted.
            return $dryRun ? $query->count() : $query->delete();
        });

        $summary = sprintf(
            '%s %d cached translation(s) unused since %s%s.',
            $dryRun ? 'Would purge' : 'Purged',
            $deleted,
            $cutoff->toDateTimeString(),
            $narrowToMasjid ? " for masjid {$masjidId}" : ''
        );

        $this->info($summary);

        // The scheduled run's only evidence — stdout goes nowhere under
        // `schedule:run`. Always emitted, zeros included.
        Log::info($summary, [
            'command' => 'translations:purge',
            'deleted' => $deleted,
            'days' => $days,
            'dry_run' => $dryRun,
            'masjid_id' => $narrowToMasjid ? (int) $masjidId : null,
        ]);

        return self::SUCCESS;
    }
}
