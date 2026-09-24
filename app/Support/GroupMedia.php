<?php

namespace App\Support;

use App\Models\GroupMessageAttachment;
use App\Models\GroupPostAttachment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The decisions about group media that are neither storage nor disclosure.
 *
 * Three questions live here, and each one had been about to acquire two
 * implementations — one on the class-story side and one on the conversation
 * side — which is how the two surfaces drift apart:
 *
 *   1. **Is this file a video?** `mime_type` is the ONLY discriminator either
 *      attachment table has (there is no `kind` column, deliberately: the
 *      sniffed type is already the authoritative answer and a second column
 *      could disagree with it). Asking it in one place is what keeps validation,
 *      retention, serialization and the SPA agreeing about the same row.
 *   2. **When does it die?** Video carries its own, shorter retention window,
 *      stamped on the attachment row at write time — see config/groups.php.
 *   3. **How is it played?** A short-lived, viewer-bound, RELATIVE signed URL,
 *      minted here so every realm mints the same thing.
 *
 * @see .claude/rules/private-uploads.md
 */
class GroupMedia
{
    /** Signed-route names. Relative signatures, so a second host serving this app still validates. */
    public const ROUTE_POST = 'group-media.post.stream';
    public const ROUTE_MESSAGE = 'group-media.message.stream';

    /** The two principals a ticket can name. A ticket is useless to anyone else. */
    public const VIEWER_STAFF = 'staff';
    public const VIEWER_FAMILY = 'family';

    /**
     * Is this stored file a video?
     *
     * Asked of the stored `mime_type`, which was SNIFFED from the bytes at
     * upload and constrained to a configured allowlist — never of a filename
     * and never of a client-supplied Content-Type.
     *
     * The prefix test rather than `in_array($mime, config(...))` is on purpose:
     * this question is asked about rows that already exist, and an allowlist
     * narrowed later (or an env var edited on one box) must not silently
     * reclassify a video already on disk as a photograph, which would hand it
     * the photo lifetime and the photo renderer.
     */
    public static function isVideo(?string $mimeType): bool
    {
        return str_starts_with(strtolower((string) $mimeType), 'video/');
    }

    /**
     * The retention date to stamp on a new attachment, or null to leave it on
     * its parent's window.
     *
     * Null for every image, which is what keeps this additive: a photograph has
     * no window of its own and still dies exactly when its post or thread does.
     */
    public static function retainedUntilFor(?string $mimeType): ?string
    {
        if (! self::isVideo($mimeType)) {
            return null;
        }

        $days = (int) config('groups.media.video.retention_days', 90);

        // 0 or negative means "leave video on the parent's window" — the same
        // spelling the feed, messaging and behaviour blocks already use.
        return $days > 0 ? Carbon::now()->addDays($days)->toDateString() : null;
    }

    /** Lifetime of a playback ticket, in minutes, floored at one. */
    public static function playbackTtlMinutes(): int
    {
        return max(1, (int) config('groups.media.video.playback_ttl_minutes', 10));
    }

    /**
     * Mint a playback ticket for one class-story video.
     *
     * RELATIVE (`absolute: false`), which is not a detail. An absolute signed
     * URL is built from `config('app.url')`, and this app is served under more
     * than one hostname (see App\Http\Middleware\SecurityHeaders — a second host
     * whose document origin is not app.url's). An absolute ticket would send the
     * <video> element cross-origin for no reason, into the CSP and CORS
     * allowlists that have already cost this project two outages. A relative
     * ticket is same-origin wherever the SPA is served from, and
     * `signed:relative` validates it there.
     *
     * The ticket NAMES ITS VIEWER, and that is what makes it safe to hand to an
     * element that cannot carry a bearer token: the signature covers the viewer
     * parameters, so they cannot be edited, and the handler re-resolves that
     * principal and re-asks GroupAudience on every ranged request. A ticket
     * minted for a guardian whose consent is withdrawn a second later stops
     * working on the next range, mid-playback.
     */
    public static function postTicket(
        int|string $masjidId,
        int|string $groupId,
        int|string $postId,
        int|string $attachmentId,
        string $viewerType,
        int|string $viewerId,
    ): string {
        return URL::temporarySignedRoute(
            self::ROUTE_POST,
            Carbon::now()->addMinutes(self::playbackTtlMinutes()),
            [
                'masjid_id' => $masjidId,
                'group_id' => $groupId,
                'post_id' => $postId,
                'attachment_id' => $attachmentId,
                'viewer_type' => $viewerType,
                'viewer_id' => $viewerId,
            ],
            absolute: false,
        );
    }

    /** Mint a playback ticket for one conversation video. Same arrangement as postTicket(). */
    public static function messageTicket(
        int|string $masjidId,
        int|string $groupId,
        int|string $threadId,
        int|string $messageId,
        int|string $attachmentId,
        string $viewerType,
        int|string $viewerId,
    ): string {
        return URL::temporarySignedRoute(
            self::ROUTE_MESSAGE,
            Carbon::now()->addMinutes(self::playbackTtlMinutes()),
            [
                'masjid_id' => $masjidId,
                'group_id' => $groupId,
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'attachment_id' => $attachmentId,
                'viewer_type' => $viewerType,
                'viewer_id' => $viewerId,
            ],
            absolute: false,
        );
    }

    /**
     * The `meta` keys describing what may be uploaded, ADDITIVE to the four
     * image-named keys both controllers already publish.
     *
     * Additive rather than renamed because the existing four are a wire contract
     * the admin SPA builds its `accept` attribute from, and renaming them would
     * break a screen to tidy a name. One definition for both surfaces, so the
     * class story and the conversation cannot advertise different ceilings for
     * the same file.
     *
     * @return array<string,mixed>
     */
    public static function videoMeta(string $perKey = 'max_videos_per_post'): array
    {
        return [
            'video_upload_key' => \App\Http\Requests\Admin\Groups\GroupPostFormRequest::VIDEO_UPLOAD_KEY,
            'accepted_video_types' => (array) config('groups.media.video.mime_types', []),
            'max_video_size_kb' => (int) config('groups.media.video.max_size_kb', 0),
            $perKey => (int) config('groups.media.video.max_per_post', 0),
            'video_retention_days' => (int) config('groups.media.video.retention_days', 0),
        ];
    }

    /**
     * Whether an attachment row is one this app will serve through the playback
     * route at all.
     *
     * Images deliberately keep the bearer-token blob fetch they have always had.
     * Nothing is gained by giving a 200KB photograph a signed URL, and something
     * is lost: a signed URL is a bearer credential in a string, and the whole
     * reason the photo path exists in its current shape is that it never
     * produces one.
     */
    public static function isPlayable(GroupPostAttachment|GroupMessageAttachment $attachment): bool
    {
        return self::isVideo($attachment->mime_type);
    }
}
