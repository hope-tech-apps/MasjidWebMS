<?php

namespace App\Jobs;

use App\Support\Renderer\RendererCachePurge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The second pass of a save's cache purge, FOLLOW_UP_SECONDS after the first.
 *
 * WHY A SECOND PASS. The renderer finds an organisation's cached pages by LISTING its
 * KV namespace, and Cloudflare KV is eventually consistent: a page a visitor warmed in
 * another region a few seconds before the save is not yet in the listing the purge
 * reads, so the first pass cannot delete it and it would be served for its whole cache
 * window (5 minutes). Measured on staging, 2026-09-24: a key written seconds before a
 * save survived the immediate purge; a key older than a minute was purged. By the time
 * this runs, every entry written before the save is listable. Entries written after the
 * save are fresh, and deleting them costs one cold render.
 *
 * Unique per organisation for its delay, so a burst of saves (a section reorder is one
 * request per section) queues one follow-up, not one per request.
 */
class PurgeRendererCacheAgain implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FOLLOW_UP_SECONDS = 75;

    public int $tries = 1;

    public int $uniqueFor = self::FOLLOW_UP_SECONDS;

    public function __construct(public int $organisationId)
    {
    }

    public function uniqueId(): string
    {
        return 'renderer-purge-'.$this->organisationId;
    }

    public function handle(RendererCachePurge $purge): void
    {
        $purge->purge($this->organisationId);
    }
}
