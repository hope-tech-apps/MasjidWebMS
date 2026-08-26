<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreGroupMessageRequest;
use App\Http\Requests\Admin\Groups\StoreGroupThreadRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Errors;
use App\Support\GroupAudience;
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
 * Tenant isolation is not hand-rolled: `tenant` middleware binds TenantContext
 * and BelongsToMasjid auto-scopes Group, GroupThread, GroupMessage and
 * GroupThreadRead — a foreign organization's id anywhere in the chain is a MISS
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

        if ($request->input('scope') === GroupThread::SCOPE_PARTICIPANT) {
            $about = $group->memberships()
                ->participants()
                ->find($request->integer('about_membership_id'));

            if ($about === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That id names no participant of this group, so no conversation can be opened about them.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $aboutMembershipId = $about->id;
        }

        try {
            $thread = DB::transaction(function () use ($request, $group, $aboutMembershipId) {
                $thread = GroupThread::create([
                    'group_id' => $group->id,
                    // The AUTHENTICATED account, never a client-supplied name.
                    'created_by_user_id' => $request->user()?->id,
                    'subject' => $request->input('subject'),
                    'scope' => $request->input('scope'),
                    'about_membership_id' => $aboutMembershipId,
                    'retained_until' => $request->input('retained_until'),
                ]);

                if ($request->filled('body')) {
                    $thread->messages()->create([
                        'author_user_id' => $request->user()?->id,
                        'body' => $request->input('body'),
                    ]);

                    // The opener has read what they just wrote; without this,
                    // their own first message would greet them as "unread".
                    $this->markRead($thread, $request->user());
                }

                return $thread;
            });

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

        $messages = $thread->messages()
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($request->query('per_page', 50))
            ->through(fn (GroupMessage $message) => $this->serializeMessage($message));

        $this->markRead($thread, $request->user());

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

        try {
            $message = DB::transaction(function () use ($request, $thread) {
                $message = $thread->messages()->create([
                    // The AUTHENTICATED account, never a client claim.
                    'author_user_id' => $request->user()?->id,
                    'body' => $request->input('body'),
                ]);

                // You have read what you just wrote.
                $this->markRead($thread, $request->user());

                return $message;
            });

            return response()->json([
                'status' => 'success',
                'data' => $this->serializeMessage($message->load('author:id,name')),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
     * Move the caller's read bookmark to now. updateOrCreate against the
     * (thread, user) unique key, so concurrent views race to the same row
     * rather than minting duplicates; masjid_id is stamped by the creating
     * hook from the bound tenant.
     */
    private function markRead(GroupThread $thread, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        GroupThreadRead::updateOrCreate(
            ['group_thread_id' => $thread->id, 'user_id' => $user->id],
            ['last_read_at' => now()],
        );
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

    /** @return array<string,mixed> */
    private function serializeMessage(GroupMessage $message): array
    {
        return [
            'id' => $message->id,
            'thread_id' => $message->group_thread_id,
            'body' => $message->body,
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
        ];
    }
}
