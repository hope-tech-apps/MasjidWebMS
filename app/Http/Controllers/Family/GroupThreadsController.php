<?php

namespace App\Http\Controllers\Family;

use App\Enums\GroupNotificationEvent;
use App\Http\Requests\Family\StoreFamilyMessageRequest;
use App\Http\Requests\Family\StoreFamilyThreadRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\GroupMessage;
use App\Models\GroupMessageReaction;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\Masjid;
use App\Support\GroupMedia;
use App\Support\GroupMessageSignals;
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
 * 2026-09-21 (owner): a third and a fourth — a parent's REACTION (🤲 👍 💯 ❓,
 * the fixed set in GroupMessageReaction) and its removal, gated exactly as a
 * reply is. And the bookmark stopped being private to its reader: the
 * TEACHER now sees it as a read receipt, while a parent sees only the
 * school's receipts, never another parent's (App\Support\GroupMessageSignals).
 *
 * PHOTOS. A teacher's message may carry photos. A parent receives them on the
 * same terms as the conversation, plus media consent on a CLASS-WIDE thread
 * (GroupAudience::mayReceiveThreadMedia()), and downloads them only through
 * downloadAttachment(), which re-resolves the chain and asks again. A parent's
 * own reply stays text only — StoreFamilyMessageRequest accepts no files.
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
        // lookup rather than a query per row. Listing is NOT reading: nothing
        // here moves a bookmark, so a thread only counts as read (and only
        // shows the teacher a receipt) once it is actually opened.
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

        $mayReceiveMedia = $this->audience->mayReceiveThreadMedia($this->contact(), $group, $thread);

        $messages = $thread->messages()
            ->with(['author:id,name', 'authorContact:id,first_name,last_name', 'attachments'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($this->perPage($request, 50));

        // Opening a conversation is reading it, up to the newest message this
        // page SERVED. The bookmark is the READER'S own and nobody else's, and
        // is writable by no other principal. Since 2026-09-21 it is also the
        // read receipt the TEACHER sees ("Seen by Huda Yusuf") — which is the
        // point of it, and which is why it is written here, after
        // mayReceiveThread(), and never by the listing.
        $servedUpTo = collect($messages->items())->max('id');
        $this->markRead($thread, $servedUpTo !== null ? (int) $servedUpTo : null);

        // Reactions and the SCHOOL's receipts. A parent is shown staff names
        // only; another family member's reaction is counted, never named, and
        // another parent's reading is not shown at all (GroupMessageSignals).
        $signals = GroupMessageSignals::forMessages($thread, $messages->items(), $this->contact());

        $messages->through(fn (GroupMessage $message) => $this->serializeMessage(
            $message, $mayReceiveMedia, $signals[(int) $message->id] ?? null
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->serializeThread($this->withAggregates($thread)),
                'messages' => $messages,
            ],
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../threads/{thread_id}/messages/{message_id}/attachments/{attachment_id}
     *
     * One photo, as bytes behind the bearer token — never a signed or cached
     * URL, so access ends the moment standing or consent does. The whole chain
     * is re-resolved link by link (a foreign id anywhere is a 404), and the
     * decision is asked again here, at the point the bytes leave.
     */
    public function downloadAttachment($masjid_id, $group_id, $thread_id, $message_id, $attachment_id)
    {
        Masjid::findOrFail($masjid_id);

        $group = $this->group($group_id);
        $thread = $group->threads()->findOrFail($thread_id);
        $message = $thread->messages()->findOrFail($message_id);
        $attachment = $message->attachments()->findOrFail($attachment_id);

        if (! $this->audience->mayReceiveThreadMedia($this->contact(), $group, $thread)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to the photos in this conversation.');
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
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * POST .../threads/{thread_id}/messages/{message_id}/attachments/{attachment_id}/playback
     *
     * A parent's playback ticket for a conversation video. Same chain and same
     * decision as downloadAttachment, re-asked by the playback route on every
     * ranged request.
     */
    public function playbackTicket($masjid_id, $group_id, $thread_id, $message_id, $attachment_id)
    {
        Masjid::findOrFail($masjid_id);

        $group = $this->group($group_id);
        $thread = $group->threads()->findOrFail($thread_id);
        $message = $thread->messages()->findOrFail($message_id);
        $attachment = $message->attachments()->findOrFail($attachment_id);

        if (! $this->audience->mayReceiveThreadMedia($this->contact(), $group, $thread)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to the photos in this conversation.');
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
                    GroupMedia::VIEWER_FAMILY, (int) $this->contact()->id,
                ),
                'expires_in' => GroupMedia::playbackTtlMinutes() * 60,
            ],
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
    /**
     * POST .../groups/{group_id}/threads — a parent OPENS a conversation.
     *
     * The second write this realm has ever had, and narrower than the staff one
     * in three deliberate ways:
     *
     *   1. SCOPE IS FORCED TO PARTICIPANT. It is not read from the payload at
     *      all, so there is no request a parent can construct that opens a
     *      class-wide thread. A group-scoped thread reaches every family in the
     *      room; a teacher may open one because they can already post to that
     *      same audience through the class story, and a parent cannot.
     *   2. THE SUBJECT MUST BE THEIR OWN CHILD. `subject()` runs the same
     *      GroupAudience check that governs every per-child read, so a
     *      membership id naming another family's child is a 403 — not a thread.
     *   3. RATE LIMITED at the route (`throttle:family-thread`), keyed on the
     *      contact. Replying is not limited; opening is, because it is the verb
     *      that creates work for a teacher.
     *
     * The notification is TEACHER_THREAD_MESSAGE, not the GUARDIAN_* the staff
     * controller sends: this conversation is going the other way, and telling
     * the guardians about their own message would be both wrong and a small
     * disclosure to the other guardians of that child.
     */
    public function store(StoreFamilyThreadRequest $request, $masjid_id, $group_id)
    {
        $group = $this->group($group_id);

        // 403s unless this contact is entitled to records about that child.
        $about = $this->subject($group, $request->validated('about_membership_id'));

        $thread = DB::transaction(function () use ($group, $about, $request) {
            $thread = GroupThread::create([
                'group_id' => $group->id,
                // The AUTHENTICATED contact, never a client-supplied name.
                'created_by_contact_id' => $this->contact()->id,
                'subject' => $request->validated('subject'),
                'scope' => GroupThread::SCOPE_PARTICIPANT,
                'about_membership_id' => $about->id,
            ]);

            $first = $thread->messages()->create([
                'author_contact_id' => $this->contact()->id,
                'body' => $request->validated('body'),
            ]);

            // You have read what you just wrote.
            $this->markRead($thread, (int) $first->id);

            return $thread;
        });

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
            'data' => ['id' => (int) $thread->id, 'subject' => $thread->subject],
        ], Response::HTTP_CREATED);
    }

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
            $this->markRead($thread, (int) $message->id);

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
            // A parent's reply carries no photos, so there is nothing to withhold.
            'data' => $this->serializeMessage(
                $message->load(['author:id,name', 'authorContact:id,first_name,last_name', 'attachments']),
                true,
                GroupMessageSignals::forMessages($thread, [$message], $this->contact())[(int) $message->id] ?? null
            ),
        ], Response::HTTP_CREATED);
    }

    /**
     * PUT .../threads/{thread_id}/messages/{message_id}/reactions/{reaction}
     *
     * A parent's 🤲 / 👍 / 💯 / ❓ — the "Ameen" to a teacher's du'a, the
     * thumbs-up that means "got it" without a reply the teacher has to read.
     *
     * AUTHORISED EXACTLY AS A REPLY IS, in the same order: `mayReceiveThread()`
     * (so another family's private conversation is a 403 and nothing is
     * written), then "not closed". The message is resolved THROUGH that thread,
     * so a parent cannot aim a reaction at another family's message by pairing
     * its id with a thread they may read — that is a 404. The reacting contact
     * is the TOKEN's, never the payload's; there is no payload.
     *
     * Idempotent: a second PUT leaves one row. No notification — a reaction is
     * an acknowledgement, and a push for every 👍 would bury the replies.
     */
    public function react($masjid_id, $group_id, $thread_id, $message_id, $reaction)
    {
        return $this->setReaction($group_id, $thread_id, $message_id, $reaction, true);
    }

    /** DELETE .../reactions/{reaction} — take it back. Same gate, equally idempotent. */
    public function unreact($masjid_id, $group_id, $thread_id, $message_id, $reaction)
    {
        return $this->setReaction($group_id, $thread_id, $message_id, $reaction, false);
    }

    private function setReaction($group_id, $thread_id, $message_id, $reaction, bool $on)
    {
        $group = $this->group($group_id);
        $thread = $group->threads()->findOrFail($thread_id);

        if (! $this->audience->mayReceiveThread($this->contact(), $group, $thread)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this conversation.');
        }

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
                'message' => 'This conversation has been closed by the school.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $key = [
            'group_message_id' => $message->id,
            'reaction' => $reaction,
            'contact_id' => $this->contact()->id,
        ];

        if ($on) {
            GroupMessageReaction::createOrFirst($key);
        } else {
            GroupMessageReaction::query()->where($key)->delete();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'message_id' => (int) $message->id,
                'reactions' => GroupMessageSignals::reactionsFor($message, $this->contact()),
            ],
        ], Response::HTTP_OK);
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
    private function markRead(GroupThread $thread, ?int $upToMessageId = null): void
    {
        $contact = $this->contact();

        if ($contact === null) {
            return;
        }

        GroupThreadRead::advance((int) $thread->id, null, (int) $contact->id, $upToMessageId);
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
    private function serializeMessage(GroupMessage $message, bool $mayReceiveMedia, ?array $signals = null): array
    {
        $attachments = $message->relationLoaded('attachments') ? $message->attachments : collect();

        return [
            'id' => (int) $message->id,
            'thread_id' => (int) $message->group_thread_id,
            'body' => $message->body,
            // Omitted entirely for a parent who may not have them — a filename
            // is itself a disclosure — and `media_withheld` says so honestly.
            // No URL: the portal builds the download path from the ids, as it
            // does for class-story photos.
            'attachments' => $mayReceiveMedia
                ? $attachments->map(fn ($attachment) => $attachment->toAudienceArray())->values()->all()
                : [],
            // The portal builds the download path from the ids (see
            // FamilyClass.vue) and builds the PLAYBACK path the same way, from
            // `is_video` on each attachment. Nothing new is emitted here on
            // purpose: adding a per-attachment path to this one serializer and
            // not the three around it is how two conventions start.
            //
            'media_withheld' => ! $mayReceiveMedia && $attachments->isNotEmpty(),
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
            // All four reactions with counts; names are staff-only for a parent.
            'reactions' => $signals['reactions'] ?? [],
            // Which STAFF have read this message. Never another parent.
            'read_by' => $signals['read_by'] ?? [],
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }
}
