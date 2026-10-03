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
 * did not, and inventing a fifth content table for the board would have been
 * the wrong answer: the broadcast row already IS the notice.
 *
 * Signage is therefore a PULL channel. `GET /api/mobile/masjids/{id}/signage`
 * selects broadcasts whose signage delivery succeeded and whose display window
 * is open (Broadcast::scopeLiveOnSignage). There is no push, no device list,
 * and therefore no target count. Zero here is correct, not a failure.
 *
 * ## No TV app reads that address (found 2026-10-03)
 *
 * This channel was built on a misreading. The endpoint the tvOS app asked for
 * and never got was `/tv-config`; it has never asked for `/signage`. Its slides
 * come from `/announcements` (ios MasjidKit `MasjidEndpoint`, five cases, none
 * of them signage; `MasjidTV/Data/SignageStore.swift`), in the released build
 * and on iOS main alike. So a notice sent here alone was on no screen while the
 * composer said "Sent".
 *
 * The composer no longer offers the channel. The API still accepts it, for a
 * browser holding the older page, and the note below says what happened in
 * words that are true. `sent` still means only "published at that address".
 * Before offering the channel again, a TV build has to read it, and a notice
 * needs a way to come down: broadcasts have no delete, and the end date is
 * optional (DECISIONS.md 2026-10-03).
 */
class SignageChannel implements BroadcastChannelDriver
{
    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::SIGNAGE;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        // The public endpoint caches its payload the way every other mobile
        // read does; a newly published notice must be in the next answer, not
        // in five minutes.
        MobileCache::flushMasjid((int) $masjid->id, MobileCache::SIGNAGE);

        $window = $broadcast->ends_on
            ? 'until ' . $broadcast->ends_on->toDateString()
            : 'until it is removed';

        return ChannelResult::sent(
            targetCount: 0,
            referenceId: $broadcast->id,
            note: 'Stored for the lobby screen ' . $window . ', but the TV app does not read this channel, so it is not on the screen. Post it to the announcements feed to show it there.',
        );
    }
}
