<?php

namespace App\Http\Controllers\Family;

use App\Models\GroupMessage;
use App\Models\GroupThread;
use Illuminate\Http\Request;
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
 * NO UNREAD, NO BOOKMARK — and that is a schema fact, not an oversight
 * ---------------------------------------------------------------------------
 *
 * `group_thread_reads.user_id` is NOT NULL and points at `users`, with a unique
 * index on (thread, user). There is no column a Contact could be written into,
 * so this slice does not write one and does not serve an `unread` flag it could
 * not compute honestly. Replacing that pair with a dual-principal
 * `(reader_type, reader_id)` is T-015f, together with
 * `group_messages.author_contact_id` so a parent can reply. Serving a
 * permanently-false `unread` would have been worse than serving none.
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
            ->with(['aboutMembership.contact:id,first_name,last_name'])
            ->withCount('messages')
            ->withMax('messages as latest_message_at', 'created_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 15))
            ->through(fn (GroupThread $thread) => $this->serializeThread($thread));

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
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($this->perPage($request, 50))
            ->through(fn (GroupMessage $message) => $this->serializeMessage($message));

        // Deliberately NOT marking the thread read — see the class docblock.

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
        $thread->loadMissing(['aboutMembership.contact:id,first_name,last_name']);

        $thread->setAttribute('messages_count', $thread->messages()->count());
        $thread->setAttribute('latest_message_at', $thread->messages()->max('created_at'));

        return $thread;
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeThread(GroupThread $thread): array
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

        return [
            'id' => (int) $thread->id,
            'group_id' => (int) $thread->group_id,
            'subject' => $thread->subject,
            'scope' => $thread->threadScope(),
            'about' => $about,
            'is_closed' => $thread->isClosed(),
            'message_count' => (int) ($thread->getAttribute('messages_count') ?? 0),
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
            // conversation is with.
            'author' => $message->author ? ['name' => $message->author->name] : null,
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }
}
