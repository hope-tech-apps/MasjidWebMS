<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactRequests\MarkAnsweredRequest;
use App\Http\Requests\Admin\ContactRequests\ReplyContactRequestRequest;
use App\Mail\ContactRequestReply;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReply;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Errors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The office's contact-us inbox: read what people sent, write back, and say
 * which ones have been dealt with (PLAN T-042d).
 *
 * ## What this controller is for
 *
 * Three halves of the same job were missing and each one has an office failure
 * attached. Nobody was told a message had arrived (so it sat unread). A reply
 * was mailed and never written down (so re-opening the message tomorrow showed
 * no trace of what was said, or by whom). And there was no answered flag at all
 * (so the second person to open a message could not tell whether the first had
 * already handled it — two people answering the same stranger).
 *
 * ## Tenancy is HAND-SCOPED here, three joins deep
 *
 * ContactUsMessage does not use BelongsToMasjid and carries no `masjid_id`; the
 * organisation is reached through contacter -> mobileAppUser -> masjid_id. Every
 * query below goes through `ownedBy()`, so another organisation's message id
 * resolves to a 404 rather than a 403 or a leak, and targeting a different
 * organisation in the ROUTE is a 403 from ResolveMasjidTenant before this
 * controller runs. `ownedBy()` exists so the join can never be forgotten on a
 * new verb — the two added by T-042d both write. See
 * .claude/rules/tenant-scoping.md and the model docblocks.
 *
 * Every `findOrFail` is OUTSIDE the try blocks. ModelNotFoundException extends
 * Exception, so a `catch (\Exception)` around one turns a clean 404 into a 500
 * and, with it, an existence oracle plus an unusable error for the admin.
 *
 * ## No permission gate, deliberately
 *
 * These routes are not inside the `crm` group and carry no `permission:`
 * middleware, and T-042d does not add one. .claude/rules/auth-permissions.md:
 * "Never add a permission gate to a pre-existing endpoint — that risks locking
 * admins out." `Permission::count()` stays 8.
 *
 * ## The reply path mails a member of the public
 *
 * That is the only outward-facing thing this controller does, and it shapes
 * three decisions documented at `reply()`: the send is synchronous so the
 * success message is a claim about delivery rather than about queueing; the
 * write happens BEFORE the send so a relay failure loses neither the text the
 * admin typed nor the truth about whether the person was actually answered; and
 * the right to send is CLAIMED before the mailer is called, so that of any
 * number of requests carrying the same reply exactly one can reach the relay.
 */
class ContactRequestsController extends Controller
{
    /**
     * The contacter columns any screen may see. `mobile_app_user_id` is here
     * only because the relation needs it to resolve.
     */
    private const CONTACTER_COLUMNS = ['id', 'mobile_app_user_id', 'email', 'name', 'phone'];

    /**
     * How long one request's claim on a send stands before another may take it
     * over.
     *
     * The claim is released as soon as the mailer answers, either way, so this
     * only ever governs a request that DIED mid-send — a php-fpm terminate, a
     * fatal, a machine going away — and never a normal one. Both directions
     * cost something, so the number is chosen rather than guessed:
     *
     *  - too SHORT and it expires while a slow relay is still working, which
     *    re-opens the exact hole it exists to close and puts a second copy in a
     *    stranger's inbox. It must therefore comfortably exceed the longest a
     *    request can live, which php-fpm's request_terminate_timeout bounds.
     *  - too LONG and a reply that nobody is actually sending cannot be retried
     *    from the screen for that many minutes.
     *
     * A duplicate email to a member of the public is the worse of the two, so
     * this errs long. An admin who will not wait can edit the wording and send
     * that, which is a different reply and correctly claims a row of its own.
     */
    private const SEND_CLAIM_TTL_SECONDS = 300;

    /**
     * Display a listing of contact requests for a masjid.
     */
    public function index(Request $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $search = $request->query('search');

        $query = $this->ownedBy($masjid)->with($this->detailRelations());

        // Apply search filter if provided
        if ($search) {
            $query->where(function ($q) use ($search) {
                // Search in message
                $q->where('message', 'LIKE', "%{$search}%")
                  // Search in contacter name, email, phone
                  ->orWhereHas('contacter', function ($contactQuery) use ($search) {
                      $contactQuery->where('name', 'LIKE', "%{$search}%")
                                   ->orWhere('email', 'LIKE', "%{$search}%")
                                   ->orWhere('phone', 'LIKE', "%{$search}%");
                  })
                  // Search in reason text
                  ->orWhereHas('reason', function ($reasonQuery) use ($search) {
                      $reasonQuery->where('text', 'LIKE', "%{$search}%");
                  });
            });
        }

        $messages = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $messages,
            'meta' => $this->replyMeta($masjid, $messages->getCollection()->all()),
        ], Response::HTTP_OK);
    }

    /**
     * Display the specified contact request.
     */
    public function show($masjid_id, $message_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $message = $this->ownedBy($masjid)
            ->with($this->detailRelations())
            ->findOrFail($message_id);

        return response()->json([
            'status' => 'success',
            'data' => $message,
            'meta' => $this->replyMeta($masjid, [$message]),
        ], Response::HTTP_OK);
    }

    /**
     * Remove the specified contact request from storage.
     *
     * The FK on contact_us_replies cascades, so deleting a message takes its
     * reply history with it — there is nothing to orphan.
     */
    public function destroy($masjid_id, $message_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        // Outside the try: another organisation's id must surface as a 404, not
        // be swallowed into a 500 by the catch below.
        $message = $this->ownedBy($masjid)->findOrFail($message_id);

        try {
            $message->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Contact request deleted successfully'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Email the sender back, save what was written, and mark the message
     * answered.
     *
     * ## The order is the design, not an accident
     *
     *   1. The reply row is written first, with `sent_at` NULL — "recorded, not
     *      delivered". If the relay is down, the admin's text still exists and
     *      the next person to open the message can see the attempt.
     *   2. The right to send it is CLAIMED, atomically, before anything leaves
     *      the building. See "One click, one email" below.
     *   3. The mail is sent SYNCHRONOUSLY. See App\Mail\ContactRequestReply for
     *      why this one Mailable is not queued.
     *   4. Only once the mailer has accepted it are `sent_at` and the message's
     *      `answered_at` stamped, in that order, and the claim released.
     *
     * The alternative — stamp answered first, then send — reads better and is
     * wrong: a failed send would leave a message marked answered that nobody was
     * actually told about, which is the exact failure the answered flag exists
     * to prevent, now with the office's confidence behind it.
     * `a_reply_that_fails_to_send_does_not_mark_the_message_answered` pins it.
     *
     * ## One click, one email
     *
     * The guard is a CLAIM, not a check. `sent_at` cannot be the guard, because
     * it is only true once the SMTP round-trip has finished, and the whole
     * problem lives inside that round-trip: a browser or proxy that gives up at
     * 30s and an admin who presses Send again, or two requests that arrive at
     * once. Both used to read "not sent yet" — correctly — and both sent. The
     * unique index stops a duplicate ROW; it never stopped a duplicate SEND.
     *
     * So the ordering is inverted. `claimForSending()` stamps `sending_at`
     * BEFORE the mailer is called, in the INSERT that creates the row or, for a
     * row that already exists, in a conditional UPDATE whose affected-row count
     * settles the race. Exactly one request can win. A request that does not win
     * NEVER reaches `Mail::send`; it reports the winner's outcome instead:
     *
     *  - the winner has already delivered → 200, "already sent", the reply and
     *    the answered state as they now stand, and `meta.sent_now = false` so
     *    the screen does not claim to have just sent anything;
     *  - the winner is still at the relay → 409, carrying the same state, and
     *    the screen keeps the admin's text so they can press Send again and get
     *    a true answer once the winner has finished.
     *
     * The key those requests are matched on is DERIVED from the message and the
     * exact text being sent when the caller supplies none — see
     * `derivedKeyFor()`. A caller's own key still wins where one is sent.
     *
     * A send that fails RELEASES the claim, which is what keeps
     * `a_retry_after_a_failed_send_delivers_the_reply_without_filing_a_second`
     * true: pressing Send after a relay error must retry rather than silently do
     * nothing forever.
     */
    public function reply(ReplyContactRequestRequest $request, $masjid_id, $message_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $message = $this->ownedBy($masjid)
            ->with($this->detailRelations())
            ->findOrFail($message_id);

        $recipient = $this->recipientFor($message);

        if ($recipient === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'This contact request has no email address to reply to.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Already normalised by the FormRequest, because this exact string is
        // what gets emailed, stored, AND hashed into the derived key.
        $body = $request->validated()['reply'];
        $key = $request->validated()['idempotency_key'] ?? self::derivedKeyFor($message, $body);
        $actor = $request->user();

        try {
            [$reply, $claimed] = $this->claimForSending($message, $body, $recipient, $actor, $key);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (! $claimed) {
            // Another request owns this send. Re-read rather than trust the copy
            // loaded a moment ago: the owner may have finished in between, and
            // "it is already sent" and "it is being sent" are different answers.
            $reply->refresh();

            if ($reply->isDelivered()) {
                // A replay of a send that already happened — a double click, a
                // retried request, a second tab, or the same words typed again.
                // Report what is true without putting a second copy in a
                // stranger's inbox.
                return response()->json([
                    'status' => 'success',
                    'message' => 'This exact reply has already been sent to ' . $recipient
                        . '. Nothing new was sent.',
                    'data' => $this->replyState($message->refresh()),
                    'meta' => ['sent_now' => false],
                ], Response::HTTP_OK);
            }

            // The winner is still at the relay, so its outcome does not exist
            // yet. Saying "sent" would be a guess, and saying "failed" would be
            // a different guess; the honest answer is that nothing new went out
            // and the admin should look again in a moment.
            return response()->json([
                'status' => 'error',
                'message' => 'This reply is already being sent to ' . $recipient
                    . '. Nothing new was sent — give it a moment, then press Send again to'
                    . ' see whether it got out.',
                'data' => $this->replyState($message->refresh()),
                'meta' => ['sent_now' => false],
            ], Response::HTTP_CONFLICT);
        }

        try {
            Mail::to($recipient)->send(new ContactRequestReply($masjid, $message, $reply->body));
        } catch (Throwable $e) {
            // Release the claim: this send is over and did not happen, so the
            // next press of Send must be able to try again. The reply survives
            // as a `sent_at = null` row, so the admin's words are not lost and
            // the message stays UNANSWERED — nobody has been told anything yet.
            // The payload carries that state so the screen can say so rather
            // than clearing the box.
            $reply->forceFill(['sending_at' => null])->save();

            return response()->json([
                'status' => 'error',
                'message' => 'The reply was saved but could not be sent to ' . $recipient
                    . '. ' . Errors::publicMessage($e, 'Please try again in a few minutes.'),
                'data' => $this->replyState($message->refresh()),
                'meta' => ['sent_now' => false],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $sentAt = now();

        // Delivery is now a fact, so the claim has nothing left to protect.
        $reply->forceFill(['sent_at' => $sentAt, 'sending_at' => null])->save();
        $message->markAnswered($actor, $sentAt);

        return response()->json([
            'status' => 'success',
            'message' => 'Reply sent successfully to ' . $recipient,
            'data' => $this->replyState($message->refresh()),
            'meta' => ['sent_now' => true],
        ], Response::HTTP_OK);
    }

    /**
     * Set or clear the answered flag without sending anything.
     *
     * The case this exists for is the ordinary one: staff rang the person back.
     * Either direction is allowed, any number of times — triage is a label, not
     * a state machine (.claude/rules/appointments.md). Clearing it does NOT
     * delete the reply history; what was said stays said.
     */
    public function markAnswered(MarkAnsweredRequest $request, $masjid_id, $message_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        // Outside the try, so a cross-tenant id is a 404 and not a 500.
        $message = $this->ownedBy($masjid)->with('replies')->findOrFail($message_id);

        try {
            // `boolean()` reads "1"/"0" (what the SPA sends through
            // URLSearchParams) as well as real booleans. The FormRequest has
            // already refused the strings "true"/"false", which Laravel's
            // `boolean` rule rejects.
            if ($request->boolean('answered')) {
                $message->markAnswered($request->user());
            } else {
                $message->markUnanswered();
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->replyState($message->refresh()),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ----------------------------------------------------------------- internals

    /**
     * Messages belonging to ONE organisation.
     *
     * The single place the three-join tenant filter is written. Every verb goes
     * through it; a new one that forgets it would be a cross-tenant read, and
     * `ContactUsReplyTenantIsolationTest` fails if any does.
     */
    private function ownedBy(Masjid $masjid): Builder
    {
        return ContactUsMessage::whereHas('contacter.mobileAppUser', function ($query) use ($masjid) {
            $query->where('masjid_id', $masjid->id);
        });
    }

    /**
     * What every read of a message loads.
     *
     * `replies` comes along on the LIST as well as the detail because the admin
     * screen opens its modal from the row it already has — fetching the history
     * separately would mean the modal briefly shows "no reply has been sent yet"
     * for a message that has one, which is the single most misleading thing this
     * screen could say.
     *
     * @return array<string,\Closure|string>
     */
    private function detailRelations(): array
    {
        return [
            'contacter' => fn ($query) => $query->select(self::CONTACTER_COLUMNS),
            'reason' => fn ($query) => $query->select('id', 'text'),
            // Naming the nested relation eager-loads `replies` too. actor_name
            // is the snapshot that survives a deleted staff account; the
            // relation is loaded for the id/email a live account still has.
            'replies.actor' => fn ($query) => $query->select('id', 'name', 'email'),
        ];
    }

    /**
     * The subject line each listed message's reply will carry, keyed by message
     * id, plus who it will come from.
     *
     * The admin screen shows this in the confirmation step before anything is
     * sent, so the preview must be the string the Mailable will actually use.
     * Composed by ContactRequestReply::subjectFor() for that reason — a
     * TypeScript copy of the format would drift and the screen would then be
     * misdescribing outgoing mail to a member of the public.
     *
     * @param  array<int,ContactUsMessage>  $messages
     * @return array<string,mixed>
     */
    private function replyMeta(Masjid $masjid, array $messages): array
    {
        $subjects = [];

        foreach ($messages as $message) {
            $subjects[(string) $message->id] = ContactRequestReply::subjectFor(
                $message->reason?->text,
                $masjid->name
            );
        }

        return [
            'reply_subjects' => $subjects,
            'reply_from_name' => $masjid->name,
        ];
    }

    /**
     * The address a reply goes to, or null when there is nowhere to send one.
     *
     * Validated rather than merely present: `contact_us_accounts.email` is
     * written by an UNAUTHENTICATED intake, so it can be any string at all, and
     * handing an unparseable one to the mailer is a 500 on a path whose failure
     * message should read "this request has no email address to reply to".
     */
    private function recipientFor(ContactUsMessage $message): ?string
    {
        $email = trim((string) ($message->contacter?->email ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * The idempotency key for a reply nobody supplied one for.
     *
     * The SPA used to mint a key per modal-open and hold it in component state,
     * which meant it identified WHEN A DIALOG WAS OPENED rather than what was
     * being sent. Reopening the modal produced a different key; a second tab
     * produced a different key; and the guard four docblocks promised was then
     * inert precisely in the cases they named. Two admins answering the same
     * message from two tabs sent the person two copies.
     *
     * A key has to be a function of the thing being done, so it is: the message
     * being answered plus the exact bytes going out. Two tabs, a reopened modal,
     * a browser retry and a proxy replay all compute the same 64 hex characters
     * without remembering anything, and the width and character class match what
     * the column and the FormRequest allow for a client-supplied key.
     *
     * The consequence to be aware of: sending a BYTE-IDENTICAL reply to the same
     * message a second time is treated as a replay and no second email goes out.
     * That is the intended reading of "one click, one email" — and it is not
     * silent, because the response says so in as many words and the reply's own
     * timestamp is on the screen. Changing a single character makes it a
     * different reply, which is what it would have to be to be worth sending.
     *
     * Not a security token, so a plain digest is right: it is only ever compared
     * for equality within one message's rows.
     */
    private static function derivedKeyFor(ContactUsMessage $message, string $body): string
    {
        return hash('sha256', $message->id . "\n" . $body);
    }

    /**
     * Get the reply row this request should send, and say whether THIS request
     * is the one allowed to send it.
     *
     * Claiming and creating are the same act, deliberately. The INSERT stamps
     * `sending_at` in the very statement that creates the row, so there is no
     * instant in which a row exists that nobody has claimed — a window that
     * small is exactly the one two concurrent requests used to both walk
     * through. For a row that already exists the claim is a conditional UPDATE
     * (`claim()`), which is the same decision made by the database rather than
     * by a read this request performed a moment ago.
     *
     * The INSERT is allowed to race: `contact_us_replies_msg_idem_unique`
     * decides who owns the row, and the loser re-reads the winner's and tries to
     * claim it — which it cannot, because the winner claimed it on the way in.
     * That is the fix. Before it, the loser read the winner's row, saw
     * `sent_at = null` (true — the winner was still at the relay) and sent its
     * own copy.
     *
     * @return array{0: ContactUsReply, 1: bool} the row, and whether this
     *         request holds the right to send it
     */
    private function claimForSending(
        ContactUsMessage $message,
        string $body,
        string $recipient,
        ?User $actor,
        string $key
    ): array {
        $existing = $this->findReplyByKey($message, $key);

        if ($existing !== null) {
            return [$existing, $this->claim($existing, $body)];
        }

        try {
            return [ContactUsReply::create([
                'contact_us_message_id' => $message->id,
                'body' => $body,
                'sent_to' => $recipient,
                'actor_user_id' => $actor?->id,
                // Snapshots, so "who answered this person?" still has an answer
                // after the staff account is deleted.
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'idempotency_key' => $key,
                // The claim, taken in the same statement as the row.
                'sending_at' => now(),
                'sent_at' => null,
            ]), true];
        } catch (UniqueConstraintViolationException $e) {
            $winner = self::isIdempotencyViolation($e)
                ? $this->findReplyByKey($message, $key)
                : null;

            if ($winner === null) {
                // Not the double-send index, then — a genuine write failure, and
                // it must not be dressed up as a successful reply.
                throw $e;
            }

            return [$winner, $this->claim($winner, $body)];
        }
    }

    /**
     * Take the right to send an existing reply, or report that somebody else
     * holds it.
     *
     * One conditional UPDATE, and its affected-row count is the whole answer.
     * The database evaluates the predicate against the row as it stands at that
     * instant while holding the row lock, so of any number of requests running
     * this at once exactly one can be told it changed a row. A read followed by
     * a write — `if (! $reply->isDelivered()) { send }` — cannot do that at any
     * isolation level, because the interesting moment is between the two.
     *
     * Nothing is claimable once `sent_at` is set: a delivered reply is a fact
     * about a stranger's inbox and is never sent again. An in-flight claim is
     * honoured until it goes stale (SEND_CLAIM_TTL_SECONDS), which is what stops
     * a request killed mid-send from stranding a reply as permanently unsendable.
     *
     * The body is refreshed only HERE, under a won claim, so an admin who
     * corrected their text after a relay error sends the corrected text — and so
     * that a request which lost the race can never rewrite the words another
     * request is at that moment putting in front of a member of the public.
     */
    private function claim(ContactUsReply $reply, string $body): bool
    {
        $claimedAt = now();
        $staleBefore = $claimedAt->copy()->subSeconds(self::SEND_CLAIM_TTL_SECONDS);

        $won = ContactUsReply::whereKey($reply->getKey())
            ->whereNull('sent_at')
            ->where(function (Builder $query) use ($staleBefore) {
                $query->whereNull('sending_at')
                    ->orWhere('sending_at', '<=', $staleBefore);
            })
            ->update(['sending_at' => $claimedAt]) === 1;

        if (! $won) {
            return false;
        }

        // Bring the in-memory copy in line with the row this request now owns.
        // `forceFill` only marks what actually differs, so an unchanged body
        // writes nothing.
        $reply->forceFill([
            'sending_at' => $claimedAt,
            'body' => $body,
        ])->save();

        return true;
    }

    /**
     * Whether a unique violation is the double-send guard's index and not some
     * other one. Reads the DRIVER's message rather than getMessage(), which also
     * carries the SQL — and the INSERT names `idempotency_key` whichever index
     * objected. Same shape as FormSubmissionsController::isClientKeyViolation().
     */
    private static function isIdempotencyViolation(UniqueConstraintViolationException $e): bool
    {
        $driver = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driver, ContactUsReply::IDEMPOTENCY_UNIQUE_INDEX)   // MySQL names the index
            || str_contains($driver, 'contact_us_replies.idempotency_key');      // SQLite names the columns
    }

    private function findReplyByKey(ContactUsMessage $message, string $key): ?ContactUsReply
    {
        return ContactUsReply::where('contact_us_message_id', $message->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * The triage state of one message after a write: enough for the screen to
     * flip its badge and redraw the history without re-fetching the page.
     *
     * @return array<string,mixed>
     */
    private function replyState(ContactUsMessage $message): array
    {
        $message->load([
            'replies.actor' => fn ($query) => $query->select('id', 'name', 'email'),
        ]);

        return [
            'id' => $message->id,
            'answered_at' => $message->answered_at,
            'answered_by_name' => $message->answered_by_name,
            'replies' => $message->replies->map(fn (ContactUsReply $reply) => [
                'id' => $reply->id,
                'body' => $reply->body,
                'actor_name' => $reply->actor_name,
                'sent_to' => $reply->sent_to,
                // Both, because "nobody has sent this" and "somebody is sending
                // it right now" look identical through `sent_at` alone, and the
                // screen tells the office which of the two it is looking at.
                'sending_at' => $reply->sending_at,
                'sent_at' => $reply->sent_at,
                'created_at' => $reply->created_at,
            ])->all(),
        ];
    }
}
