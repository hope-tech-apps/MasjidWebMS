<?php

namespace App\Http\Controllers\Family;

use App\Enums\GroupNotificationEvent;
use App\Http\Requests\Family\StoreFamilyMessageRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\GroupMessage;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The conversations a parent is a party to (T-015e).
 *
 * The decision is `GroupAudience::readableThreadsQuery()` / `mayReceiveThread()`
 * — unchanged, and now simply reachable with a Contact as the principal. Which
 * threads that yields for a guardian, and why, is worth restating because it is
 * the sharpest privacy line in the product:
 *
 *   - a GROUP-WIDE thread is the feed audience, so it is consent-gated exactly
 *     like the class story;
 *   - a PARTICIPANT thread is readable only by the group's leaders, the member
 *     it names, and a guardian whose edge names THAT member as their ward.
 *     Consent is deliberately NOT consulted — requiring feed consent to talk to
 *     the teacher about your own child would invert what consent protects
 *     (.claude/rules/groups.md, "consent gates broadcasts, not conversations").
 *
 * ANOTHER FAMILY'S PARTICIPANT THREAD IS EXACTLY WHAT THIS EXCLUDES, and it is
 * excluded at QUERY level rather than by filtering a fetched page: a thread this
 * parent may not read is never selected, so it cannot appear in a page, in a
 * paginator total, or as the difference between two counts. Those threads are
 * where a teacher and a guardian discuss a safeguarding concern.
 *
 * ---------------------------------------------------------------------------
 * A PARENT CAN NOW REPLY, AND BE MARKED AS HAVING READ (T-015f)
 * ---------------------------------------------------------------------------
 *
 * This class used to document the opposite: `group_thread_reads.user_id` was
 * NOT NULL against `users`, so there was no column a Contact could be written
 * into, and rather than serve a permanently-false `unread` the slice served
 * none. Both halves are now real columns — `group_messages.author_contact_id`
 * and `group_thread_reads.contact_id` — so this surface writes exactly two
 * things and no more: a reply, and the reader's own bookmark.
 *
 * WHAT A REPLY STILL MAY NOT DO. It cannot start a conversation (a parent
 * opening a thread about their own child would route around the teacher who
 * decides what is discussed and where), it cannot reopen a closed one, and it
 * cannot reach a thread `mayReceiveThread()` refuses — which is the same
 * decision the read side already resolves through, not a second one.
 */
class GroupThreadsController extends FamilyController
{
    /**
     * GET /api/family/masjids/{masjid_id}/groups/{group_id}/threads
     */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = $this->group($group_id);

        $query = $this->audience->readableThreadsQuery($this->contact(), $group);

        // Null means "not in this group at all" — 403, mirroring the feed and
        // the staff surface. "In the group with nothing to read" is an empty
        // 200, which is a different fact and must stay distinguishable.
        if ($query === null) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this group\'s conversations.');
        }

        $threads = $query
            ->with(['aboutMembership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS])
            ->withCount('messages')
            ->withMax('messages as latest_message_at', 'created_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 15));

        // This parent's OWN bookmarks for the threads on this page — one query,
        // and only for the ids already selected, so an unread flag costs a
        // lookup rather than a query per row. Nobody else's read state is
        // fetched or serve-able: a bookmark says when YOU last looked.
        $reads = GroupThreadRead::query()
            ->where('contact_id', $this->contact()?->id)
            ->whereIn('group_thread_id', collect($threads->items())->pluck('id')->all())
            ->pluck('last_read_at', 'group_thread_id');

        $threads->through(fn (GroupThread $thread) => $this->serializeThread(
            $thread,
            $reads->get($thread->id)
        ));

        return response()->json([
            'status' => 'success',
            'data' => $threads,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../threads/{thread_id}
     *
     * The single-thread check is `mayReceiveThread()` and the listing filter is
     * `readableThreadsQuery()` — two entry points to one decision, so a thread
     * the listing shows is a thread this endpoint serves and vice versa.
     */
    public function show(Request $request, $masjid_id, $group_id, $thread_id)
    {
        $group = $this->group($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        if (! $this->audience->mayReceiveThread($this->contact(), $group, $thread)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this conversation.');
        }

        $messages = $thread->messages()
            ->with(['author:id,name', 'authorContact:id,first_name,last_name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($this->perPage($request, 50))
            ->through(fn (GroupMessage $message) => $this->serializeMessage($message));

        // Opening a conversation is reading it. The bookmark is the READER'S
        // own and nobody else's — it records that this parent has seen it, and
        // is not visible to, nor writable by, any other principal.
        $this->markRead($thread);

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->serializeThread($this->withAggregates($thread)),
                'messages' => $messages,
            ],
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    private function withAggregates(GroupThread $thread): GroupThread
    {
        $thread->loadMissing(['aboutMembership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS]);

        $thread->setAttribute('messages_count', $thread->messages()->count());
        $thread->setAttribute('latest_message_at', $thread->messages()->max('created_at'));

        return $thread;
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * POST .../threads/{thread_id}/messages — the parent's reply.
     *
     * The ONLY write in the family realm besides sign-in, and it is deliberately
     * the narrowest one that makes a conversation a conversation.
     *
     * AUTHORISATION IS THE READ DECISION, unchanged: `mayReceiveThread()`. A
     * parent may answer exactly the threads they may see, so there is no second
     * rule that could drift from the first — and in particular no rule that
     * could let a reply reach a thread the listing would never show them.
     *
     * THE AUTHOR COMES FROM THE TOKEN, never the payload. `author_contact_id`
     * is the authenticated contact; there is no field a client could send to
     * claim authorship of somebody else's message, which is the whole reason
     * attributing a message to a Contact is honest now and was not before
     * T-015c.
     */
    public function storeMessage(StoreFamilyMessageRequest $request, $masjid_id, $group_id, $thread_id)
    {
        $group = $this->group($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        if (! $this->audience->mayReceiveThread($this->contact(), $group, $thread)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this conversation.');
        }

        if ($thread->isClosed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This conversation has been closed by the school.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = DB::transaction(function () use ($thread, $request) {
            $message = $thread->messages()->create([
                'author_contact_id' => $this->contact()->id,
                'body' => $request->validated('body'),
            ]);

            // You have read what you just wrote.
            $this->markRead($thread);

            return $message;
        });

        // A parent's reply notifies the class's teacher(s). The author (this
        // parent) is skipped by the resolver. afterCommit + fail-soft.
        SendGroupNotificationJob::dispatch(
            (int) $group->masjid_id,
            (int) $group->id,
            GroupNotificationEvent::TEACHER_THREAD_MESSAGE,
            aboutContactId: null,
            authorUserId: null,
            authorContactId: $this->contact()->id,
        )->afterCommit();

        return response()->json([
            'status' => 'success',
            'data' => $this->serializeMessage(
                $message->load(['author:id,name', 'authorContact:id,first_name,last_name'])
            ),
        ], Response::HTTP_CREATED);
    }

    /**
     * Move THIS parent's read bookmark to now.
     *
     * updateOrCreate against (thread, contact), which the T-015f migration made
     * unique, so two tabs race to the same row rather than minting duplicates.
     * `user_id` stays null: a parent's read is not a staff read, and writing one
     * into the staff column would attribute it to an account id that means
     * something else entirely.
     */
    private function markRead(GroupThread $thread): void
    {
        $contact = $this->contact();

        if ($contact === null) {
            return;
        }

        GroupThreadRead::updateOrCreate(
            ['group_thread_id' => $thread->id, 'contact_id' => $contact->id],
            ['last_read_at' => now()],
        );
    }

    private function serializeThread(GroupThread $thread, $lastReadAt = null): array
    {
        $about = null;

        if ($thread->about_membership_id !== null) {
            $contact = $thread->aboutMembership?->contact;

            // A participant thread only ever reaches a guardian of the member it
            // names, so the name in here is always their own ward — never
            // another family's child. That is a property of
            // `readableThreadsQuery`, upstream, and is not re-checked here on
            // purpose: one decision, in one place.
            $about = [
                'membership_id' => (int) $thread->about_membership_id,
                'contact' => $contact ? [
                    'id' => (int) $contact->id,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                ] : null,
            ];
        }

        $latest = $thread->getAttribute('latest_message_at');

        // Unread means "something was said after you last looked". A thread the
        // parent has never opened is unread only if it actually HAS a message —
        // an empty conversation is not news.
        $lastRead = $lastReadAt !== null ? \Illuminate\Support\Carbon::parse($lastReadAt) : null;
        $unread = $latest !== null
            && ($lastRead === null || \Illuminate\Support\Carbon::parse($latest)->greaterThan($lastRead));

        return [
            'id' => (int) $thread->id,
            'group_id' => (int) $thread->group_id,
            'subject' => $thread->subject,
            'scope' => $thread->threadScope(),
            'about' => $about,
            'is_closed' => $thread->isClosed(),
            'message_count' => (int) ($thread->getAttribute('messages_count') ?? 0),
            'unread' => $unread,
            'last_read_at' => $lastRead?->toIso8601String(),
            'latest_message_at' => $latest !== null
                ? \Illuminate\Support\Carbon::parse($latest)->toIso8601String()
                : null,
            'created_at' => optional($thread->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeMessage(GroupMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'thread_id' => (int) $message->group_thread_id,
            'body' => $message->body,
            // A name, not an id. `users.id` is an internal staff identifier and
            // a parent has nothing to do with it; the teacher's name is what the
            // conversation is with. Since T-015f the author may be a parent, so
            // both principals resolve through GroupMessage::authorLabel() and
            // the client is told WHICH, so it can side the message.
            'author' => $message->authorLabel() !== null
                ? ['name' => $message->authorLabel()]
                : null,
            'author_is_parent' => $message->authorIsParent(),
            'is_mine' => $message->author_contact_id !== null
                && (int) $message->author_contact_id === (int) $this->contact()?->id,
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }
}
