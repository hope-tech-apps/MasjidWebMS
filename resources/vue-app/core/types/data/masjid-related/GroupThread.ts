/**
 * Group messaging threads — the teacher <-> guardian channel.
 *
 * The thread's `scope` IS its disclosure shape, decided server-side and never
 * re-derived here:
 *   - `group`      — the feed audience (leaders, members, consented guardians);
 *   - `participant`— the group's leaders plus the ONE member/guardian it
 *                    concerns, named by `about`.
 *
 * Text only. Attachments are deliberately deferred; the feed owns media.
 */

import { GroupContact } from "./Group";

/** Mirrors `GroupThread::SCOPES`. An unrecognized stored scope fails closed to participant. */
export type ThreadScope = 'group' | 'participant';

/** Whom a participant-scoped thread concerns. Null on a group-wide thread. */
export type ThreadSubject = {
    membership_id: number;
    /** Null when the target has since left the roster — the record stays honest about having a subject. */
    contact: GroupContact | null;
};

export type GroupThread = {
    id: number;
    group_id: number;
    subject: string;
    scope: ThreadScope;
    about: ThreadSubject | null;
    created_by: { id: number; name: string } | null;
    is_closed: boolean;
    closed_at: string | null;
    retained_until: string | null;
    message_count: number;
    latest_message_at: string | null;
    last_read_at: string | null;
    /** A bookmark comparison, never an authorization record. */
    unread: boolean;
    created_at: string | null;
    updated_at: string | null;
};

/**
 * An attachment sent with a message. A PHOTO is fetched through `download_path`
 * with the token; a VIDEO is played from a ticket minted at
 * `playback_ticket_path`. Branch on `is_video`.
 */
export type GroupMessageAttachment = {
    id: number;
    file_name: string;
    mime_type: string;
    size_bytes: number;
    download_path: string;
    /**
     * Whether these bytes are a VIDEO, stated by the server from the stored
     * `mime_type` rather than re-derived per client. A video must NOT be
     * rendered by <img> (a silent broken image) and must NOT be blob-fetched
     * through `download_path` (100MB behind a bearer token, which is what the
     * playback ticket exists to avoid).
     */
    is_video?: boolean;
    /**
     * Where to POST for a short-lived playback ticket. Present for video only,
     * null for a photograph. It is a path to ASK for a signed URL, never a
     * signed URL itself — a playable link in a list payload would start its
     * clock when the page rendered rather than when somebody pressed play.
     */
    playback_ticket_path?: string | null;
    /** The attachment's OWN retention date (video only); null means it dies with its parent. */
    retained_until?: string | null;
};

export type GroupMessage = {
    id: number;
    thread_id: number;
    /** Empty when the message is photos only. */
    body: string;
    author: { id?: number; name: string } | null;
    author_is_parent?: boolean;
    /** Omitted (empty) for a reader who may not have them; see media_withheld. */
    attachments?: GroupMessageAttachment[];
    media_withheld?: boolean;
    is_mine?: boolean;
    /** All four reactions, always, in catalogue order. */
    reactions?: GroupMessageReaction[];
    /** Who has read this message (never its author, never the viewer). */
    read_by?: GroupMessageReader[];
    created_at: string | null;
};

/** 🤲 👍 💯 ❓ — the fixed set, `GroupMessageReaction::REACTIONS`. */
export type GroupMessageReaction = {
    key: 'ameen' | 'thumbs_up' | 'hundred' | 'question' | string;
    emoji: string;
    count: number;
    mine: boolean;
    /** Everyone the viewer may be shown by name, excluding the viewer. */
    by: GroupMessageReader[];
};

export type GroupMessageReader = { name: string; is_parent: boolean };

/** Shape submitted when opening a thread, optionally with its first message. */
export type GroupThreadPayload = {
    subject: string;
    scope: ThreadScope;
    /** Required for a participant thread, prohibited on a group-wide one. */
    about_membership_id: number | null;
    body: string;
};

/** `meta` on the thread endpoints. */
export type GroupThreadsMeta = {
    group_label: string;
    thread_scopes: ThreadScope[];
    max_message_length: number;
    /**
     * The server's own field names and ceilings for attachments. Typed here
     * because the office compose boxes build their multipart body and their
     * `accept` attribute from them rather than from literals — a client that
     * guesses `images[]` drifts silently from GroupPostFormRequest::UPLOAD_KEY.
     */
    upload_key?: string;
    accepted_image_types?: string[];
    max_image_size_kb?: number;
    max_images_per_message?: number;
    /** ADDITIVE video constraints — see GroupFeedMeta for why they are separate. */
    video_upload_key?: string;
    accepted_video_types?: string[];
    max_video_size_kb?: number;
    max_videos_per_message?: number;
    video_retention_days?: number;
};
