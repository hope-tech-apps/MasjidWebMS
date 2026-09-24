<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\GroupNotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\GroupPostFormRequest;
use App\Http\Requests\Admin\Groups\StoreGroupMessageRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Http\Requests\Admin\Groups\StoreGroupThreadRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageReaction;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Errors;
use App\Support\GroupAudience;
use App\Support\GroupMedia;
use App\Support\GroupMessageAttachments;
use App\Support\GroupMessageSignals;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Group messaging threads — the teacher <-> parent channel (PLAN T-005c).
 *
 * Same two-gate arrangement as the feed it mirrors, with one addition:
 *
 *   - OPENING a thread / closing / reopening / soft-deleting one is
 *     `permission:manage contacts`, exactly like publishing a feed post — the
 *     accountable roster administrator, recorded in `created_by_user_id`. An
 *     admin who cannot read a thread can still open one (the feed's documented
 *     read/write asymmetry), because opening is administration.
 *   - READING (index/show) additionally requires being IN the audience, decided
 *     by App\Support\GroupAudience: the feed disclosure for a group-wide
 *     thread; leaders + the specific member/guardian it concerns for a
 *     participant-scoped one.
 *   - WRITING A MESSAGE requires BOTH: `manage contacts` on the route AND being
 *     able to read the thread. Unlike publishing an announcement, a message is
 *     a contribution to a conversation, and someone who may not see the
 *     conversation has no place speaking into it.
 *
 * PHOTOS. A staff message may carry images (GroupMessageAttachment, private
 * disk). They are listed only for a reader GroupAudience::mayReceiveThreadMedia()
 * allows, and served only by downloadAttachment(), which asks it again. This
 * controller is mounted in BOTH staff realms (routes/admin.php and
 * routes/teacher.php), so every download link is built for the realm the
 * request arrived through — a teacher login is refused by the admin realm.
 *
 * REACTIONS AND READ RECEIPTS (owner, 2026-09-21). react()/unreact() add and
 * remove the caller's 🤲 👍 💯 ❓ behind replying's own gate (route write
 * gate + mayReceiveThread() + not closed). Every serialized message carries
 * its reactions and `read_by`, derived from the readers' bookmarks by
 * App\Support\GroupMessageSignals — a staff viewer is shown every name.
 *
 * Tenant isolation is not hand-rolled: `tenant` middleware binds TenantContext
 * and BelongsToMasjid auto-scopes Group, GroupThread, GroupMessage,
 * GroupMessageAttachment and GroupThreadRead — a foreign organization's id anywhere in the chain is a MISS
 * (404), never a filtered row. findOrFail stays OUTSIDE every try/catch so the
 * JSON renderer turns it into a clean 404. See .claude/rules/tenant-scoping.md.
 */
class GroupThreadsController extends Controller
{
    public function __construct(private GroupAudience $audience)
    {
    }

    /**
     * GET .../groups/{group_id}/threads[?scope=group|participant]
     *
     * Recently-active first (messages touch the thread, so updated_at IS the
     * activity clock). The listing is pre-filtered to what THIS caller may
     * read by GroupAudience::readableThreadsQuery — the same decision show()
     * makes per thread, so the list never advertises a conversation the caller
     * would then be refused.
     */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $query = $this->audience->readableThreadsQuery($request->user(), $group);

        // Not in this group at all -> 403, mirroring the feed: the group is
        // addressable by this admin, so pretending it has no conversations
        // would be a lie; they are simply not entitled to read them.
        if ($query === null) {
            abort(403, 'You are not entitled to this group\'s conversations.');
        }

        $scope = $request->query('scope');

        if ($scope !== null && ! in_array($scope, GroupThread::SCOPES, true)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['scope' => ['The scope filter must be one of: ' . implode(', ', GroupThread::SCOPES) . '.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $threads = $query
            ->with(['creator:id,name', 'aboutMembership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS])
            ->withCount('messages')
            ->withMax('messages as latest_message_at', 'created_at')
            ->when($scope !== null, fn ($q) => $q->where('scope', $scope))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 15));

        // The caller's bookmarks for just this page, fetched once rather than
        // per row. Keyed by thread so serialization stays a lookup.
        $reads = GroupThreadRead::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('group_thread_id', collect($threads->items())->pluck('id'))
            ->get()
            ->keyBy('group_thread_id');

        $threads->through(fn (GroupThread $thread) => $this->serializeThread($thread, $reads->get($thread->id)));

        return response()->json([
            'status' => 'success',
            'data' => $threads,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/threads
     *
     * Opens a thread, optionally with its first message in the same
     * transaction — a half-opened conversation never exists. For a
     * participant-scoped thread the target must be a PARTICIPANT membership of
     * THIS group: a thread "about" a guardian edge would name a relationship
     * rather than a person, and a membership from any other group (or tenant)
     * is invisible to the scoped lookup. Both are refused as validation (422),
     * not 404 — the id arrived in the payload, not the path, so there is no
     * resource being addressed.
     *
     * masjid_id is intentionally absent from the create payload: the
     * BelongsToMasjid creating hook stamps it from the bound tenant.
     */
    public function store(StoreGroupThreadRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $aboutMembershipId = null;
        $aboutContactId = null;

        if ($request->input('scope') === GroupThread::SCOPE_PARTICIPANT) {
            $about = $group->memberships()
                ->participants()->current()
                ->find($request->integer('about_membership_id'));

            if ($about === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That id names no participant of this group, so no conversation can be opened about them.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $aboutMembershipId = $about->id;
            $aboutContactId = $about->contact_id;
        }

        $uploads = $this->uploads($request);
        $hasFirstMessage = $request->filled('body') || $uploads !== [];

        try {
            $thread = DB::transaction(function () use ($request, $group, $aboutMembershipId, $uploads, $hasFirstMessage) {
                $thread = GroupThread::create([
                    'group_id' => $group->id,
                    // The AUTHENTICATED account, never a client-supplied name.
                    'created_by_user_id' => $request->user()?->id,
                    'subject' => $request->input('subject'),
                    'scope' => $request->input('scope'),
                    'about_membership_id' => $aboutMembershipId,
                    'retained_until' => $request->input('retained_until'),
                ]);

                if ($hasFirstMessage) {
                    $message = $thread->messages()->create([
                        'author_user_id' => $request->user()?->id,
                        // A photo-only message stores an empty body; the column
                        // is NOT NULL and "no text" is what was sent.
                        'body' => (string) ($request->input('body') ?? ''),
                    ]);

                    GroupMessageAttachments::store($message, $uploads);

                    // The opener has read what they just wrote; without this,
                    // their own first message would greet them as "unread".
                    $this->markRead($thread, $request->user(), (int) $message->id);
                }

                return $thread;
            });

            // A thread opened WITH a first message notifies like a reply would;
            // an empty thread shell notifies no one. A participant thread reaches
            // the ward's guardian(s); a group-wide thread reaches the feed audience
            // (the job decides from aboutContactId). afterCommit + fail-soft.
            if ($hasFirstMessage) {
                SendGroupNotificationJob::dispatch(
                    (int) $group->masjid_id,
                    (int) $group->id,
                    GroupNotificationEvent::GUARDIAN_THREAD_MESSAGE,
                    aboutContactId: $aboutContactId,
                    authorUserId: $request->user()?->id,
                    authorContactId: null,
                )->afterCommit();
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->serializeThread(
                    $this->withListAggregates($thread->fresh()),
                    $this->readMarker($thread, $request->user())
                ),
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET .../groups/{group_id}/threads/{thread_id}?page=&per_page=
     *
     * The conversation, oldest first (it reads top-down like one), paginated.
     * Viewing it moves the caller's read bookmark to now — which is what
     * "reading" means — so the serialized thread reports itself read.
     */
    public function show(Request $request, $masjid_id, $group_id, $thread_id)
    {
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        $this->authorizeThread($request->user(), $group, $thread);

        $mayReceiveMedia = $this->audience->mayReceiveThreadMedia($request->user(), $group, $thread);
        $viewerId = $request->user()?->id;

        $messages = $thread->messages()
            ->with(['author:id,name', 'authorContact:id,first_name,last_name', 'attachments'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($request->query('per_page', 50));

        // Opening the conversation is reading it — up to the newest message this
        // page actually SERVED, not the newest in the thread: a receipt must not
        // claim somebody saw a message that was never put in front of them.
        // Written BEFORE the receipts are computed, and it cannot matter: a
        // viewer is never listed as a reader of what they are looking at.
        $servedUpTo = collect($messages->items())->max('id');
        $this->markRead($thread, $request->user(), $servedUpTo !== null ? (int) $servedUpTo : null);

        $signals = GroupMessageSignals::forMessages($thread, $messages->items(), $request->user());

        $messages->through(fn (GroupMessage $message) => $this->serializeMessage(
            $message, $request, $masjid_id, $group_id, $mayReceiveMedia, $viewerId, $signals[(int) $message->id] ?? null
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->serializeThread(
                    $this->withListAggregates($thread),
                    $this->readMarker($thread, $request->user())
                ),
                'messages' => $messages,
            ],
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/threads/{thread_id}/messages
     *
     * Read entitlement is required to WRITE here — the deliberate difference
     * from the feed's write path. Publishing an announcement is administration;
     * speaking in a conversation belongs only to the people who are in it.
     */
    public function storeMessage(StoreGroupMessageRequest $request, $masjid_id, $group_id, $thread_id)
    {
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        $this->authorizeThread($request->user(), $group, $thread);

        if ($thread->isClosed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This conversation is closed; reopen it to continue.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $uploads = $this->uploads($request);

        try {
            $message = DB::transaction(function () use ($request, $thread, $uploads) {
                $message = $thread->messages()->create([
                    // The AUTHENTICATED account, never a client claim.
                    'author_user_id' => $request->user()?->id,
                    // A photo-only message stores an empty body (NOT NULL column).
                    'body' => (string) ($request->input('body') ?? ''),
                ]);

                // Same transaction, all or nothing: a photo that fails to write
                // rolls the message back and removes any photo already written.
                GroupMessageAttachments::store($message, $uploads);

                // You have read what you just wrote.
                $this->markRead($thread, $request->user(), (int) $message->id);

                return $message;
            });

            // A staff reply reaches the ward's guardian(s) (participant thread) or
            // the feed audience (group-wide thread) — the job decides from
            // aboutContactId. afterCommit + fail-soft.
            SendGroupNotificationJob::dispatch(
                (int) $group->masjid_id,
                (int) $group->id,
                GroupNotificationEvent::GUARDIAN_THREAD_MESSAGE,
                aboutContactId: $thread->aboutMembership?->contact_id,
                authorUserId: $request->user()?->id,
                authorContactId: null,
            )->afterCommit();

            return response()->json([
                'status' => 'success',
                // The sender sees what they just sent, photos included: they
                // supplied the bytes a moment ago.
                'data' => $this->serializeMessage(
                    $message->load(['author:id,name', 'attachments']),
                    $request, $masjid_id, $group_id, true, $request->user()?->id,
                    GroupMessageSignals::forMessages($thread, [$message], $request->user())[(int) $message->id] ?? null
                ),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT .../threads/{thread_id}/messages/{message_id}/reactions/{reaction}
     *
     * Add this caller's 🤲 / 👍 / 💯 / ❓. Idempotent — a second tap, or two
     * tabs, leave one row — which is why adding and removing are two verbs
     * rather than one "toggle" that a double-tap would undo.
     *
     * The gate is REPLYING's gate, check for check: the route's write gate
     * (`permission:manage contacts` / `teacher.leads`), then
     * mayReceiveThread(), then "not closed". The message is found THROUGH the
     * thread, so a message id from another conversation is a 404 even when the
     * caller may read this one.
     */
    public function react(Request $request, $masjid_id, $group_id, $thread_id, $message_id, $reaction)
    {
        return $this->setReaction($request, $group_id, $thread_id, $message_id, $reaction, true);
    }

    /** DELETE .../reactions/{reaction} — take it back. Idempotent the same way. */
    public function unreact(Request $request, $masjid_id, $group_id, $thread_id, $message_id, $reaction)
    {
        return $this->setReaction($request, $group_id, $thread_id, $message_id, $reaction, false);
    }

    /**
     * GET .../threads/{thread_id}/messages/{message_id}/attachments/{attachment_id}
     *
     * Streams one photo off the PRIVATE disk. Re-resolves the WHOLE chain —
     * masjid -> group -> thread -> message -> attachment, each found through its
     * parent — so a foreign id anywhere is a 404, then asks GroupAudience
     * whether this reader may have the photos in this conversation at all.
     */
    public function downloadAttachment(Request $request, $masjid_id, $group_id, $thread_id, $message_id, $attachment_id)
    {
        Masjid::findOrFail($masjid_id);
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);
        $message = $thread->messages()->findOrFail($message_id);
        $attachment = $message->attachments()->findOrFail($attachment_id);

        if (! $this->audience->mayReceiveThreadMedia($request->user(), $group, $thread)) {
            abort(403, 'You are not entitled to the photos in this conversation.');
        }

        if (! $attachment->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This photo is no longer stored on the server.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $attachment->storage()->download(
            $attachment->path,
            $attachment->original_name,
            [
                // Sniffed from the bytes at upload and held to the allowlist.
                'Content-Type' => $attachment->mime_type,
                // No proxy may hold one family's photo for the next caller.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * POST .../threads/{thread_id}/messages/{message_id}/attachments/{attachment_id}/playback
     *
     * Mint a playback ticket for ONE conversation video. Same chain and same
     * disclosure question as downloadAttachment, asked here at MINT time and
     * asked again by GroupMediaPlaybackController on every ranged request.
     */
    public function playbackTicket(Request $request, $masjid_id, $group_id, $thread_id, $message_id, $attachment_id)
    {
        Masjid::findOrFail($masjid_id);
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);
        $message = $thread->messages()->findOrFail($message_id);
        $attachment = $message->attachments()->findOrFail($attachment_id);

        if (! $this->audience->mayReceiveThreadMedia($request->user(), $group, $thread)) {
            abort(403, 'You are not entitled to the photos in this conversation.');
        }

        if (! GroupMedia::isPlayable($attachment)) {
            return response()->json([
                'status' => 'failed',
                'data' => 'That attachment is not a video.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'url' => GroupMedia::messageTicket(
                    $masjid_id, $group_id, $thread->id, $message->id, $attachment->id,
                    GroupMedia::VIEWER_STAFF, (int) $request->user()->id,
                ),
                'expires_in' => GroupMedia::playbackTtlMinutes() * 60,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/threads/{thread_id}/close
     *
     * State, not deletion: the conversation stays readable, it just takes no
     * further messages. Idempotent — closing a closed thread reports it closed
     * rather than erroring, because the caller's intent is already true.
     */
    public function close(Request $request, $masjid_id, $group_id, $thread_id)
    {
        return $this->setClosed($request, $group_id, $thread_id, true);
    }

    /** POST .../groups/{group_id}/threads/{thread_id}/reopen — the inverse, equally idempotent. */
    public function reopen(Request $request, $masjid_id, $group_id, $thread_id)
    {
        return $this->setClosed($request, $group_id, $thread_id, false);
    }

    /**
     * DELETE .../groups/{group_id}/threads/{thread_id}
     *
     * Soft delete, deliberately: the conversation disappears from the list
     * immediately, but a mis-click must not be the thing that destroys the
     * record of what was discussed about a child. The rows go when the
     * retention window closes and the `groups:purge-feed` sweep runs.
     */
    public function destroy($masjid_id, $group_id, $thread_id)
    {
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        $thread->delete();

        return response()->json([
            'status' => 'success',
            'data' => ['id' => $thread->id, 'deleted_at' => optional($thread->deleted_at)->toIso8601String()],
        ], Response::HTTP_OK);
    }

    /** Shared body of react/unreact. */
    private function setReaction(Request $request, $group_id, $thread_id, $message_id, $reaction, bool $on)
    {
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        $this->authorizeThread($request->user(), $group, $thread);

        $message = $thread->messages()->findOrFail($message_id);

        if (! GroupMessageReaction::isAllowed($reaction)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['reaction' => ['A reaction must be one of: '.implode(' ', GroupMessageReaction::REACTIONS).'.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($thread->isClosed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This conversation is closed; reopen it to continue.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $key = [
            'group_message_id' => $message->id,
            'reaction' => $reaction,
            // The AUTHENTICATED account, never a client claim.
            'user_id' => $request->user()->id,
        ];

        if ($on) {
            // createOrFirst: the unique key settles a race between two taps
            // instead of the second one 500ing.
            GroupMessageReaction::createOrFirst($key);
        } else {
            GroupMessageReaction::query()->where($key)->delete();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'message_id' => (int) $message->id,
                'reactions' => GroupMessageSignals::reactionsFor($message, $request->user()),
            ],
        ], Response::HTTP_OK);
    }

    /** Shared body of close/reopen — one write path, two route verbs. */
    private function setClosed(Request $request, $group_id, $thread_id, bool $closed)
    {
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        if ($thread->isClosed() !== $closed) {
            $thread->update(['closed_at' => $closed ? now() : null]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serializeThread(
                $this->withListAggregates($thread->fresh()),
                $this->readMarker($thread, $request->user())
            ),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Refuse a conversation this caller is not entitled to.
     *
     * 403, not 404, for the same reason as the feed: the group itself is
     * addressable by this admin, so pretending the thread does not exist would
     * make the API harder to reason about. 404 stays reserved for an id
     * belonging to another organization.
     */
    private function authorizeThread(?User $user, Group $group, GroupThread $thread): void
    {
        if ($this->audience->mayReceiveThread($user, $group, $thread)) {
            return;
        }

        abort(403, 'You are not entitled to this conversation.');
    }

    /**
     * Move the caller's read bookmark to now, and its message high-water mark
     * forward to `$upToMessageId` (the newest message they were shown). Keyed
     * on the (thread, user) unique key; masjid_id is stamped by the creating
     * hook from the bound tenant. See GroupThreadRead::advance().
     */
    private function markRead(GroupThread $thread, ?User $user, ?int $upToMessageId = null): void
    {
        if ($user === null) {
            return;
        }

        GroupThreadRead::advance((int) $thread->id, (int) $user->id, null, $upToMessageId);
    }

    /** The caller's bookmark on one thread, if any. */
    private function readMarker(GroupThread $thread, ?User $user): ?GroupThreadRead
    {
        if ($user === null) {
            return null;
        }

        return GroupThreadRead::query()
            ->where('group_thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Load onto a single thread the same aggregates the index query computes,
     * so serializeThread() sees one shape from both paths.
     */
    private function withListAggregates(GroupThread $thread): GroupThread
    {
        $thread->loadMissing(['creator:id,name', 'aboutMembership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS]);

        $thread->setAttribute('messages_count', $thread->messages()->count());
        $thread->setAttribute('latest_message_at', $thread->messages()->max('created_at'));

        return $thread;
    }

    /**
     * One thread as an entitled reader sees it.
     *
     * `about` is populated only on a participant-scoped thread: anyone entitled
     * to SEE such a thread (a leader, the member, their guardian) is entitled
     * to know whom it concerns — that is what the thread IS. A target whose
     * membership has since left the roster serializes with a null contact
     * rather than vanishing, so the record stays honest about having a subject.
     *
     * @return array<string,mixed>
     */
    private function serializeThread(GroupThread $thread, ?GroupThreadRead $read): array
    {
        $about = null;

        if ($thread->about_membership_id !== null) {
            $contact = $thread->aboutMembership?->contact;

            $about = [
                'membership_id' => $thread->about_membership_id,
                'contact' => $contact ? [
                    'id' => $contact->id,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                ] : null,
            ];
        }

        $latestRaw = $thread->getAttribute('latest_message_at');
        $latest = $latestRaw !== null ? Carbon::parse($latestRaw) : null;
        $lastRead = $read?->last_read_at;

        return [
            'id' => $thread->id,
            'group_id' => $thread->group_id,
            'subject' => $thread->subject,
            'scope' => $thread->threadScope(),
            'about' => $about,
            'created_by' => $thread->creator ? ['id' => $thread->creator->id, 'name' => $thread->creator->name] : null,
            'is_closed' => $thread->isClosed(),
            'closed_at' => optional($thread->closed_at)->toIso8601String(),
            'retained_until' => optional($thread->retained_until)->toDateString(),
            'message_count' => (int) ($thread->getAttribute('messages_count') ?? 0),
            'latest_message_at' => optional($latest)->toIso8601String(),
            'last_read_at' => optional($lastRead)->toIso8601String(),
            // Unread means "there is something newer than my bookmark" — an
            // empty thread is never unread, and a bookmark taken in the same
            // second as the newest message counts as read (the marker is
            // always written after the message it follows).
            'unread' => $latest !== null && ($lastRead === null || $lastRead->lt($latest)),
            'created_at' => optional($thread->created_at)->toIso8601String(),
            'updated_at' => optional($thread->updated_at)->toIso8601String(),
        ];
    }

    /**
     * One message as an entitled staff reader sees it.
     *
     * Photos are OMITTED, not merely un-downloadable, for a reader who may not
     * have them — a filename is itself a disclosure — and `media_withheld` says
     * so, as the class story does. `is_mine` lets the screen put the reader's
     * own messages on their side of the conversation.
     *
     * @return array<string,mixed>
     */
    private function serializeMessage(
        GroupMessage $message,
        Request $request,
        $masjid_id,
        $group_id,
        bool $mayReceiveMedia,
        $viewerId,
        ?array $signals = null
    ): array {
        $attachments = $message->relationLoaded('attachments') ? $message->attachments : collect();

        return [
            'id' => $message->id,
            'thread_id' => $message->group_thread_id,
            'body' => $message->body,
            'attachments' => $mayReceiveMedia
                ? $attachments->map(fn ($attachment) => $attachment->toAudienceArray() + [
                    // Back at the authenticated endpoint of the realm this
                    // request came through; the SPA fetches it with the token.
                    'download_path' => sprintf(
                        '/api/%s/masjids/%s/groups/%s/threads/%d/messages/%d/attachments/%d',
                        $this->realm($request), $masjid_id, $group_id,
                        $message->group_thread_id, $message->id, $attachment->id
                    ),
                    // Video only: where to ASK for a playback ticket, not a
                    // ticket itself. See GroupPostsController::serialize.
                    'playback_ticket_path' => GroupMedia::isPlayable($attachment)
                        ? sprintf(
                            '/api/%s/masjids/%s/groups/%s/threads/%d/messages/%d/attachments/%d/playback',
                            $this->realm($request), $masjid_id, $group_id,
                            $message->group_thread_id, $message->id, $attachment->id
                        )
                        : null,
                ])->values()->all()
                : [],
            'media_withheld' => ! $mayReceiveMedia && $attachments->isNotEmpty(),
            'is_mine' => $message->author_user_id !== null
                && $viewerId !== null
                && (int) $message->author_user_id === (int) $viewerId,
            // Since T-015f a message may be written by a PARENT. Both principals
            // resolve through GroupMessage::authorLabel(), and the staff surface
            // is told which — a parent's reply must be visibly a parent's, not an
            // unattributed line a teacher might answer as though a colleague
            // wrote it. `id` is emitted only for a staff author: a contact id is
            // not a staff identifier and does not belong in this payload.
            'author' => $message->authorLabel() !== null
                ? array_filter([
                    'id' => $message->authorIsParent() ? null : $message->author?->id,
                    'name' => $message->authorLabel(),
                ], static fn ($v) => $v !== null)
                : null,
            'author_is_parent' => $message->authorIsParent(),
            // 🤲 👍 💯 ❓ with counts and, for staff, every name; and who has
            // read this message. Staff see parents' and colleagues' receipts —
            // see GroupMessageSignals for what a parent is shown instead.
            'reactions' => $signals['reactions'] ?? [],
            'read_by' => $signals['read_by'] ?? [],
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }

    /**
     * Vertical-aware labelling, same source as the other group controllers:
     * what a group is CALLED comes from the tenant's terminology pack, never a
     * hardcoded string. See .claude/rules/verticals.md.
     *
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'group_label' => $masjid?->term('groups') ?? 'Groups',
            'thread_scopes' => GroupThread::SCOPES,
            'max_message_length' => (int) config('groups.messaging.max_message_length', 0),
            'upload_key' => GroupPostFormRequest::UPLOAD_KEY,
            'accepted_image_types' => (array) config('groups.media.mime_types', []),
            'max_image_size_kb' => (int) config('groups.media.max_size_kb', 0),
            'max_images_per_message' => (int) config('groups.media.max_per_post', 0),
            'reactions' => GroupMessageReaction::catalogue(),
            // ADDITIVE, exactly as on the story side — the image-named keys are
            // a wire contract two native apps and the admin SPA read.
        ] + GroupMedia::videoMeta('max_videos_per_message');
    }

    /**
     * Which staff realm this request came through. The controller is mounted
     * under both /api/admin and /api/teacher, and a link into the other one
     * would be refused (the admin realm rejects a Teacher login).
     */
    private function realm(Request $request): string
    {
        return $request->is('api/teacher/*') ? 'teacher' : 'admin';
    }

    /**
     * The uploaded photos, as a plain list.
     *
     * @return array<int,\Illuminate\Http\UploadedFile>
     */
    private function uploads(Request $request): array
    {
        // Two bags, one list — images and video are validated separately (their
        // own allowlist, ceiling and count) and stored identically. See
        // GroupPostsController::uploads for the same note.
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
