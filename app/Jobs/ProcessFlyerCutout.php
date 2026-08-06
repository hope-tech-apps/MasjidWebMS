<?php

namespace App\Jobs;

use App\Models\Flyer;
use App\Services\Flyer\ImageCutout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Removes the background from a flyer's uploaded photo, off the request path, and
 * writes the outcome onto the flyer row (`cutout_status`, `cutout_path`,
 * `cutout_error`).
 *
 * This MUST stay queued — dispatch it, never `dispatchSync()`, and never call
 * ImageCutout from a controller. One cutout is ~3.2s of the production droplet's
 * ONLY vCPU at ~354MB RSS, on a box that is also running MySQL and PHP-FPM; done
 * inline it would stall the prayer-time API that three masjids' apps poll.
 * REQUIRES the running queue worker (systemd `masjid-queue.service`).
 *
 * The caller sets `cutout_status` to 'queued' when it dispatches, so the Studio
 * shows a spinner from the moment of upload rather than from the moment a worker
 * happens to pick the job up:
 *
 *     $flyer->update(['cutout_status' => 'queued', 'cutout_error' => null]);
 *     ProcessFlyerCutout::dispatch($flyer->id);
 */
class ProcessFlyerCutout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Mirrors the `cutout_status` enum — see Flyer::CUTOUT_STATUSES. */
    public const STATUS_NONE = 'none';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /**
     * Two attempts, not the default. Most cutout failures are a property of the
     * picture (no subject, no contrast) and will fail identically forever, so a
     * retry loop just burns the vCPU we are protecting. The one retry exists for
     * the environmental case — a run that timed out while the box was swapping.
     */
    public int $tries = 2;

    /**
     * Overridden from config in the constructor (see resolveTimeout); declared so
     * it is a real property. 80 is what the shipped config resolves to — kept off
     * 90 so the declaration itself never states the value that collides with the
     * database queue's default retry_after.
     */
    public int $timeout = 80;

    public function __construct(
        public int $flyerId,
        public ?string $disk = null,
    ) {
        // 'local' (storage/app/private), never 'public' — flyer photos are not
        // world-readable by URL. See config/flyer.php.
        $this->disk ??= (string) config('flyer.cutout.disk', 'local');

        $this->timeout = $this->resolveTimeout();
    }

    /**
     * Two ceilings squeeze this from both sides, and getting it wrong in either
     * direction has a cost:
     *
     *  - It must sit ABOVE ImageCutout's own process timeout, so a wedged python
     *    is killed by the service (which yields a reason the admin can read)
     *    rather than by the worker (which yields none).
     *  - It must sit STRICTLY BELOW the queue connection's retry_after. Laravel
     *    requires that; at equality — which +30 on the shipped 60 hit exactly,
     *    against a database queue whose retry_after defaults to 90 — the queue
     *    can hand the job to a second worker at the very moment the first is
     *    still running it. Two concurrent 354MB inferences on a 1 vCPU box with
     *    2GB of RAM is how MySQL gets OOM-killed.
     *
     * Both are read from config rather than assumed, so raising
     * FLYER_CUTOUT_TIMEOUT or DB_QUEUE_RETRY_AFTER cannot silently recreate the
     * overlap. When the two cannot both be honoured (a process ceiling that
     * already exceeds retry_after), the queue's constraint wins: a job killed
     * without a pretty message beats a job running twice.
     */
    private function resolveTimeout(): int
    {
        $process = max(1, (int) config('flyer.cutout.timeout', 60));
        $timeout = $process + 20;

        $connection = (string) config('queue.default', 'database');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);

        // 0/absent means the driver has no visibility window of this kind (sqs,
        // sync), so there is nothing to stay under.
        if ($retryAfter > 0 && $timeout >= $retryAfter) {
            $timeout = $retryAfter - 10;
        }

        return max(1, $timeout);
    }

    /**
     * A full minute before the second attempt: whatever memory pressure caused
     * an environmental failure needs time to clear, and nobody is watching a
     * flyer render in real time.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(ImageCutout $cutout): void
    {
        // Resolved by id rather than an injected model: the worker runs with no
        // bound TenantContext (unbound = unscoped, per .claude/rules/tenant-scoping.md),
        // and re-reading here means a flyer deleted between dispatch and pickup
        // is a no-op instead of a ModelNotFoundException. Re-reading also picks up
        // a photo swapped after dispatch, so the cutout matches what is on the row.
        $flyer = Flyer::find($this->flyerId);

        if (! $flyer) {
            return;
        }

        $source = $flyer->source_image_path;

        // 'none' is the schema's resting state for a flyer with no photo, which is
        // exactly what this is — not a failure the admin needs to see.
        if (! $source) {
            $this->record($flyer, self::STATUS_NONE, null, null);

            return;
        }

        $disk = Storage::disk((string) $this->disk);

        // Idempotency. A redelivered job — worker restart mid-run, a retry that
        // fired after the write landed — must not spend another 3.2s of CPU or
        // replace a cutout that is already good.
        if ($flyer->cutout_status === self::STATUS_DONE
            && $flyer->cutout_path
            && $disk->exists($flyer->cutout_path)) {
            return;
        }

        if (! $disk->exists($source)) {
            $this->record($flyer, self::STATUS_FAILED, null, 'The uploaded image is no longer on the server.');

            return;
        }

        $this->record($flyer, self::STATUS_PROCESSING, null, null);

        $destination = $this->destinationPath($source);

        $result = $cutout->run($disk->path($source), $disk->path($destination));

        if ($result['ok']) {
            // Deliberately not a failure: a frame-filling subject reports this
            // legitimately and ImageCutout explains why nothing can tell it apart
            // from a background that was never removed. Logged so that if admins
            // ever do start reporting uncut flyers, there is a trail to count.
            if ($result['meta']['coverage_high'] ?? false) {
                Log::info('Flyer cutout kept the whole frame', [
                    'flyer_id' => $flyer->id,
                    'coverage' => $result['meta']['coverage'] ?? null,
                ]);
            }

            $this->record($flyer, self::STATUS_DONE, $destination, null);

            return;
        }

        Log::warning('Flyer cutout failed', [
            'flyer_id' => $flyer->id,
            'attempt' => $this->attempts(),
            'reason' => $result['reason'],
            'meta' => $result['meta'],
        ]);

        if (($result['meta']['retryable'] ?? false) && $this->attempts() < $this->tries) {
            // Environmental, so it is worth exactly one more go. Left queued
            // rather than failed: the Studio should keep showing a spinner, not an
            // error we are about to retract a minute from now.
            $this->record($flyer, self::STATUS_QUEUED, null, null);

            throw new RuntimeException((string) $result['reason']);
        }

        $this->record($flyer, self::STATUS_FAILED, null, $result['reason']);
    }

    /**
     * Reached when retries are exhausted or the worker died mid-job. Without
     * this, such a flyer would sit in 'processing' forever and the Studio would
     * spin on it indefinitely.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessFlyerCutout failed permanently', [
            'flyer_id' => $this->flyerId,
            'error' => $exception?->getMessage(),
        ]);

        $flyer = Flyer::find($this->flyerId);

        if (! $flyer) {
            return;
        }

        $this->record(
            $flyer,
            self::STATUS_FAILED,
            null,
            $exception?->getMessage() ?: 'Background removal could not be completed.',
        );
    }

    /**
     * Deterministic name, keyed on the flyer AND its source: a redelivered job
     * overwrites its own output instead of littering the disk, while a flyer
     * given a NEW photo cannot quietly keep serving the old cutout's URL.
     */
    private function destinationPath(string $source): string
    {
        $directory = trim((string) config('flyer.cutout.directory', 'flyers/cutouts'), '/');
        $fingerprint = substr(sha1($this->flyerId . '|' . $source), 0, 12);

        return "{$directory}/{$this->flyerId}-{$fingerprint}.png";
    }

    private function record(Flyer $flyer, string $status, ?string $path, ?string $error): void
    {
        // Assigned directly rather than through fill(), so this keeps working if
        // the cutout columns ever leave $fillable.
        $flyer->cutout_status = $status;
        $flyer->cutout_path = $path;
        // cutout_error is TEXT, so this cap is for the admin reading it, not the
        // column — a Python traceback pasted into the Studio helps nobody.
        $flyer->cutout_error = $error === null ? null : Str::limit($error, 480);
        $flyer->save();
    }
}
