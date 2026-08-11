<?php

namespace App\Services\Broadcast;

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\Masjid;

/**
 * One delivery channel of the unified composer (T-008).
 *
 * ## The contract
 *
 * - `deliver()` returns a ChannelResult on success or a skip, and THROWS on
 *   failure. There is no failure return value on purpose: see ChannelResult.
 * - A driver may assume the tenant is already bound to `$masjid` — the
 *   dispatcher binds it before calling, so BelongsToMasjid models scope
 *   themselves and every write is stamped with the right masjid.
 * - A driver must be independent. It may not read another channel's delivery
 *   row, and it must not care whether it ran first, last, or after a sibling
 *   blew up.
 *
 * ## The seam this interface exists for
 *
 * Adding a channel is: one case on App\Enums\BroadcastChannel, one class here,
 * one line in BroadcastDispatcher::DRIVERS. Nothing about the composer service,
 * the request, the endpoint, or the storage schema changes.
 *
 * SMS is the channel this seam was shaped around, and T-009 held it to exactly
 * those three steps. What SMS additionally needed — a consent record on
 * `contacts`, a suppression list that outlives a deleted contact, a per-tenant
 * A2P 10DLC sender identity and a signature-verified inbound webhook — lives
 * BENEATH the channel rather than inside it, which is why none of it appears in
 * this interface. A driver's job is still to deliver or throw; deciding who may
 * lawfully be delivered to belongs to the audience resolver
 * (.claude/rules/broadcasts.md).
 */
interface BroadcastChannelDriver
{
    /** Which channel this driver serves. */
    public function channel(): BroadcastChannel;

    /**
     * Deliver the composed message through this channel.
     *
     * @throws \Throwable when the channel could not accept the message.
     */
    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult;
}
