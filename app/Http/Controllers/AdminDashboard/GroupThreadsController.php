<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\GroupNotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\GroupPostFormRequest;
use App\Http\Requests\Admin\Groups\StoreGroupMessageRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Http\Requests\Admin\Groups\StoreGroupThreadRequest;
use App\Http\Requests\Admin\Groups\UpdateGroupMessageRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageEdit;
use App\Models\GroupMessageReaction;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Groups\GroupThreadWriter;
use App\Support\Errors;
use App\Support\GroupAudience;
use App\Support\GroupMedia;
use App\Support\GroupMessageAttachments;
use App\Support\GroupMessageSignals;
use App\Support\GroupThreadUnread;
use App\Support\LineEndings;
use App\Support\ScheduledTime;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * EDITING (W7, 2026-10-01). updateMessage() lets the AUTHOR of a sent staff
 * message change its words; edits() lets the office read what it said before.
 * See updateMessage() for the rules and why an edit notifies nobody.
 *
 * Tenant isolation is not hand-rolled: `tenant` middleware binds TenantContext
 * and BelongsToMasjid auto-scopes Group, GroupThread, GroupMessage,
 * GroupMessageAttachment and GroupThreadRead — a foreign organization's id anywhere in the chain is a MISS
 * (404), never a filtered row. findOrFail stays OUTSIDE every try/catch so the
 * JSON renderer turns it into a clean 404. See .claude/rules/tenant-scoping.md.
 */
class GroupThreadsController extends Controller
{
    /** The most messages one page of a conversation may carry. */
    public const MAX_MESSAGES_PER_PAGE = 200;

    /** The most conversations one list page may carry. */
    private const MAX_THREADS_PER_PAGE = 100;

    public function __construct(private GroupAudience $audience, private GroupThreadWriter $writer)
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
            ->paginate($this->threadsPerPage($request));

        // The caller's bookmarks for just this page, fetched once rather than
        // per row. Keyed by thread so serialization stays a lookup.
        $reads = GroupThreadRead::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('group_thread_id', collect($threads->items())->pluck('id'))
            ->get()
            ->keyBy('group_thread_id');

        // ONE grouped query for the page's per-thread counts and the whole class's
        // total (the badge is exact even when the list is paginated). The total
        // ignores the ?scope filter: it is the class's number, not this view's.
        $userId = (int) $request->user()->id;
        $pageCounts = GroupThreadUnread::byThread(
            $userId, [(int) $group->id], null, collect($threads->items())->pluck('id')->map(fn ($id) => (int) $id)->all()
        )[(int) $group->id] ?? [];
        $unreadTotal = GroupThreadUnread::byGroup($userId, [(int) $group->id], $this->audience->readableThreadsQuery($request->user(), $group))[(int) $group->id] ?? 0;

        $threads->through(fn (GroupThread $thread) => $this->serializeThread(
            $thread, $reads->get($thread->id), $pageCounts[(int) $thread->id] ?? 0
        ));

        return response()->json([
            'status' => 'success',
            'data' => $threads,
            'meta' => $this->meta() + ['unread_total' => $unreadTotal],
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

        $about = null;

        if ($request->input('scope') === GroupThread::SCOPE_PARTICIPANT) {
            $about = $this->writer->aboutMembership($group, $request->integer('about_membership_id'));

            if ($about === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That id names no participant of this group, so no conversation can be opened about them.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            // The write itself is GroupThreadWriter's, shared with the scheduled
            // send: the thread, the first message, its photos, the opener's read
            // marker and the email to the people it reaches.
            [$thread] = $this->writer->open(
                $group,
                $request->user()?->id,
                (string) $request->input('subject'),
                (string) $request->input('scope'),
                $about,
                $request->input('body') !== null ? (string) $request->input('body') : null,
                $this->uploads($request),
                $request->input('retained_until'),
            );

            return response()->json([
                'status' => 'success',
                'data' => $this->serializeThread(
                    $this->withListAggregates($thread->fresh()),
                    $this->readMarker($thread, $request->user()),
                    $this->unreadIn($thread, $request->user())
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
            ->paginate($this->messagesPerPage($request));

        // Opening the conversation is reading it — up to the newest message this
        // page actually SERVED, not the newest in the thread: a receipt must not
        // claim somebody saw a message that was never put in front of them.
        // Written BEFORE the receipts are computed, and it cannot matter: a
        // viewer is never listed as a reader of what they are looking at.
        $servedUpTo = collect($messages->items())->max('id');
        $this->markRead($thread, $request->user(), $servedUpTo !== null ? (int) $servedUpTo : null);

        $signals = GroupMessageSignals::forMessages($thread, $messages->items(), $request->user());

        $messages->through(fn (GroupMessage $message) => $this->serializeMessage(
            $message, $request, $masjid_id, $group_id, $mayReceiveMedia, $viewerId, $signals[(int) $message->id] ?? null,
            ! $thread->isClosed()
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->serializeThread(
                    $this->withListAggregates($thread),
                    $this->readMarker($thread, $request->user()),
                    $this->unreadIn($thread, $request->user())
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

                // You have read what you just wrote, but only if nothing from anyone
                // else sits between your bookmark and it. A reply from a screen that
                // was open while a parent wrote must not carry the bookmark past that
                // message: it would never be unread, and the receipt would claim it
                // was seen.
                if ($request->user() !== null
                    && ! GroupThreadUnread::hasUnseenFromOthers((int) $request->user()->id, (int) $thread->id)) {
                    $this->markRead($thread, $request->user(), (int) $message->id);
                }

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
     * PUT .../groups/{group_id}/threads/{thread_id}/messages/{message_id}
     *
     * Change the WORDS of a message already sent (W7, 2026-10-01). One method,
     * mounted in both staff realms (admin under `manage contacts`, teacher under
     * `teacher.leads`).
     *
     *  - WHO: only the message's AUTHOR, a staff account, who can still read the
     *    conversation. `author_user_id` must equal the signed-in user, which in
     *    one test refuses a parent's message (no author_user_id), a colleague's,
     *    and one whose author account was deleted (nulled). The office gets no
     *    exemption and neither does a SuperAdmin; families do not edit here.
     *  - WHAT: the body, at the ceiling sending has. Photos, videos, subject and
     *    scope are refused by the request. An empty body is allowed only when
     *    the message has an attachment.
     *  - WHEN: any time, but not in a closed conversation. A message that is
     *    still only a scheduled conversation is not a row here, so it is a 404;
     *    its own W5 rules stand.
     *  - TRACE: GroupMessage has always promised no per-message eraser quietly
     *    rewrites what a parent was told, so a REAL edit writes the old text to
     *    group_message_edits and stamps `edited_at`, in one transaction, under a
     *    row lock. An unchanged body writes and stamps nothing.
     *  - QUIET: an edit sends no email or push (the nudge for the original went
     *    out already, and the nudge is content-free), does not touch the
     *    thread's updated_at (GroupMessage::$touches would float an old
     *    conversation to the top of every list, so the save runs inside
     *    GroupThread::withoutTouching: the model being TOUCHED is the one
     *    named), moves nobody's read marker, and leaves reactions and
     *    "seen by" as they were.
     *
     * Order: the thread is found through the group (foreign id: 404), then the
     * read gate (403), then the message through the thread (foreign or
     * other-thread id: 404), the author gate (403), the closed gate (422).
     */
    public function updateMessage(UpdateGroupMessageRequest $request, $masjid_id, $group_id, $thread_id, $message_id)
    {
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);
        $user = $request->user();

        $this->authorizeThread($user, $group, $thread);

        $message = $thread->messages()->findOrFail($message_id);

        if ($message->author_user_id === null || (int) $message->author_user_id !== (int) $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only edit a message you wrote.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($thread->isClosed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This conversation is closed; reopen it to continue.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = (string) ($request->input('body') ?? '');

        if ($body === '' && ! $message->attachments()->exists()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['body' => ['Write a message.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Fail closed, with the author's text still on their screen, for the few
        // seconds a deploy runs this code before `migrate` has added the audit
        // table and the marker (never a 500 that loses what they typed).
        if (! $this->editingIsAvailable()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Editing is not available for a moment. Keep your text and try again shortly.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $message = DB::transaction(function () use ($thread, $message, $user, $body) {
            // The lock settles the one real race: the same author in two tabs.
            // Last write wins and both versions are audited.
            $locked = $thread->messages()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            // Line endings are not words: a message stored with "\r\n" (multipart, before
            // 2026-10-01) and saved untouched must leave no history row (LineEndings).
            if (trim(LineEndings::normalise($locked->body)) === trim($body)) {
                return $locked;
            }

            GroupMessageEdit::create([
                'group_message_id' => $locked->id,
                'editor_user_id' => $user->id,
                'previous_body' => (string) $locked->body,
            ]);

            GroupThread::withoutTouching(function () use ($locked, $body): void {
                $locked->forceFill(['body' => $body, 'edited_at' => now()])->save();
            });

            return $locked;
        });

        return response()->json([
            'status' => 'success',
            'data' => $this->serializeMessage(
                $message->load(['author:id,name', 'attachments']),
                $request, $masjid_id, $group_id,
                $this->audience->mayReceiveThreadMedia($user, $group, $thread),
                $user->id,
                GroupMessageSignals::forMessages($thread, [$message], $user)[(int) $message->id] ?? null,
                ! $thread->isClosed()
            ),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../threads/{thread_id}/messages/{message_id}/edits   (admin realm only)
     *
     * What an edited message said before each edit, oldest first, for the
     * office. Behind `manage contacts` on the route and the same thread read
     * gate as the message itself, so the office reads an earlier text only
     * where it may read the conversation. No teacher or family variant exists:
     * the history is the office's audit, and no message payload carries it.
     */
    public function edits(Request $request, $masjid_id, $group_id, $thread_id, $message_id)
    {
        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        $this->authorizeThread($request->user(), $group, $thread);

        $message = $thread->messages()->findOrFail($message_id);

        if (! $this->editingIsAvailable()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Earlier versions are not available for a moment. Try again shortly.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $edits = $message->edits()->with('editor:id,name')->orderBy('id')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'message_id' => (int) $message->id,
                'edited_at' => optional($message->edited_at)->toIso8601String(),
                'current_body' => $message->body,
                'edits' => $edits->map(fn (GroupMessageEdit $edit) => [
                    'id' => (int) $edit->id,
                    'previous_body' => $edit->previous_body,
                    // A name, never an id; null once that account is deleted.
                    'edited_by' => $edit->editor?->name,
                    // When this earlier text stopped being the current one.
                    'replaced_at' => optional($edit->created_at)->toIso8601String(),
                ])->values()->all(),
            ],
        ], Response::HTTP_OK);
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
                $this->readMarker($thread, $request->user()),
                $this->unreadIn($thread, $request->user())
            ),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * True once `migrate` has added the edit marker and the audit table. A deploy
     * runs the new code for a few seconds before it, and an edit that needs
     * either must refuse (503) rather than 500 and lose what the author typed.
     */
    private function editingIsAvailable(): bool
    {
        return Schema::hasColumn('group_messages', 'edited_at')
            && Schema::hasTable('group_message_edits');
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

    /**
     * Page size for the conversation. The default stays 50 (the native apps ask for
     * nothing else); a caller that wants more may, up to a ceiling, so a teacher
     * can read a long conversation in a few requests and the bookmark (which moves
     * to the newest message SERVED) reaches the end of it.
     */
    /** The list's page size: 15 by default, never below 1 (a 0 divides by zero), never above the cap. */
    private function threadsPerPage(Request $request): int
    {
        return max(1, min((int) $request->query('per_page', 15), self::MAX_THREADS_PER_PAGE));
    }

    private function messagesPerPage(Request $request): int
    {
        return max(1, min((int) $request->query('per_page', 50), self::MAX_MESSAGES_PER_PAGE));
    }

    /** How many messages the caller has not yet seen in one thread. */
    private function unreadIn(GroupThread $thread, ?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return GroupThreadUnread::byThread((int) $user->id, [(int) $thread->group_id], null, [(int) $thread->id])
            [(int) $thread->group_id][(int) $thread->id] ?? 0;
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
    private function serializeThread(GroupThread $thread, ?GroupThreadRead $read, int $unreadCount = 0): array
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
            // How many messages from other people this reader has not seen
            // (GroupThreadUnread). `unread` keeps its key for the screens and
            // native apps that read it and now simply means "that is above zero",
            // so the pill and the number can never disagree.
            'unread_count' => $unreadCount,
            'unread' => $unreadCount > 0,
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
        ?array $signals = null,
        bool $threadOpen = true
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
            'is_mine' => $this->isMine($message, $viewerId),
            // The author may change the words of their own message while the
            // conversation is open (updateMessage). This viewer already passed
            // the read gate to be shown it; the route's write gate is the rest.
            'can_edit' => $threadOpen && $this->isMine($message, $viewerId),
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
            // Null for a message never edited, and for every row before the
            // column exists. Never an editor or an earlier text: those are the
            // office's, behind the `edits` route.
            'edited_at' => optional($message->edited_at)->toIso8601String(),
        ];
    }

    private function isMine(GroupMessage $message, $viewerId): bool
    {
        return $message->author_user_id !== null
            && $viewerId !== null
            && (int) $message->author_user_id === (int) $viewerId;
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
            // "Send later" for a NEW conversation: the SCHOOL's zone the field is read
            // in and how far ahead it may go, so the compose box can label its time
            // before anything has been scheduled.
            'scheduling' => [
                'timezone' => ScheduledTime::schoolTimezone(),
                'max_days_ahead' => ScheduledTime::maxDaysAhead(),
            ],
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
