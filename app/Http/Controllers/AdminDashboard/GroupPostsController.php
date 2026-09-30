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
use App\Services\Groups\GroupStoryPublisher;
use App\Support\ScheduledTime;
use App\Support\Errors;
use App\Support\GroupAudience;
use App\Support\GroupMedia;
use App\Support\GroupPostAttachments;
use App\Support\GroupPostSignals;
use App\Support\Reactions;
use App\Models\GroupPostReaction;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    /** The school's zone, resolved once per request (a Masjid lookup). */
    private ?string $zone = null;

    public function __construct(private GroupAudience $audience, private GroupStoryPublisher $publisher)
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

        // THE SCHEDULED LIST is a separate view of the same table: the stories that are
        // not out yet (waiting, or refused at release), soonest first. Only the staff
        // who may see a scheduled story may ask for it (a teacher of the class, the
        // office), and that is the WHOLE gate: the office edits and cancels a teacher's
        // scheduled story (S14) without being on the roster, and a story that has not
        // gone out is not yet a disclosure to anybody. The FEED below is unchanged, and an
        // administrator who is not on the roster still cannot read it back. The default
        // feed shows what families see, for staff too, so a story does not appear in it
        // the moment it is scheduled.
        if ($request->boolean('scheduled')) {
            if (! $this->audience->mayReadUnpublished($request->user(), $group)) {
                abort(403, 'You are not entitled to the scheduled stories of this group.');
            }

            $mayReceiveMedia = $this->audience->mayReceive(
                $request->user(), $group, GroupAudience::DISCLOSURE_MEDIA
            );

            $scheduled = $group->posts()
                ->unpublished()
                ->with(['author:id,name', 'attachments'])
                ->orderBy('published_at')
                ->orderBy('id');

            // ONE PAGE HOLDING EVERYTHING. A teacher may schedule a month of daily stories
            // (S12), and a story the list does not show is a story nobody can edit, send
            // now or cancel (S14). Same paginator shape as the feed, so no client changes;
            // the page is as long as the list, which is bounded by what people scheduled.
            $posts = $scheduled->paginate(max(1, (clone $scheduled)->count()));

            $posts = $posts->through(fn (GroupPost $post) => $this->serialize(
                $post, $masjid_id, $group_id, $mayReceiveMedia, null
            ));

            return response()->json([
                'status' => 'success',
                'data' => $posts,
                'meta' => $this->meta($mayReceiveMedia, $group),
            ], Response::HTTP_OK);
        }

        $this->authorizeDisclosure($request->user(), $group, GroupAudience::DISCLOSURE_FEED);

        $mayReceiveMedia = $this->audience->mayReceive(
            $request->user(), $group, GroupAudience::DISCLOSURE_MEDIA
        );

        $posts = $group->posts()
            ->published()
            ->with(['author:id,name', 'attachments'])
            ->newestPublishedFirst()
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

        // A story that is not out yet is read by the staff who manage it (a teacher of
        // the class, the office) without the feed gate, exactly as the Scheduled list
        // is: it is not yet a disclosure to anybody. Everything else, including a
        // missing id, asks the feed gate first as it always has.
        $candidate = $this->postsFor($group, $request->user())
            ->with(['author:id,name', 'attachments'])
            ->find($post_id);

        if ($candidate === null || $candidate->isPublished()) {
            $this->authorizeDisclosure($request->user(), $group, GroupAudience::DISCLOSURE_FEED);
        }

        $post = $candidate ?? $this->postsFor($group, $request->user())->with(['author:id,name', 'attachments'])->findOrFail($post_id);

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

        // "Send later": the school's own wall clock, already checked to be in the
        // future and within 30 days by the request. Null means out now.
        $sendAt = $request->sendAt();

        try {
            $post = DB::transaction(function () use ($request, $group, $sendAt) {
                $post = GroupPost::create([
                    'group_id' => $group->id,
                    // The AUTHENTICATED account, never a client-supplied author.
                    'author_user_id' => $request->user()?->id,
                    'title' => $request->input('title'),
                    'body' => $request->input('body'),
                    'retained_until' => $request->input('retained_until'),
                    // Null lets the model stamp "now"; a time keeps the story back from
                    // every family read until then (GroupPost::scopePublished).
                    'published_at' => $sendAt,
                ]);

                GroupPostAttachments::store($post, $this->uploads($request));

                return $post;
            });

            // Off-request nudge to the class's feed-consented guardians. Dispatched
            // AFTER the transaction (afterCommit) so a queued worker never sees the
            // post before it is committed; fail-soft so it can never break this write.
            //
            // NOT for a scheduled story: nobody may hear of it before they can read
            // it. `groups:publish-due` sends this same email when its time comes.
            if ($sendAt === null) {
                SendGroupNotificationJob::dispatch(
                    (int) $group->masjid_id,
                    (int) $group->id,
                    GroupNotificationEvent::CLASS_STORY,
                    aboutContactId: null,
                    authorUserId: $post->author_user_id,
                    authorContactId: null,
                )->afterCommit();
            }

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
        $post = $this->postsFor($group, $request->user())->findOrFail($post_id);

        $this->authorizeScheduledWrite($request->user(), $post);

        // Moving a story's time. Only a story that has NOT gone out: once families
        // have read one, "reschedule" would mean pulling it back, which is a
        // deletion and not an edit.
        $sendNow = $request->boolean('send_now');
        $sendAt = $request->sendAt();
        $moves = $sendNow || $sendAt !== null;

        if ($moves && $post->isPublished()) {
            return $this->cannotBeMoved('This story has already gone out, so its time can no longer be changed.');
        }

        // S15 IS ASKED AGAIN AT ONCE. A new time (or "Send now") on a story whose author
        // may no longer send it would only be refused again by the sweep, with the same
        // words, about two minutes before the new time; and "Send now" would skip the
        // sweep and put it out. So the answer is given here, while the person who is
        // editing can still act on it.
        if ($moves && ($why = $this->publisher->refusal($post, $group)) !== null) {
            return $this->cannotBeMoved("{$why} It cannot be put back in the queue: cancel it and write it again.");
        }

        $fields = $request->safe()->only(['title', 'body', 'retained_until']);
        $goesOutAt = null;

        if ($moves) {
            $goesOutAt = $sendNow ? now() : $sendAt;
            $fields += $this->retentionFollowing($post, $goesOutAt, $fields);
        } elseif (! $post->isPublished()) {
            $goesOutAt = $post->published_at;
        }

        // A window that closes before the story goes out would have the nightly purge
        // delete it (and its photos) unsent, with nothing in the Scheduled list to say so.
        $keptUntil = array_key_exists('retained_until', $fields)
            ? $fields['retained_until']
            : $post->retained_until?->toDateString();

        if ($goesOutAt !== null
            && ($moves || array_key_exists('retained_until', $fields))
            && $keptUntil !== null
            && Carbon::parse($keptUntil)->toDateString() < $goesOutAt->toDateString()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['retained_until' => ['Keep it until the day it goes out or later, or it would be deleted before anybody read it.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $moved = DB::transaction(function () use ($request, $post, $moves, $fields, $goesOutAt): bool {
                if ($moves) {
                    // THE MOVE IS DECIDED ON THE ROW AS IT IS NOW, under its lock. The
                    // check above read the row earlier; in the seconds since, the sweep
                    // may have announced it (then it is out, and moving it would pull
                    // back a story families were told about). The sweep's own claim
                    // waits on this lock and then finds the new time, so it cannot
                    // announce a story that is being moved.
                    $current = GroupPost::query()->whereKey($post->getKey())->lockForUpdate()->first();

                    if ($current === null || $current->isPublished()) {
                        return false;
                    }

                    // A new time is a new chance: a story that had been refused at
                    // release is scheduled afresh, and re-asked at its new time.
                    $fields['published_at'] = $goesOutAt;
                    $fields['publish_failed_at'] = null;
                    $fields['publish_failure'] = null;
                }

                if ($fields !== []) {
                    $post->update($fields);
                }

                GroupPostAttachments::store($post, $this->uploads($request));

                return true;
            });

            if (! $moved) {
                return $this->cannotBeMoved('This story has already gone out, so its time can no longer be changed.');
            }

            // "Send now" is out this instant, so it is announced this instant, by the
            // same claim the sweep makes: at most one email whoever gets there first. A
            // story is out only once announced, so this comes BEFORE the response is read.
            if ($sendNow) {
                $this->publisher->announce($post->fresh());
            }

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

    private function cannotBeMoved(string $message)
    {
        return response()->json([
            'status' => 'failed',
            'data' => ['send_at' => [$message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The retention window follows the day a story goes OUT, not the day it was typed.
     * Only a window the system stamped is moved: one the author chose (or this request
     * sets) is theirs.
     *
     * @param array<string,mixed> $fields the fields this request already sets
     * @return array<string,mixed>
     */
    private function retentionFollowing(GroupPost $post, $goesOutAt, array $fields): array
    {
        $days = (int) config('groups.feed.retention_days', 0);

        if ($days <= 0 || array_key_exists('retained_until', $fields)) {
            return [];
        }

        $stamped = $post->published_at?->copy()->addDays($days)->toDateString();

        if ($post->retained_until !== null && $post->retained_until->toDateString() !== $stamped) {
            return [];
        }

        return ['retained_until' => $goesOutAt->copy()->addDays($days)->toDateString()];
    }

    /**
     * Who may change or cancel a story that is NOT out yet: its author and the office
     * (S14). A co-teacher may SEE it in the Scheduled list and may not touch it.
     *
     * Deliberately not asked of a story that is out: those were already editable and
     * deletable by any teacher of the class, and this slice does not change that.
     */
    private function authorizeScheduledWrite(?User $user, GroupPost $post): void
    {
        if ($post->isPublished()) {
            return;
        }

        if ($this->maySchedule($user, $post)) {
            return;
        }

        abort(403, 'Only the author or the office can change a story that has not gone out.');
    }

    private function maySchedule(?User $user, GroupPost $post): bool
    {
        return $user !== null
            && ((int) $post->author_user_id === (int) $user->id || $user->can('manage contacts'));
    }

    /**
     * The group's stories AS THIS CALLER MAY SEE THEM. Staff who may see a scheduled
     * story (a teacher of the class, the office) address every story; anybody else
     * this controller serves (an administrator who is on the roster only as a parent,
     * say) addresses the stories that are out, exactly as a family does.
     */
    private function postsFor(Group $group, ?User $user): \Illuminate\Database\Eloquent\Relations\HasMany|\Illuminate\Database\Eloquent\Builder
    {
        $posts = $group->posts();

        return $this->audience->mayReadUnpublished($user, $group) ? $posts : $posts->published();
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

        // published(): nobody reacts to a story before its time, staff included. A tap
        // on a scheduled story would be a reaction the digest could announce to its
        // author before any family had read a word of it.
        $post = $group->posts()->published()->findOrFail($post_id);

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
        $post = $this->postsFor($group, request()->user())->findOrFail($post_id);

        // Deleting a story that has not gone out IS cancelling it: the author or the
        // office only. A soft delete, like every story: a mis-click is recoverable and
        // the bytes go with retention.
        $this->authorizeScheduledWrite(request()->user(), $post);

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
        $post = $this->postsFor($group, $request->user())->findOrFail($post_id);
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
        $post = $this->postsFor($group, $request->user())->findOrFail($post_id);
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
            // When it goes (or went) OUT to families, and where it stands. `published_at_
            // local` is the school's own clock in the form the Send-later field takes, so
            // the edit form shows what was chosen and not a UTC instant.
            'published_at' => optional($post->published_at ?? $post->created_at)->toIso8601String(),
            'published_at_local' => ScheduledTime::local($post->published_at ?? $post->created_at, $this->zone()),
            'status' => $post->hasFailedToPublish() ? 'failed' : ($post->isScheduled() ? 'scheduled' : 'published'),
            'publish_failure' => $post->publish_failure,
            // Author and office only: a co-teacher sees a scheduled story and is not
            // offered the buttons that would be refused.
            'can_change_schedule' => $post->isPublished() || $this->maySchedule(request()->user(), $post),
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

    private function zone(): string
    {
        return $this->zone ??= ScheduledTime::schoolTimezone();
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
            // "Send later": the clock the field is read on (the SCHOOL's, never the
            // browser's) and how far ahead it may go.
            'scheduling' => [
                'timezone' => $this->zone(),
                'max_days_ahead' => ScheduledTime::maxDaysAhead(),
            ],
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
