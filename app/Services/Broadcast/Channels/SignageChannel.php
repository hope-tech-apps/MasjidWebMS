<?php

namespace App\Services\Broadcast\Channels;

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\ChannelResult;
use App\Support\MobileCache;

/**
 * The tvOS signage channel (T-008).
 *
 * ## Why this one is different
 *
 * The other three channels write into a table that already had an owner. Signage
 * did not: `MasjidTV/Data/SignageStore.swift` fetches a board payload from an
 * endpoint that **has never existed** — docs/recon-2026-08-11.md records the
 * client falling back to `TVConfig.defaults` on every fetch failure. So there is
 * no legacy signage write path for this driver to call into, and inventing a
 * fifth content table for the board would have been the wrong answer: the
 * broadcast row already IS the notice.
 *
 * Signage is therefore a PULL channel. The board asks
 * `GET /api/mobile/masjids/{id}/signage` what to show, and that endpoint selects
 * broadcasts whose signage delivery succeeded and whose display window is open
 * (Broadcast::scopeLiveOnSignage). Marking this delivery `sent` is literally the
 * act of publishing to the board — there is no push, no device list, and
 * therefore no target count. Zero here is correct, not a failure.
 *
 * Consequently this driver almost cannot fail, and that is honest rather than
 * lazy: publishing to a pull surface is a local state change. What CAN fail is
 * the board's own fetch, which is a client-side and network concern that this
 * record must not pretend to know about.
 */
class SignageChannel implements BroadcastChannelDriver
{
    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::SIGNAGE;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        // The public board endpoint caches its payload the way every other
        // mobile read does; a newly published notice must appear on the next
        // fetch, not in five minutes.
        MobileCache::flushMasjid((int) $masjid->id, MobileCache::SIGNAGE);

        $window = $broadcast->ends_on
            ? 'until ' . $broadcast->ends_on->toDateString()
            : 'until it is removed';

        return ChannelResult::sent(
            targetCount: 0,
            referenceId: $broadcast->id,
            note: 'Live on the signage board ' . $window . '; the board pulls it on its next fetch.',
        );
    }
}
