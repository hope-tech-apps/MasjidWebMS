<?php

namespace App\Services\Broadcast;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastChannel;
use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use App\Services\Broadcast\Newsletter\NewsletterBlocks;
use App\Services\Broadcast\Newsletter\NewsletterPicture;
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
     * @param  array<string, mixed>  $attributes  Validated payload; `blocks` is the
     *                                            normalised newsletter layout, if any.
     * @param  array<int, BroadcastChannel>  $channels  Channels opted in for this send.
     * @param  array<string, UploadedFile>  $blockImages  The layout's pictures, by upload key.
     */
    public function compose(
        Masjid $masjid,
        array $attributes,
        array $channels,
        ?UploadedFile $image = null,
        ?int $authorId = null,
        array $blockImages = [],
    ): Broadcast {
        $audience = BroadcastAudience::tryFrom((string) ($attributes['audience'] ?? '')) ?? BroadcastAudience::EVERYONE;

        $scheduledAt = ! empty($attributes['scheduled_at'])
            ? Carbon::parse($attributes['scheduled_at'])
            : null;

        // The newsletter's pictures are re-encoded BEFORE anything is written
        // (NewsletterPicture: metadata stripped, resized, renamed), so a picture
        // that fails to decode leaves no broadcast behind. Only keys the stored
        // layout references are kept, so a stray upload never becomes an
        // orphaned public file.
        $pictures = [];
        if (! empty($attributes['blocks']) && $blockImages !== []) {
            $wanted = array_flip(NewsletterBlocks::imageKeys($attributes['blocks']));

            try {
                foreach ($blockImages as $key => $file) {
                    if (isset($wanted[$key])) {
                        $pictures[$key] = NewsletterPicture::prepare($file);
                    }
                }
            } catch (\Throwable $e) {
                foreach ($pictures as $picture) {
                    @unlink($picture['path']);
                }

                throw $e;
            }
        }

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
                // Written only when there is a layout, so a broadcast without
                // one is created with exactly the attributes it always had.
                ...(! empty($attributes['blocks']) ? ['blocks' => $attributes['blocks']] : []),
                'starts_on' => $attributes['starts_on'] ?? null,
                'ends_on' => $attributes['ends_on'] ?? null,
                'audience' => $audience->value,
                // Only stored for the explicit-set audience. Snapshotted, so a
                // later edit to the directory cannot rewrite who was addressed.
                'audience_contact_ids' => $audience === BroadcastAudience::CONTACTS
                    ? array_values(array_unique(array_map('intval', (array) ($attributes['contact_ids'] ?? []))))
                    : null,
                // The SERVICE is snapshotted; its people deliberately are not.
                // An interest is an opt-in, so the recipients are resolved at
                // send time and a withdrawal made between composing and
                // dispatching is honoured (BroadcastAudienceResolver).
                'audience_service_id' => $audience === BroadcastAudience::SERVICE
                    ? (int) ($attributes['service_id'] ?? 0) ?: null
                    : null,
                // The TAG is stored and its people are resolved at send time,
                // like a service: a scheduled send reaches whoever carries the
                // tag when it goes.
                'audience_tag_id' => $audience === BroadcastAudience::TAG
                    ? (int) ($attributes['tag_id'] ?? 0) ?: null
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

        // The newsletter's pictures follow the same rule, each tagged with the
        // key its blocks name. Spatie moves the prepared temporary file into the
        // media disk under its generated name.
        if ($pictures !== []) {
            foreach ($pictures as $key => $picture) {
                // (string): PHP turns a digits-only key such as "7" into an int.
                $broadcast->addMedia($picture['path'])
                    ->usingName((string) $key)
                    ->usingFileName($picture['name'])
                    ->withCustomProperties([Broadcast::BLOCK_KEY_PROPERTY => (string) $key])
                    ->toMediaCollection(Broadcast::BLOCK_MEDIA_COLLECTION);
            }

            $broadcast->refresh();
        }

        return $broadcast;
    }

    /**
     * Compose, then either send now or hand a delayed job to the queue.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, BroadcastChannel>  $channels
     * @param  array<string, UploadedFile>  $blockImages
     */
    public function send(
        Masjid $masjid,
        array $attributes,
        array $channels,
        ?UploadedFile $image = null,
        ?int $authorId = null,
        array $blockImages = [],
    ): Broadcast {
        $broadcast = $this->compose($masjid, $attributes, $channels, $image, $authorId, $blockImages);

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
