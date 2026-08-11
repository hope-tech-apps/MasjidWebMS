<?php

namespace App\Services\Broadcast;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastChannel;
use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The unified publish composer (T-008).
 *
 * One payload in — title, body, optional link and image, a display window, an
 * audience, a set of channels and either "now" or a time — and one `Broadcast`
 * out, carrying a pending delivery row per selected channel. Actually reaching
 * the channels is BroadcastDispatcher's job; keeping the two apart is what lets
 * a scheduled send be composed today and fanned out later by a queued job with
 * no duplicated logic.
 *
 * ## What is atomic and what is not
 *
 * Composition IS atomic: the broadcast row and its delivery rows are written in
 * one transaction, because nothing has left the building yet and half a record
 * helps nobody. The FAN-OUT is emphatically not — see the long note on
 * BroadcastDispatcher explaining why rolling a delivered push back would be the
 * worst available outcome.
 *
 * ## Scheduling
 *
 * A future `scheduled_at` becomes a DELAYED QUEUE JOB and nothing more. There is
 * no scheduler table, no cron sweep and no second source of truth about when
 * something goes out — Laravel's queue already provides delayed dispatch, and
 * anything beyond that is infrastructure this slice deliberately does not build.
 * A send with no `scheduled_at` (or one in the past) goes immediately.
 */
class BroadcastComposer
{
    public function __construct(private readonly BroadcastDispatcher $dispatcher)
    {
    }

    /**
     * Compose a broadcast and its pending per-channel deliveries.
     *
     * @param  array<string, mixed>  $attributes  Validated payload.
     * @param  array<int, BroadcastChannel>  $channels  Channels opted in for this send.
     */
    public function compose(
        Masjid $masjid,
        array $attributes,
        array $channels,
        ?UploadedFile $image = null,
        ?int $authorId = null,
    ): Broadcast {
        $audience = BroadcastAudience::tryFrom((string) ($attributes['audience'] ?? '')) ?? BroadcastAudience::EVERYONE;

        $scheduledAt = ! empty($attributes['scheduled_at'])
            ? Carbon::parse($attributes['scheduled_at'])
            : null;

        $broadcast = DB::transaction(function () use ($masjid, $attributes, $channels, $audience, $scheduledAt, $authorId): Broadcast {
            $broadcast = Broadcast::create([
                // Explicit for the unbound caller (a console command, a system
                // job); the BelongsToMasjid creating hook overrides it with the
                // bound tenant on an admin request, which is the guardrail.
                'masjid_id' => $masjid->id,
                'created_by_user_id' => $authorId,
                'title' => $attributes['title'],
                'body' => $attributes['body'],
                'link' => $attributes['link'] ?? null,
                'starts_on' => $attributes['starts_on'] ?? null,
                'ends_on' => $attributes['ends_on'] ?? null,
                'audience' => $audience->value,
                // Only stored for the explicit-set audience. Snapshotted, so a
                // later edit to the directory cannot rewrite who was addressed.
                'audience_contact_ids' => $audience === BroadcastAudience::CONTACTS
                    ? array_values(array_unique(array_map('intval', (array) ($attributes['contact_ids'] ?? []))))
                    : null,
                'scheduled_at' => $scheduledAt,
                'status' => $this->isFuture($scheduledAt) ? Broadcast::STATUS_SCHEDULED : Broadcast::STATUS_PENDING,
            ]);

            foreach ($channels as $channel) {
                $broadcast->deliveries()->create([
                    'masjid_id' => $masjid->id,
                    'channel' => $channel->value,
                    'status' => BroadcastDelivery::STATUS_PENDING,
                ]);
            }

            return $broadcast;
        });

        // Media is attached AFTER the transaction on purpose: spatie writes bytes
        // to disk, and a rolled-back transaction cannot unwrite a file. Doing it
        // outside keeps the failure mode "a record with no image" rather than
        // "an orphaned file with no record".
        if ($image) {
            $broadcast->addMedia($image)->toMediaCollection(Broadcast::MEDIA_COLLECTION);
            $broadcast->refresh();
        }

        return $broadcast;
    }

    /**
     * Compose, then either send now or hand a delayed job to the queue.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, BroadcastChannel>  $channels
     */
    public function send(
        Masjid $masjid,
        array $attributes,
        array $channels,
        ?UploadedFile $image = null,
        ?int $authorId = null,
    ): Broadcast {
        $broadcast = $this->compose($masjid, $attributes, $channels, $image, $authorId);

        if ($this->isFuture($broadcast->scheduled_at)) {
            SendBroadcastJob::dispatch($broadcast->id)->delay($broadcast->scheduled_at);

            return $broadcast;
        }

        return $this->dispatcher->dispatch($broadcast);
    }

    private function isFuture(?Carbon $at): bool
    {
        return $at !== null && $at->isFuture();
    }
}
