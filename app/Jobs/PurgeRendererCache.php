<?php

namespace App\Jobs;

use App\Support\Renderer\RendererCachePurge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The first purge pass after a burst of saves (RendererPurgeScheduler).
 *
 * Unique per organisation UNTIL IT STARTS: every save before it runs shares it, and a save
 * that lands while it is running queues the next one, so no save is left unpurged.
 */
class PurgeRendererCache implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** A safety net for a lock whose job never ran; it is normally released on start. */
    public int $uniqueFor = 60;

    public function __construct(public int $organisationId)
    {
    }

    public function uniqueId(): string
    {
        return 'renderer-purge-first-'.$this->organisationId;
    }

    public function handle(RendererCachePurge $purge): void
    {
        $purge->purge($this->organisationId);
    }
}
