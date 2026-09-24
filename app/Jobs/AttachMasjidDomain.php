<?php

namespace App\Jobs;

use App\Models\MasjidDomain;
use App\Services\Domains\DomainAttacher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The first step of attaching a host, taken right after the row is written
 * (Manara Studio W1, S7), so a SuperAdmin who adds a domain does not wait up to
 * five minutes for the schedule to notice it.
 *
 * `$afterCommit`: the row is written inside a transaction (S8 writes it inside
 * the provision transaction), and a Cloudflare call made for a row that then
 * rolled back would create records nothing tracks. The queue driver is
 * `database` (config/queue.php), so a job queued inside a transaction that
 * rolls back would otherwise be rolled back with it anyway; the flag makes the
 * order explicit whatever the driver.
 *
 * `$tries = 1`: `domains:reconcile` is the retry. A second attempt of this job
 * would race the schedule for the same row for nothing (the attacher's lock
 * would make one of them a no-op).
 *
 * It carries the id, not the model: a row deleted before the job runs is simply
 * skipped.
 */
class AttachMasjidDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public int $masjidDomainId)
    {
        // Set here, not as a property default: Queueable declares $afterCommit
        // itself, and a redeclaration with a different default is a fatal
        // error when the traits are composed.
        $this->afterCommit = true;
    }

    public function handle(DomainAttacher $attacher): void
    {
        $domain = MasjidDomain::find($this->masjidDomainId);

        if ($domain === null) {
            return;
        }

        $attacher->advance($domain);
    }
}
