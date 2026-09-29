<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\GroupNotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\GroupPostFormRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Http\Requests\Admin\Groups\StoreGroupPostRequest;
use App\Http\Requests\Admin\Groups\UpdateGroupPostRequest;
use App\Models\Group;
use App\Models\GroupPost;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Errors;
use App\Support\GroupAudience;
use App\Support\GroupMedia;
use App\Support\GroupPostAttachments;
use App\Support\GroupPostSignals;
use App\Support\Reactions;
use App\Models\GroupPostReaction;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A group's PRIVATE activity feed — the "class story" (PLAN T-005b).
 *
 * Two different authorization questions, deliberately answered by two different
 * mechanisms:
 *
 *   - WRITING (store/update/destroy) is `permission:manage contacts`, exactly
 *     like the roster endpoints this sits beside. The accountable roster
 *     administrator publishes; the post records WHICH account did
 *     (`author_user_id`).
 *   - READING (index/show/downloadAttachment) additionally requires being IN the
 *     group, decided by App\Support\GroupAudience. `permission:view contacts` is
 *     necessary but NOT sufficient: .claude/rules/groups.md forbids a
 *     group-scoped read from being visible "to the whole tenant because they
 *     happen to be a Contact", and these posts are about children.
 *
 * Tenant isolation is not hand-rolled: the route keeps /masjids/{masjid_id}/...
 * by convention, but the `tenant` middleware binds TenantContext and
 * BelongsToMasjid auto-scopes Group, GroupPost and GroupPostAttachment — so
 * another organization's id anywhere in the chain is a MISS (404), never a
 * filtered row. See .claude/rules/tenant-scoping.md.
 *
 * findOrFail is kept OUTSIDE every try/catch so the app's JSON renderer turns
 * ModelNotFoundException into a clean 404 rather than a 500.
 */
class GroupPostsController extends Controller
{
    public function __construct(private GroupAudience $audience)
    {
    }

    /**
     * GET .../groups/{group_id}/posts
     *
     * The feed, newest first. Images are listed only for a reader entitled to
     * MEDIA: a guardian with feed-only consent gets the words and is told, in
     * `meta.media_withheld`, that there were pictures they may not have — which
     * is honest without being a disclosure.
     */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $this->authorizeDisclosure($request->user(), $group, GroupAudience::DISCLOSURE_FEED);

        $mayReceiveMedia = $this->audience->mayReceive(
            $request->user(), $group, GroupAudience::DISCLOSURE_MEDIA
        );

        $posts = $group->posts()
            ->with(['author:id,name', 'attachments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 15));

        // One query each for the page's reactions and receipts, as THIS viewer
        // may see them.
        $signals = $this->signals($group, $posts->getCollection(), $request->user());

        $posts = $posts->through(fn (GroupPost $post) => $this->serialize(
            $post, $masjid_id, $group_id, $mayReceiveMedia, $signals[(int) $post->id] ?? null
        ));

        return response()->json([
            'status' => 'success',
            'data' => $posts,
            'meta' => $this->meta($mayReceiveMedia, $group),
        ], Response::HTTP_OK);
    }

    /** GET .../groups/{group_id}/posts/{post_id} */
    public function show(Request $request, $masjid_id, $group_id, $post_id)
    {
        $group = Group::findOrFail($group_id);

        $this->authorizeDisclosure($request->user(), $group, GroupAudience::DISCLOSURE_FEED);

        $post = $group->posts()->with(['author:id,name', 'attachments'])->findOrFail($post_id);

        $mayReceiveMedia = $this->audience->mayReceive(
            $request->user(), $group, GroupAudience::DISCLOSURE_MEDIA
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize(
                $post, $masjid_id, $group_id, $mayReceiveMedia,
                $this->signals($group, [$post], $request->user())[(int) $post->id] ?? null
            ),
            'meta' => $this->meta($mayReceiveMedia, $group),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/posts
     *
     * The post row and its images are written in ONE transaction, and
     * GroupPostAttachments removes anything it already wrote if a later file
     * fails — so a half-published story never exists and no bytes are left on
     * disk that nothing points at.
     *
     * masjid_id is intentionally absent from the create payload: the
     * BelongsToMasjid creating hook stamps it from the bound tenant, so a
     * client-supplied masjid_id can never plant a post in another organization.
     */
    public function store(StoreGroupPostRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        try {
            $post = DB::transaction(function () use ($request, $group) {
                $post = GroupPost::create([
                    'group_id' => $group->id,
                    // The AUTHENTICATED account, never a client-supplied author.
                    'author_user_id' => $request->user()?->id,
                    'title' => $request->input('title'),
                    'body' => $request->input('body'),
                    'retained_until' => $request->input('retained_until'),
                ]);

                GroupPostAttachments::store($post, $this->uploads($request));

                return $post;
            });

            // Off-request nudge to the class's feed-consented guardians. Dispatched
            // AFTER the transaction (afterCommit) so a queued worker never sees the
            // post before it is committed; fail-soft so it can never break this write.
            SendGroupNotificationJob::dispatch(
                (int) $group->masjid_id,
                (int) $group->id,
                GroupNotificationEvent::CLASS_STORY,
                aboutContactId: null,
                authorUserId: $post->author_user_id,
                authorContactId: null,
            )->afterCommit();

            $post->load(['author:id,name', 'attachments']);

            return response()->json([
                'status' => 'success',
                // The writer sees what they just wrote, images included: they
                // supplied the bytes a moment ago.
                'data' => $this->serialize(
                    $post, $masjid_id, $group_id, true,
                    $this->signals($group, [$post], $request->user())[(int) $post->id] ?? null
                ),
                'meta' => $this->meta(true, $group),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT .../groups/{group_id}/posts/{post_id}
     *
     * Edits the text and may ADD images. Authorship is not editable: it records
     * who published, and rewriting that would defeat the point of recording it.
     */
    public function update(UpdateGroupPostRequest $request, $masjid_id, $group_id, $post_id)
    {
        $group = Group::findOrFail($group_id);
        $post = $group->posts()->findOrFail($post_id);

        try {
            DB::transaction(function () use ($request, $post) {
                $fields = $request->safe()->only(['title', 'body', 'retained_until']);

                if ($fields !== []) {
                    $post->update($fields);
                }

                GroupPostAttachments::store($post, $this->uploads($request));
            });

            $fresh = $post->fresh()->load(['author:id,name', 'attachments']);

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize(
                    $fresh, $masjid_id, $group_id, true,
                    $this->signals($group, [$fresh], $request->user())[(int) $fresh->id] ?? null
                ),
                'meta' => $this->meta(true, $group),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT .../groups/{group_id}/posts/{post_id}/reactions/{reaction}
     *
     * Add this caller's 🤲 / 👍 / 💯 / ❓ to a class story post (owner,
     * 2026-09-29). Idempotent — a second tap, or two tabs, leave one row — which
     * is why adding and removing are two verbs rather than one "toggle" that a
     * double-tap would undo.
     *
     * THE GATE IS THE FEED READ GATE: the route's write gate
     * (`permission:manage contacts` / `teacher.leads`), then
     * GroupAudience::DISCLOSURE_FEED. A person may only react to what they may
     * read. The post is found THROUGH the group, so another school's post, another
     * class's post and a soft-deleted post are all a 404.
     *
     * The principal is the AUTHENTICATED account, never a client claim, and
     * nothing is dispatched: the author hears about reactions once, in the
     * content-free digest (`groups:notify-reactions`).
     */
    public function react(Request $request, $masjid_id, $group_id, $post_id, $reaction)
    {
        return $this->setReaction($request, $group_id, $post_id, $reaction, true);
    }

    /** DELETE .../reactions/{reaction} — take it back. Idempotent the same way. */
    public function unreact(Request $request, $masjid_id, $group_id, $post_id, $reaction)
    {
        return $this->setReaction($request, $group_id, $post_id, $reaction, false);
    }

    /** Shared body of react/unreact. */
    private function setReaction(Request $request, $group_id, $post_id, $reaction, bool $on)
    {
        $group = Group::findOrFail($group_id);

        $this->authorizeDisclosure($request->user(), $group, GroupAudience::DISCLOSURE_FEED);

        $post = $group->posts()->findOrFail($post_id);

        if (! Reactions::isAllowed($reaction)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['reaction' => ['A reaction must be one of: '.implode(' ', Reactions::REACTIONS).'.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $key = [
            'group_post_id' => $post->id,
            'reaction' => $reaction,
            // The AUTHENTICATED account, never a client claim.
            'user_id' => $request->user()->id,
        ];

        if ($on) {
            // createOrFirst: the unique key settles a race between two taps
            // instead of the second one 500ing.
            GroupPostReaction::createOrFirst($key);
        } else {
            GroupPostReaction::query()->where($key)->delete();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'post_id' => (int) $post->id,
                'reactions' => GroupPostSignals::reactionsFor($post, $request->user()),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * DELETE .../groups/{group_id}/posts/{post_id}
     *
     * Soft delete, deliberately: the post disappears from the feed immediately,
     * but a mis-click must not be the thing that destroys a term of classroom
     * photographs. The BYTES go when the retention window closes and
     * `groups:purge-feed` force-deletes the row — see GroupPost::purge().
     */
    public function destroy($masjid_id, $group_id, $post_id)
    {
        $group = Group::findOrFail($group_id);
        $post = $group->posts()->findOrFail($post_id);

        $post->delete();

        return response()->json([
            'status' => 'success',
            'data' => ['id' => $post->id, 'deleted_at' => optional($post->deleted_at)->toIso8601String()],
        ], Response::HTTP_OK);
    }

    /**
     * GET .../groups/{group_id}/posts/{post_id}/attachments/{attachment_id}
     *
     * Streams one image off the PRIVATE disk. This endpoint is the reason those
     * files are not in the public root. It re-resolves the WHOLE ownership chain
     * — masjid -> group -> post -> attachment, each link found through its
     * parent — so a foreign id anywhere in the path is a 404 (a miss, not a
     * filter), and it then asks GroupAudience whether this reader may receive
     * media at all. A guardian who has not consented is refused HERE, at the
     * point of disclosure, which is what .claude/rules/groups.md requires.
     */
    public function downloadAttachment(Request $request, $masjid_id, $group_id, $post_id, $attachment_id)
    {
        // masjid -> group -> post -> attachment. The masjid link is the BOUND
        // TENANT rather than a hand-written `where masjid_id = ?`: Group is
        // BelongsToMasjid, so findOrFail already misses on another
        // organization's group, and .claude/rules/tenant-scoping.md forbids
        // re-implementing that filter by hand (a filter can be forgotten; the
        // scope cannot). Resolving the route's masjid first keeps the chain
        // explicit and 404s an id that names no organization at all.
        Masjid::findOrFail($masjid_id);
        $group = Group::findOrFail($group_id);
        $post = $group->posts()->findOrFail($post_id);
        $attachment = $post->attachments()->findOrFail($attachment_id);

        $this->authorizeDisclosure($request->user(), $group, GroupAudience::DISCLOSURE_MEDIA);

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
                // The type was sniffed from the bytes at upload and constrained
                // to the configured allowlist, so it is ours to state rather
                // than the uploader's. Attachment disposition (set by
                // download()) plus the global nosniff header keeps it from ever
                // being rendered inline.
                'Content-Type' => $attachment->mime_type,
                // Private and uncached: no proxy may hold one group's photograph
                // and hand it to the next person who asks for the URL.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * POST .../groups/{group_id}/posts/{post_id}/attachments/{attachment_id}/playback
     *
     * Mint a playback ticket for ONE video: a short-lived, viewer-bound, relative
     * signed URL the <video> element can use, because it cannot send a bearer
     * token and a 100MB blob fetch is not playback.
     *
     * The same chain and the same disclosure question as downloadAttachment,
     * asked here at MINT time and then asked AGAIN by
     * GroupMediaPlaybackController on every ranged request the ticket buys. This
     * endpoint is not the gate; it is the first of two.
     *
     * POST rather than GET, so the URL it returns cannot end up in a browser
     * history entry, a proxy access log line or a bookmark of its own.
     */
    public function playbackTicket(Request $request, $masjid_id, $group_id, $post_id, $attachment_id)
    {
        Masjid::findOrFail($masjid_id);
        $group = Group::findOrFail($group_id);
        $post = $group->posts()->findOrFail($post_id);
        $attachment = $post->attachments()->findOrFail($attachment_id);

        $this->authorizeDisclosure($request->user(), $group, GroupAudience::DISCLOSURE_MEDIA);

        // Photos keep the bearer-token blob fetch. Refusing here rather than
        // quietly minting a ticket that the playback route would refuse anyway
        // keeps the answer in one place.
        if (! GroupMedia::isPlayable($attachment)) {
            return response()->json([
                'status' => 'failed',
                'data' => 'That attachment is not a video.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'url' => GroupMedia::postTicket(
                    $masjid_id, $group_id, $post->id, $attachment->id,
                    GroupMedia::VIEWER_STAFF, (int) $request->user()->id,
                ),
                'expires_in' => GroupMedia::playbackTtlMinutes() * 60,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Refuse a disclosure this caller is not entitled to.
     *
     * 403, not 404: the group itself is addressable by this admin (they can see
     * it in the groups list and manage its roster), so pretending it does not
     * exist would be a lie that makes the API harder to reason about. 404 stays
     * reserved for what it means everywhere else here — an id belonging to
     * another organization.
     */
    private function authorizeDisclosure(?User $user, Group $group, string $disclosure): void
    {
        if ($this->audience->mayReceive($user, $group, $disclosure)) {
            return;
        }

        abort(403, $disclosure === GroupAudience::DISCLOSURE_MEDIA
            ? 'You are not entitled to the images in this group.'
            : 'You are not entitled to read this group\'s feed.');
    }

    /**
     * One post as an entitled reader sees it.
     *
     * When the reader may not receive media the attachments are OMITTED, not
     * merely un-downloadable: a filename and a file size are themselves a
     * disclosure about a child, and least disclosure means the payload does not
     * carry what the reader may not have.
     *
     * @return array<string,mixed>
     */
    private function serialize(GroupPost $post, $masjid_id, $group_id, bool $mayReceiveMedia, ?array $signals = null): array
    {
        $attachments = $mayReceiveMedia
            ? $post->attachments->map(fn ($attachment) => $attachment->toAudienceArray() + [
                // The only link that exists for one of these: back at the
                // authenticated endpoint, which the SPA fetches with the bearer
                // token. A plain <a href> would 401 — and that is the point.
                //
                // For the realm the request came through. This controller is
                // also mounted under /api/teacher, and the admin realm refuses
                // a Teacher login — a hardcoded /api/admin link gave every
                // teacher a photo they could not open.
                'download_path' => sprintf(
                    '/api/%s/masjids/%s/groups/%s/posts/%d/attachments/%d',
                    request()->is('api/teacher/*') ? 'teacher' : 'admin',
                    $masjid_id, $group_id, $post->id, $attachment->id
                ),
                // Video only, and it is a path to ASK for a ticket, not a
                // ticket: a playable URL in a list payload would start its
                // ten-minute clock when the page rendered rather than when
                // somebody pressed play, and would sit in whatever holds that
                // payload. Null for a photograph, which needs neither.
                'playback_ticket_path' => GroupMedia::isPlayable($attachment)
                    ? sprintf(
                        '/api/%s/masjids/%s/groups/%s/posts/%d/attachments/%d/playback',
                        request()->is('api/teacher/*') ? 'teacher' : 'admin',
                        $masjid_id, $group_id, $post->id, $attachment->id
                    )
                    : null,
            ])->values()->all()
            : [];

        return [
            'id' => $post->id,
            'group_id' => $post->group_id,
            'title' => $post->title,
            'body' => $post->body,
            'author' => $post->author ? ['id' => $post->author->id, 'name' => $post->author->name] : null,
            'retained_until' => optional($post->retained_until)->toDateString(),
            'created_at' => optional($post->created_at)->toIso8601String(),
            'updated_at' => optional($post->updated_at)->toIso8601String(),
            'attachments' => $attachments,
            // Stated rather than inferred from an empty array, so a reader who
            // simply has no photos this week is not confused with one who is not
            // allowed to see them.
            'media_withheld' => ! $mayReceiveMedia && $post->attachments->isNotEmpty(),
            // All four reactions with counts, named as THIS viewer may see them
            // (App\Support\GroupPostSignals). A post just created has none, and
            // the four empty buttons are still what the screen draws from.
            'reactions' => $signals['reactions'] ?? Reactions::summarize(collect(), false, null, null),
        ] + $this->seenFields($signals);
    }

    /**
     * Reactions for the posts, and — while `groups.story_reads.enabled` is on —
     * their read receipts. STAFF payloads only: this controller serves the office
     * and the teacher, both of whom are shown every name. The family controller
     * builds neither `seen_*` field.
     *
     * @param iterable<GroupPost> $posts
     * @return array<int, array<string,mixed>> keyed by post id
     */
    private function signals(Group $group, iterable $posts, ?User $viewer): array
    {
        $posts = collect($posts);
        $signals = GroupPostSignals::forPosts($posts, $viewer);

        if (! $this->readsEnabled()) {
            return $signals;
        }

        $timezone = $this->schoolTimezone();
        $seen = GroupPostSignals::seenFor(
            $posts,
            $this->audience->storyGuardianContacts($group),
            GroupPostSignals::trackingSince($timezone),
            $timezone,
        );

        foreach ($signals as $id => $row) {
            $signals[$id] = $row + ['seen' => $seen[$id] ?? null];
        }

        return $signals;
    }

    /** The receipt fields for one post — absent (not zero) while receipts are switched off. */
    private function seenFields(?array $signals): array
    {
        $seen = $signals['seen'] ?? null;

        if (! $this->readsEnabled() || $seen === null) {
            return [];
        }

        // Predates recording and has no read: say so, rather than "0 of N".
        if (($seen['tracked'] ?? true) === false) {
            return ['seen_tracked' => false, 'seen_since' => $seen['since']];
        }

        return [
            'seen_by' => $seen['seen_by'],
            'seen_count' => $seen['seen_count'],
            'audience_count' => $seen['audience_count'],
        ];
    }

    /** The school's own time zone, so "Not tracked before <date>" names the school's day. */
    private function schoolTimezone(): string
    {
        $masjidId = app(TenantContext::class)->get();
        $timezone = $masjidId ? Masjid::find($masjidId)?->timezone : null;

        return is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : (string) config('app.timezone');
    }

    /**
     * Whether receipts are being collected at all. While off, "Seen by 0 of 7"
     * would describe a receipt nobody has been keeping, so the fields are
     * omitted rather than zeroed.
     */
    private function readsEnabled(): bool
    {
        return (bool) config('groups.story_reads.enabled', false);
    }

    /**
     * Vertical-aware labelling, same source as GroupsController: what a group is
     * CALLED comes from the tenant's terminology pack ("Halaqat"/"Classrooms"/
     * "Teams"), never a hardcoded string. See .claude/rules/verticals.md.
     *
     * @return array<string,mixed>
     */
    private function meta(bool $mayReceiveMedia, ?Group $group = null): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'group_label' => $masjid?->term('groups') ?? 'Groups',
            'may_receive_media' => $mayReceiveMedia,
            'upload_key' => GroupPostFormRequest::UPLOAD_KEY,
            'accepted_image_types' => (array) config('groups.media.mime_types', []),
            'max_image_size_kb' => (int) config('groups.media.max_size_kb', 0),
            'max_images_per_post' => (int) config('groups.media.max_per_post', 0),
            // The four reaction buttons, from the one shared list.
            'reactions' => Reactions::catalogue(),
            // Read receipts: whether they are being collected, and — only then —
            // how many consented, current parents hold no portal login and so
            // cannot be counted (the footnote under "Seen by 4 of 7").
            'story_reads' => [
                'enabled' => $this->readsEnabled(),
            ] + ($this->readsEnabled() && $group !== null
                ? ['unreachable_count' => $this->audience->storyGuardiansWithoutLogin($group)]
                : []),
            // ADDITIVE. The four keys above are the wire contract the admin SPA
            // builds its `accept` attribute from; video gets its own five rather
            // than a widening of theirs, for the same reason the config block
            // does. See App\Support\GroupMedia::videoMeta().
        ] + GroupMedia::videoMeta('max_videos_per_post');
    }

    /**
     * The uploaded media, as one plain list: images first, then video.
     *
     * TWO BAGS, ONE LIST. They arrive separately because they are validated
     * separately — different allowlist, different ceiling, different count — but
     * once past the boundary they are the same thing: a file to write to the
     * private disk and record as an attachment. GroupPostAttachments stamps the
     * shorter retention window on the video ones from their own sniffed type,
     * so nothing downstream has to remember which bag a file came out of.
     *
     * @return array<int,\Illuminate\Http\UploadedFile>
     */
    private function uploads(Request $request): array
    {
        return array_merge(
            $this->bag($request, GroupPostFormRequest::UPLOAD_KEY),
            $this->bag($request, GroupPostFormRequest::VIDEO_UPLOAD_KEY),
        );
    }

    /**
     * One upload bag, normalised to a list.
     *
     * @return array<int,\Illuminate\Http\UploadedFile>
     */
    private function bag(Request $request, string $key): array
    {
        $files = $request->file($key);

        if ($files === null) {
            return [];
        }

        return array_values(is_array($files) ? $files : [$files]);
    }
}
