import { Media } from "@/core/types/data/Media"

/** The five things one compose action can reach. */
export type BroadcastChannel = 'announcement' | 'push' | 'signage' | 'email' | 'sms'

/**
 * Who an addressable channel reaches.
 *
 * `service` is the interest-routed audience: everyone who asked to hear about
 * one service, on the phones they signed in on. It is NOT a list of people the
 * admin picks — the recipients are resolved by the server at send time, so a
 * member who withdrew their interest an hour ago is not reached.
 */
export type BroadcastAudience = 'everyone' | 'contacts' | 'service'

/** Per-channel outcome. `skipped` is a fact, not a failure. */
export type BroadcastDeliveryStatus = 'pending' | 'sent' | 'failed' | 'skipped'

export type BroadcastDelivery = {
    id: number;
    broadcast_id: number;
    channel: BroadcastChannel;
    status: BroadcastDeliveryStatus;
    target_count: number | null;
    note: string | null;
    error: string | null;
    dispatched_at: string | null;
    delivered_at: string | null;
}

/**
 * `partial` is a first-class state, not an error: the fan-out is deliberately
 * not transactional, because a push already on ten thousand lock screens cannot
 * be rolled back. The UI has to show it as its own outcome.
 */
export type BroadcastStatus = 'pending' | 'scheduled' | 'sent' | 'partial' | 'failed'

export type Broadcast = {
    id: number;
    masjid_id: number;
    title: string;
    body: string;
    link: string | null;
    audience: BroadcastAudience;
    audience_contact_ids: number[] | null;
    audience_service_id: number | null;
    status: BroadcastStatus;
    scheduled_at: string | null;
    created_at: string;
    deliveries?: BroadcastDelivery[];
    image?: Media | null;
}
