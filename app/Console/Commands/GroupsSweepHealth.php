<?php

namespace App\Console\Commands;

use App\Models\GroupMessageSchedule;
use App\Models\GroupPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `groups:sweep-health`: is `groups:publish-due` actually getting scheduled items out?
 *
 * Every ten minutes (routes/console.php, withoutOverlapping). It is a SEPARATE command on
 * purpose: the staleness check used to live inside the sweep, so a sweep that was dead,
 * wedged on its mutex or crashing before the check could never report itself (the point's
 * W5/W6 delta review, P5). This one asks the database directly and shares nothing with the
 * sweep's code path but the two tables it empties.
 *
 * "Stuck" is a story nobody has announced (not announced, not refused, not deleted) or a
 * conversation still `scheduled` OR `sending` whose time passed more than ten minutes ago. Ten
 * minutes is ten missed sweeps. A `sending` row IS counted: a legitimate claim lasts
 * milliseconds, but a failure in the sweep's gate phase (before its try) leaves the row
 * `sending`, the stale-claim handback returns it after ten minutes and the same run claims it
 * again, so it is `sending` whenever this command looks and only this count can see it. A
 * refused story and a failed conversation are not stuck: somebody decided that, and the author
 * sees it.
 *
 * What this cannot see: if the scheduler itself (the cron that runs `schedule:run`) has
 * stopped, this command stops with the sweep, and silence is all there is. The signal for
 * that is the absence of the `monitors` info lines, which both commands write every run
 * (deploy/README.md, "Rolling back scheduled stories and conversations").
 *
 * Stuck: one ERROR on the `monitors` channel AND one on the default channel (production's
 * LOG_LEVEL=warning keeps errors there, and the monitors channel is what on-call is wired
 * to), carrying the COUNTS ONLY, never a title, a body or a name. Not stuck: one info line
 * on `monitors`, which is the proof this command ran.
 */
class GroupsSweepHealth extends Command
{
    /** Ten missed sweeps. */
    private const STUCK_AFTER_MINUTES = 10;

    protected $signature = 'groups:sweep-health';

    protected $description = 'Report scheduled stories and conversations that are more than ten minutes past their time and still not out';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(self::STUCK_AFTER_MINUTES);

        // Soft-deleted stories are excluded by the model's own scope: a cancelled story is not stuck.
        $stories = GroupPost::withoutMasjidScope()
            ->whereNull('announced_at')
            ->whereNull('publish_failed_at')
            ->where('published_at', '<=', $cutoff)
            ->count();

        $conversations = GroupMessageSchedule::withoutMasjidScope()
            ->whereIn('status', [GroupMessageSchedule::STATUS_SCHEDULED, GroupMessageSchedule::STATUS_SENDING])
            ->where('send_at', '<=', $cutoff)
            ->count();

        if ($stories + $conversations > 0) {
            $line = sprintf(
                'groups:sweep-health: %d scheduled stories and %d scheduled conversations are more than %d minutes past their time and still not out',
                $stories, $conversations, self::STUCK_AFTER_MINUTES
            );

            Log::channel('monitors')->error($line);
            Log::error($line);
            $this->error($line);

            return self::SUCCESS;
        }

        $line = 'groups:sweep-health: nothing is more than '.self::STUCK_AFTER_MINUTES.' minutes past its time';
        Log::channel('monitors')->info($line);
        $this->info($line);

        return self::SUCCESS;
    }
}
