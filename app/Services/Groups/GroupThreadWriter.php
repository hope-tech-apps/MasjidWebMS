<?php

namespace App\Services\Groups;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Support\GroupMessageAttachments;
use Illuminate\Support\Facades\DB;

/**
 * Opens a conversation: the thread, its first message, the opener's read marker and
 * the email to the people it reaches.
 *
 * EXTRACTED from GroupThreadsController::store (T-002.4) so that a conversation
 * scheduled for later is written by EXACTLY the code that writes one typed just now.
 * The sweep (`groups:publish-due`) calls this at the scheduled moment; the controller
 * calls it on the request. There is one send path, so a rule added to it (a gate, a
 * marker, a notification) cannot be true of an immediate message and false of a
 * scheduled one.
 *
 * WHAT IT DOES NOT DO is decide whether the author MAY write. That is the caller's:
 * the route gate on a request, ScheduledSendGate at send time. This class trusts the
 * arguments it is given and writes them.
 *
 * ALL OR NOTHING. The thread, its first message, the photos and the read marker are
 * one transaction, and `$inTransaction` (the sweep's "mark this schedule sent") runs
 * inside it, so a conversation exists if and only if its schedule says it was sent. A
 * process that dies part-way leaves nothing behind for a retry to duplicate.
 *
 * The email is dispatched AFTER the transaction (afterCommit), so a queue worker never
 * sees a thread that is not committed yet, and it is fail-soft: it can never fail the
 * write that preceded it.
 */
class GroupThreadWriter
{
    /**
     * The participant a conversation is ABOUT, or null when the id names none: not a
     * membership of THIS group, not a participant (a thread "about" a guardian edge
     * would name a relationship rather than a person), or one who has left the class.
     *
     * Read through the tenant-scoped relation, so another organisation's membership id
     * is a miss. The sweep asks it again at send time with the tenant bound to the
     * schedule's organisation.
     */
    public function aboutMembership(Group $group, int $membershipId): ?GroupMembership
    {
        return $group->memberships()
            ->participants()->current()
            ->find($membershipId);
    }

    /**
     * @param  array<int,\Illuminate\Http\UploadedFile>  $uploads
     * @param  (callable(GroupThread, ?GroupMessage): void)|null  $inTransaction
     * @return array{0: GroupThread, 1: ?GroupMessage} the thread, and its first message when it had one
     */
    public function open(
        Group $group,
        ?int $authorUserId,
        string $subject,
        string $scope,
        ?GroupMembership $about,
        ?string $body,
        array $uploads = [],
        ?string $retainedUntil = null,
        ?callable $inTransaction = null,
    ): array {
        // A conversation about one child with no child would be addressed to nobody in
        // particular, and its notice would fall to the whole class. Never write one.
        if ($scope === GroupThread::SCOPE_PARTICIPANT && $about === null) {
            throw new \InvalidArgumentException('A conversation about one child needs that child.');
        }

        $hasFirstMessage = ($body !== null && trim($body) !== '') || $uploads !== [];

        [$thread, $message] = DB::transaction(function () use (
            $group, $authorUserId, $subject, $scope, $about, $body, $uploads, $retainedUntil, $hasFirstMessage, $inTransaction
        ) {
            $thread = GroupThread::create([
                'group_id' => $group->id,
                // The AUTHENTICATED account (or the schedule's author), never a
                // client-supplied name.
                'created_by_user_id' => $authorUserId,
                'subject' => $subject,
                'scope' => $scope,
                'about_membership_id' => $about?->id,
                'retained_until' => $retainedUntil,
            ]);

            $message = null;

            if ($hasFirstMessage) {
                $message = $thread->messages()->create([
                    'author_user_id' => $authorUserId,
                    // A photo-only message stores an empty body; the column is NOT
                    // NULL and "no text" is what was sent.
                    'body' => (string) ($body ?? ''),
                ]);

                GroupMessageAttachments::store($message, $uploads);

                // The opener has read what they just wrote; without this, their own
                // first message would greet them as "unread".
                if ($authorUserId !== null) {
                    GroupThreadRead::advance((int) $thread->id, $authorUserId, null, (int) $message->id);
                }
            }

            if ($inTransaction !== null) {
                $inTransaction($thread, $message);
            }

            return [$thread, $message];
        });

        // A thread opened WITH a first message notifies like a reply would; an empty
        // thread shell notifies no one. A participant thread reaches the ward's
        // guardian(s); a group-wide thread reaches the feed audience (the job decides
        // from aboutContactId). afterCommit + fail-soft.
        if ($hasFirstMessage) {
            // The conversation is written and committed by now. A queue that cannot take
            // the notice must not turn a SENT conversation into an error for the caller
            // (the sweep would count it failed): log it loudly and go on (the point's W5
            // review, item 4). The notice for that one conversation is lost; the log line
            // names it.
            try {
                SendGroupNotificationJob::dispatch(
                    (int) $group->masjid_id,
                    (int) $group->id,
                    GroupNotificationEvent::GUARDIAN_THREAD_MESSAGE,
                    aboutContactId: $about?->contact_id,
                    authorUserId: $authorUserId,
                    authorContactId: null,
                )->afterCommit();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('groups: conversation '.$thread->id.' was sent, but its email notice could not be queued ('.get_class($e).')');
            }
        }

        return [$thread, $message];
    }
}
