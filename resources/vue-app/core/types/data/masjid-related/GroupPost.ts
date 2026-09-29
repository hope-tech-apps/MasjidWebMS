/**
 * The group's PRIVATE activity feed — the "class story".
 *
 * The one thing a consumer must not get wrong: an image here has NO public URL.
 * The bytes sit on the private disk and the only way to read one is the
 * authenticated `download_path` below, fetched with the bearer token. A naive
 * `<img src>` would 401, and that is the point — see
 * .claude/rules/private-uploads.md.
 */

/** One image on a post, as `GroupPostAttachment::toAudienceArray()` serves it. */
export type GroupPostAttachment = {
    id: number;
    file_name: string;
    mime_type: string;
    size_bytes: number;
    uploaded_at: string | null;
    /**
     * The ONLY link that exists for one of these: back at the authenticated
     * endpoint. Fetch it through axios (which carries Authorization), turn the
     * blob into an object URL, and render that.
     */
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

/**
 * One of the four reactions on a post, as `App\Support\GroupPostSignals` serves
 * it: always all four, in order. The server has already decided whose names THIS
 * viewer may see (a parent is shown staff names only), so a screen renders
 * `by` and never filters it.
 */
export type GroupPostReaction = {
    key: string;
    emoji: string;
    count: number;
    mine: boolean;
    by: { name: string; is_parent: boolean }[];
};

/** The account that published a post — never a client-supplied author. */
export type GroupPostAuthor = {
    id: number;
    name: string;
};

export type GroupPost = {
    id: number;
    group_id: number;
    title: string | null;
    body: string;
    author: GroupPostAuthor | null;
    retained_until: string | null;
    created_at: string | null;
    updated_at: string | null;
    /**
     * EMPTY, not merely un-downloadable, for a reader without media consent: a
     * filename and a file size are themselves a disclosure about a child.
     */
    attachments: GroupPostAttachment[];
    /**
     * Stated by the server rather than inferred from an empty list, so "no photos
     * this week" is never confused with "not allowed to see them".
     */
    media_withheld: boolean;
    /** 🤲 👍 💯 ❓ on this post — all four, in order, with counts and `mine`. */
    reactions?: GroupPostReaction[];
    /**
     * Read receipts — STAFF payloads only, and ABSENT (not zero) while the school
     * has receipts switched off (`meta.story_reads.enabled`). `audience_count` is
     * the consented, current parents with a live portal login; `seen_count` never
     * exceeds it.
     */
    seen_by?: { name: string; seen_at: string | null }[];
    seen_count?: number;
    audience_count?: number;
    /**
     * `false` (with `seen_since`, a school-local `Y-m-d`) on a story that predates
     * recording and has no read on it: it was not tracked, which is not the same
     * as nobody opening it. The three fields above are omitted for it.
     */
    seen_tracked?: boolean;
    seen_since?: string | null;
};

/** Shape submitted by the compose box. Images travel as files, not in this object. */
export type GroupPostPayload = {
    title: string;
    body: string;
};

/** `meta` on the feed endpoints. The upload constraints come from the server. */
export type GroupFeedMeta = {
    group_label: string;
    may_receive_media: boolean;
    upload_key: string;
    accepted_image_types: string[];
    max_image_size_kb: number;
    max_images_per_post: number;
    /**
     * ADDITIVE video constraints. A SECOND set rather than wider values on the
     * four above, because the server holds video to its own allowlist, its own
     * ceiling (100MB against 8MB), its own count and its own shorter retention.
     */
    video_upload_key?: string;
    accepted_video_types?: string[];
    max_video_size_kb?: number;
    max_videos_per_post?: number;
    video_retention_days?: number;
    /** Read receipts: are they being collected, and how many parents cannot be counted. */
    story_reads?: { enabled: boolean; unreachable_count?: number };
};
