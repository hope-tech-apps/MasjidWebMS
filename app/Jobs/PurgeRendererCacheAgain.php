<?php

namespace App\Jobs;

use App\Support\Renderer\RendererCachePurge;
use App\Support\Renderer\RendererPurgeScheduler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The second purge pass, FOLLOW_UP_SECONDS after the organisation's LAST save.
 *
 * WHY A SECOND PASS. The renderer finds an organisation's cached pages by listing its KV
 * namespace, and Cloudflare KV's list is eventually consistent: a page a visitor warmed in
 * another region a few seconds before a save is not yet in the listing the first pass
 * reads (measured on staging, 2026-09-24). By FOLLOW_UP_SECONDS after the save it is.
 *
 * WHY IT TRAILS THE LAST SAVE. One job per organisation is queued (ShouldBeUnique, lock held
 * until it finishes); a later save only moves the recorded last-save time. When the job
 * runs early, it releases itself back to the queue until FOLLOW_UP_SECONDS after that last
 * save. A release keeps the unique lock (Laravel frees it only when the job finishes), so a
 * burst still ends in exactly one second pass — after its final save, never before it.
 */
class PurgeRendererCacheAgain implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FOLLOW_UP_SECONDS = 75;

    /** Longer than any editing session keeps it re-arming; see retryUntil(). */
    public int $uniqueFor = 1800;

    public function __construct(public int $organisationId)
    {
    }

    public function uniqueId(): string
    {
        return 'renderer-purge-again-'.$this->organisationId;
    }

    /** Releases count as attempts, so the job is bounded by time, not tries. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->uniqueFor);
    }

    public function handle(RendererCachePurge $purge): void
    {
        $lastSave = RendererPurgeScheduler::lastSaveAt($this->organisationId);
        $wait = ($lastSave ?? 0) + self::FOLLOW_UP_SECONDS - now()->getTimestamp();
        if ($wait > 0) {
            $this->release($wait);

            return;
        }

        $purge->purge($this->organisationId);
    }
}
