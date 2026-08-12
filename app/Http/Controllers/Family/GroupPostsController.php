<?php

namespace App\Http\Controllers\Family;

use App\Models\GroupPost;
use App\Models\Masjid;
use App\Support\GroupAudience;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The class story, as a parent reads it (T-015e).
 *
 * Every decision here is `App\Support\GroupAudience`'s — the same object, the
 * same methods and the same consent rules the staff feed uses. Nothing about
 * consent moved for this slice: `media` still covers `feed`, a guardian with no
 * recorded consent still receives neither, and a reader without media consent
 * gets NO attachment list at all, because a filename and a file size are
 * themselves a disclosure about a child.
 *
 * ---------------------------------------------------------------------------
 * NO SIGNED URLS. EVER.
 * ---------------------------------------------------------------------------
 *
 * `docs/t015-parent-identity-design.md` §8 makes this a build requirement and
 * not a preference: consent revoked mid-session is only safe because every read
 * re-enters `GroupAudience`, including the byte stream. So this endpoint serves
 * the image itself, through the bearer token, re-resolving
 * masjid → group → post → attachment on every single request, with
 * `Cache-Control: private, no-store`.
 *
 * The moment an attachment is handed a signed, long-lived or CDN-cached URL,
 * withdrawing consent stops working — the URL keeps serving a child's
 * photograph to whoever holds it, with nothing left to ask. Already-delivered
 * bytes and the app's on-device cache genuinely cannot be recalled; that is
 * disclosed in the parent-facing consent copy rather than pretended away. What
 * must not happen is adding a fourth category of unrecallable bytes on purpose.
 */
class GroupPostsController extends FamilyController
{
    /**
     * GET /api/family/masjids/{masjid_id}/groups/{group_id}/posts
     */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = $this->group($group_id);

        $this->authorizeDisclosure($group, GroupAudience::DISCLOSURE_FEED);

        $mayReceiveMedia = $this->audience->mayReceive(
            $this->contact(), $group, GroupAudience::DISCLOSURE_MEDIA
        );

        $posts = $group->posts()
            ->with(['author:id,name', 'attachments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 15))
            ->through(fn (GroupPost $post) => $this->serialize($post, $masjid_id, $group_id, $mayReceiveMedia));

        return response()->json([
            'status' => 'success',
            'data' => $posts,
            'meta' => $this->meta(['may_receive_media' => $mayReceiveMedia]),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../posts/{post_id}
     */
    public function show($masjid_id, $group_id, $post_id)
    {
        $group = $this->group($group_id);

        $this->authorizeDisclosure($group, GroupAudience::DISCLOSURE_FEED);

        $post = $group->posts()->with(['author:id,name', 'attachments'])->findOrFail($post_id);

        $mayReceiveMedia = $this->audience->mayReceive(
            $this->contact(), $group, GroupAudience::DISCLOSURE_MEDIA
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($post, $masjid_id, $group_id, $mayReceiveMedia),
            'meta' => $this->meta(['may_receive_media' => $mayReceiveMedia]),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../posts/{post_id}/attachments/{attachment_id}
     *
     * The whole ownership chain is re-resolved link by link, each found THROUGH
     * its parent, so a foreign id anywhere in the path is a 404 — a miss, not a
     * filter — and the media disclosure is asked for again at the point the
     * bytes leave. A guardian whose consent was withdrawn a second ago is
     * refused here, on this request, which is the property §8 requires.
     */
    public function downloadAttachment($masjid_id, $group_id, $post_id, $attachment_id)
    {
        // Resolving the masjid first keeps the chain explicit and 404s an id
        // that names no organisation at all. It is NOT the isolation mechanism —
        // that is the bound tenant plus BelongsToMasjid, exactly as on the staff
        // side (.claude/rules/tenant-scoping.md forbids re-implementing the
        // filter by hand).
        Masjid::findOrFail($masjid_id);

        $group = $this->group($group_id);
        $post = $group->posts()->findOrFail($post_id);
        $attachment = $post->attachments()->findOrFail($attachment_id);

        $this->authorizeDisclosure($group, GroupAudience::DISCLOSURE_MEDIA);

        if (! $attachment->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This image is no longer stored on the server.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $attachment->storage()->download(
            $attachment->path,
            $attachment->original_name,
            [
                // Sniffed from the bytes at upload and constrained to the
                // configured allowlist, so it is ours to state rather than the
                // uploader's. Attachment disposition plus the global nosniff
                // header keeps it from ever rendering inline.
                'Content-Type' => $attachment->mime_type,
                // No proxy, no CDN and no shared cache may hold one family's
                // photograph and hand it to the next person who asks.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * One post as an entitled parent sees it.
     *
     * @return array<string,mixed>
     */
    private function serialize(GroupPost $post, $masjid_id, $group_id, bool $mayReceiveMedia): array
    {
        $attachments = $mayReceiveMedia
            ? $post->attachments->map(fn ($attachment) => $attachment->toAudienceArray() + [
                // The FAMILY path, not the admin one. Both are authenticated
                // endpoints that re-resolve the chain; they differ only in which
                // realm's token opens them, and handing a parent an
                // `/api/admin/...` URL would produce a 401 they cannot act on.
                'download_path' => sprintf(
                    '/api/family/masjids/%s/groups/%s/posts/%d/attachments/%d',
                    $masjid_id, $group_id, $post->id, $attachment->id
                ),
            ])->values()->all()
            : [];

        return [
            'id' => (int) $post->id,
            'group_id' => (int) $post->group_id,
            'title' => $post->title,
            'body' => $post->body,
            'author' => $post->author ? ['name' => $post->author->name] : null,
            'created_at' => optional($post->created_at)->toIso8601String(),
            'attachments' => $attachments,
            // Stated rather than inferred from an empty array, so a parent with
            // no photos this week is not confused with one who is not allowed to
            // see them.
            'media_withheld' => ! $mayReceiveMedia && $post->attachments->isNotEmpty(),
        ];
    }
}
