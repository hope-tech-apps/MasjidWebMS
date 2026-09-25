<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\EnableFamilyLoginRequest;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\User;
use App\Services\Family\AddressReassignmentRequired;
use App\Services\Family\FamilyAccessService;
use App\Services\Family\FamilyInviteService;
use App\Services\Family\InviteDeliveryFailed;
use App\Support\Errors;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin surface that turns the parent portal ON for one contact.
 *
 * Three verbs, and the shape is the SMS-consent controller's on purpose — it is
 * the closest thing in this application and for the same reason: enabling and
 * revoking are NOT a boolean toggle.
 *
 *  - GET    the current state, the credential address, and the audit trail. The
 *           trail is part of the READ because "who gave this person access to my
 *           daughter's file" is a question the office has to be able to answer
 *           from the screen, not from a database console.
 *  - POST   opens sign-in at an address the admin types. Also the way an address
 *           is CHANGED and the way a revoked login is re-opened.
 *  - DELETE withdraws it and ends any live session.
 *
 * Plus two acts on the INVITE rather than on the sign-in: `invite()` mails a
 * 7-day link, and `copyLink()` mints the same link and hands it back **to a
 * SuperAdmin only**, unsent (2026-09-25). The second relaxes a guarantee the
 * first was built around — see its docblock and DECISIONS.md.
 *
 * ## Where it sits
 *
 * Beside the contacts endpoints, inside the `crm` group, behind the same
 * `auth:sanctum` + `admin` + `tenant` + `crm` stack, gated on the SAME
 * permissions the contacts surface uses: `view contacts` to read, `manage
 * contacts` to write. No permission is minted — `Permission::count()` stays at
 * 8 and StaffAuthGuardPinTest pins it — and minting one would change the seeded
 * set that RolePermissionBridgeTest also pins. The judgement is the one
 * .claude/rules/groups.md and the credentials routes already make: a login is an
 * attribute OF the member directory, and whoever is trusted to manage a person's
 * record is trusted to manage how that person signs in.
 *
 * ## Tenant isolation is the guardrail's, not this controller's
 *
 * The scoped `findOrFail` sits OUTSIDE any try/catch, so another masjid's
 * contact id is a clean 404 rather than a 500, and the route's `{masjid_id}` is
 * never used as a filter (.claude/rules/tenant-scoping.md). An admin of A
 * naming B's masjid in the path is a 403 from `ResolveMasjidTenant` before this
 * class runs at all.
 */
class ContactFamilyLoginController extends Controller
{
    public function __construct(
        private readonly FamilyAccessService $access,
        private readonly FamilyInviteService $invites,
    ) {
    }

    /**
     * Mail this parent a 7-day link that lands them inside the portal.
     *
     * ## Why a fourth verb, and why here
     *
     * `store()` opens the door and sends the family NOTHING. The parent has to
     * be told by a human that a portal exists, find `/family/{id}/sign-in`, type
     * the address the office chose rather than the one they use, and then fetch
     * a six-digit code that dies in ten minutes. Measured: Al-Razi had ten
     * family logins enabled and five had never been used once. This is the
     * missing half of `store()`, not a convenience on top of it.
     *
     * ## Refusals, not errors
     *
     * The RuntimeException catch is the same REFUSAL path `store()` has, and the
     * causes are all things an operator can act on: this member may not hold a
     * family login at all (the same guardian-edge rule `store()` enforces,
     * re-run at send time because standing lapses without revoking anything);
     * there is no live sign-in to invite them to; or this mailbox has already
     * been sent the hour's worth of links.
     *
     * ## A MAIL FAILURE IS NOT ONE OF THEM, and the catch order says so
     *
     * `FamilyInviteService` does not swallow a send failure the way
     * `FamilyLoginService` must — that one is unauthenticated, where an error
     * path is an existence oracle, and this one is an admin pressing a button
     * whose entire purpose is to write to somebody. What the office must never
     * get is a green tick over an email that was not sent.
     *
     * But a transport failure IS a `RuntimeException` (Symfony's
     * `TransportException` extends it), so it would have fallen into the refusal
     * catch and been reported as a 422 saying something is wrong with this
     * member, with the transport's own message — which routinely quotes the
     * recipient address and the relay's response — as the body. Hence
     * `InviteDeliveryFailed`, caught FIRST, answered 500, and logged in full.
     *
     * The QueryException catch sits above the refusal for the same reason
     * `store()` records: `QueryException extends PDOException extends
     * RuntimeException`, so without it the refusal block would answer every
     * database error with the driver's message — table names, columns and a
     * bound value — as a 422 body. Three catches, one ordering rule: the
     * subclasses that are NOT refusals go first.
     */
    public function invite(Request $request, $masjid_id, $contact_id)
    {
        // Non-trashed, like `store()` and `destroy()`: mailing a key to a member
        // who is not in the directory is not an act this application should
        // offer, and a deleted member's access has already ended. Outside any
        // try/catch, so another masjid's contact id is a clean 404 rather than a
        // 500, and the route's `{masjid_id}` is never used as a filter
        // (.claude/rules/tenant-scoping.md).
        $contact = Contact::findOrFail($contact_id);

        try {
            $this->invites->issue(
                $contact,
                $this->actor($request),
                $request->ip(),
            );
        } catch (InviteDeliveryFailed $e) {
            // FIRST, and the ordering is the point rather than a style
            // preference. `InviteDeliveryFailed` IS a RuntimeException — as is
            // Symfony's own TransportException, which is what it wraps — so
            // without this block a relay outage would fall into the refusal
            // catch below and be reported to the office as a 422 saying
            // something is wrong with this member, carrying the transport's own
            // message (which routinely quotes the recipient address and the
            // relay's response) as the body. That is the same class of leak the
            // QueryException catch exists to prevent, and it is measured on that
            // one: the driver's message, table names, columns and a bound value,
            // handed to whoever clicked save.
            //
            // The service sends inside its transaction, so by the time we are
            // here there is NO invite row, no `invite_sent` audit row, and the
            // link the parent may already be holding has not been invalidated.
            // The message says so, because an office that thinks it has just
            // burned the family's link will go and do something about it.
            Log::error('family portal invite delivery failed', [
                'contact_id' => $contact->id,
                'masjid_id' => $contact->masjid_id,
                // The cause, not the wrapper — the wrapper is always this class.
                'exception' => ($e->getPrevious() ?? $e)::class,
                'message' => ($e->getPrevious() ?? $e)->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'The invite could not be emailed just now, so nothing was sent and '
                    . 'any earlier link still works. Please try again in a few minutes.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The whole panel, re-read. The caller gets the new `invite` block and
        // the fresh `invite_sent` row in one response rather than having to
        // follow up with a GET — the same shape `store()` and `destroy()` use.
        return response()->json([
            'status' => 'success',
            'data' => $this->payload($contact->refresh()),
        ], Response::HTTP_OK);
    }

    /**
     * SUPER ADMIN ONLY — mint a portal link and hand it back, unsent.
     *
     * ## What this relaxes, said plainly
     *
     * `FamilyInviteService::issue()` was built so the plaintext never reached a
     * caller: generated, mailed, discarded. What that bought is that **no staff
     * member ever holds a working key to a child's photographs, marks and
     * safeguarding conversations** — the emailed link can only be read by
     * whoever holds the mailbox the office typed, and the row says which mailbox
     * that was. A link on a screen has no such answer.
     *
     * The owner asked for it back, narrowly: "the ability to set Sajida's login
     * myself", "the link for me to text her directly", and, asked who should be
     * able to do that, **"You only (super admin)"**. DECISIONS.md, 2026-09-25,
     * carries the alternatives and why this is the boundary.
     *
     * ## The gate, and why it is HERE rather than on the route
     *
     * `Auth::user()?->type !== 'SuperAdmin'` → `abort(403)`, which is the call
     * `MasjidsController::setCrmAccess` and `setCapability` already make, for
     * the reason recorded there: the shared `super` middleware answers a
     * non-super caller **401**, and the admin SPA reads a 401 as "your session
     * ended" and signs the operator out. A MasjidAdmin who reaches this must be
     * told they may not, not logged out of a screen they are entitled to.
     *
     * `abort()` raises an `HttpException`, which this app's JSON renderer turns
     * into a clean 403 with the standard envelope. `can:` and a FormRequest
     * `authorize()` are avoided for the reason .claude/rules/auth-permissions.md
     * records: an `AuthorizationException` is not an `HttpException` and would
     * fall through to a 500 here.
     *
     * **No permission is minted.** `Permission::count()` stays at 8 and
     * `StaffAuthGuardPinTest` pins it. A `copy portal link` permission would be
     * granted either to `masjid-admin` — the set the owner declined, since every
     * MasjidAdmin at every organisation holds the full CRM set — or to nobody,
     * which is this check wearing a costume.
     *
     * The route keeps `permission:manage contacts` underneath, which changes no
     * outcome (a SuperAdmin holds all eight) and keeps it reading like its three
     * siblings. **The SuperAdmin check is the operative gate**, and
     * `FamilyPortalInviteCopyLinkTest` deletes it to prove so.
     *
     * ## The plaintext is in the response body and nowhere else
     *
     * Not logged — there is no `Log::` on any path through here, and the refusal
     * catch below re-throws nothing that has seen the token, because every
     * refusal `mint()` raises is thrown BEFORE the token is generated. Not
     * stored — the column is a keyed digest. Not in an exception message. It is
     * in the fragment of the URL besides, so even a caller who pasted it into an
     * address bar would not put it in an access log.
     *
     * There is no `InviteDeliveryFailed` catch because nothing is delivered:
     * that whole failure direction does not exist on this door.
     */
    public function copyLink(Request $request, $masjid_id, $contact_id)
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(
                Response::HTTP_FORBIDDEN,
                'Only a super admin can copy a parent portal link. Use "Send portal invite" to email it instead.'
            );
        }

        // Non-trashed and outside any try/catch, exactly as `invite()` above:
        // another masjid's contact id is a clean 404, never a 500, and the
        // route's `{masjid_id}` is never used as a filter
        // (.claude/rules/tenant-scoping.md).
        $contact = Contact::findOrFail($contact_id);

        try {
            $minted = $this->invites->issueCopyableLink(
                $contact,
                $this->actor($request),
                $request->ip(),
            );
        } catch (QueryException $e) {
            // Above the refusal for `store()`'s reason: QueryException extends
            // PDOException extends RuntimeException, so without this the refusal
            // block answers every database error with the driver's message —
            // table names, columns and a bound value — as a 422 body.
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($contact->refresh()) + [
                /*
                 * THE ONLY TIME THIS APPLICATION EVER EMITS A PORTAL LINK.
                 *
                 * A separate key rather than folded into the `invite` block,
                 * because the `invite` block is what `show()` serves on every
                 * page load and a link must never ride a GET. Nothing reads this
                 * back: it exists for exactly one response and the screen that
                 * renders it drops it when the operator leaves the contact.
                 *
                 * `expires_at` travels with it so the screen states the lifetime
                 * from the server's own row rather than repeating "7 days" from
                 * a constant in TypeScript that agrees today.
                 */
                'copied_link' => [
                    'url' => $minted['url'],
                    'expires_at' => $minted['invite']->expires_at,
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Current sign-in state for this contact, plus who changed it and when.
     *
     * ## `withTrashed` — the trail has to outlive its subject
     *
     * `ContactsController::destroy` revokes before it soft-deletes, so deleting
     * a member writes the `revoked` row that says their access ended and who
     * ended it. This method resolved the contact with a plain `findOrFail`, so
     * the moment that row was written the ONE screen built to answer "who took
     * my access away" answered 404 — evidence written directly into a place no
     * screen could reach, which is worse than the silence it replaced.
     *
     * READ ONLY. `store()` and `destroy()` below keep the non-trashed lookup:
     * granting or withdrawing a credential on a member who is not in the
     * directory is not an act this application should offer, and a deleted
     * member's access has already ended. Restore them first (`POST
     * …/contacts/{id}/restore`), which is a deliberate act on the record.
     */
    public function show(Request $request, $masjid_id, $contact_id)
    {
        $contact = Contact::withTrashed()->findOrFail($contact_id);

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($contact),
        ], Response::HTTP_OK);
    }

    /**
     * Enable — or re-address, or re-open — family sign-in.
     *
     * The RuntimeException catch is a REFUSAL path, not an error path: an
     * address already in use, or a placeholder contact. The message is written
     * for the admin reading it and names the member holding the address, because
     * "already in use" with no name leaves them with nothing to do next.
     *
     * It also now settles the CONCURRENT case. Two admins enabling the same
     * address in the same second both clear the pre-check — it is a SELECT, not
     * a lock — and `contacts_masjid_login_email_unique` refuses the loser. The
     * service translates that ONE violation into the same RuntimeException the
     * pre-check throws, so the translation lives beside the message rather than
     * in a catch block here that would have to be kept in agreement with it.
     *
     * ## The QueryException catch below is not belt-and-braces
     *
     * Measured, and it is not the 500 it looks like: `QueryException extends
     * PDOException extends RuntimeException`, so the refusal catch underneath
     * ALREADY caught every database error and answered 422 with the driver's
     * message as the body — table names, column names, the failing UPDATE and a
     * bound value, handed to whoever clicked save. Catching it FIRST (PHP takes
     * the first matching block) sends anything the service did not deliberately
     * translate down the path this application already has for an internal
     * error: logged in full, generic in production, raw only under
     * `app.debug` (App\Support\Errors).
     */
    public function store(EnableFamilyLoginRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        // READ BEFORE THE WRITE, because after it there is nobody to read: the
        // address has moved and the loser's column is null. `reassign_address`
        // is `sometimes|boolean` and nothing can enforce that the refusal naming
        // the loser was ever issued, so a first-shot confirmation took a
        // credential address off a soft-deleted holder in silence. This is what
        // makes the outcome say whose it was.
        $released = $request->boolean('reassign_address')
            ? $this->access->addressHolderReport($contact, $request->string('login_email')->toString())
            : null;

        try {
            $contact = $this->access->enable(
                $contact,
                $request->string('login_email')->toString(),
                $this->actor($request),
                $request->ip(),
                $request->boolean('reassign_address'),
            );
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                // TRUE for exactly one refusal: the address is still assigned to
                // a member whose portal access has already ended, and re-sending
                // with `reassign_address` will move it. The SPA uses this to
                // offer the confirmation rather than matching the sentence — a
                // reworded message must not silently remove the only way
                // through. Never true for a holder who can sign in right now.
                'reassignable' => $e instanceof AddressReassignmentRequired,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($contact) + [
                // Present ONLY when this act actually took the address off
                // somebody. The wording is the refusal's, so a first-shot
                // confirmation and a confirmed refusal disclose exactly the same
                // thing about the same person — a deleted holder is described,
                // never named, because no screen here shows a deleted contact.
                'address_released_from' => $released === null ? null : $released['name'],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Revoke family sign-in.
     *
     * What this does to a session already holding a token is documented on
     * FamilyAccessService: `family.active` re-checks liveness on every family
     * request so the token is refused on the next one, AND the tokens are
     * deleted here so it stops existing. Two mechanisms, tested separately.
     *
     * The QueryException catch is here for the same reason as on `store()`: a
     * PDOException IS a RuntimeException, so without it the refusal path answers
     * a database failure with the driver's message in the body.
     */
    public function destroy(Request $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        try {
            $contact = $this->access->revoke(
                $contact,
                $this->actor($request),
                $request->ip(),
            );
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($contact),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * The staff member acting, or null.
     *
     * Narrowed with `instanceof` rather than trusted: this tree is `admin`-gated
     * so the principal is a User in practice, but the audit row's actor column
     * is a `users` foreign key and a wrong principal type must produce "no
     * actor recorded" rather than an id from another table.
     */
    private function actor(Request $request): ?User
    {
        $principal = $request->user();

        return $principal instanceof User ? $principal : null;
    }

    /**
     * State + credential address + trail.
     *
     * The three-state word is computed in PHP by FamilyAccessService rather than
     * reconstructed in the SPA from the raw timestamps: the portal's liveness
     * rule already exists once, in `Contact::familyLoginIsActive()`, and a second
     * copy in TypeScript is a copy that agrees today.
     *
     * `last_login_at` is included because it is the one thing on the screen that
     * tells an office whether the invite ever actually worked. It is operator
     * visibility only — nothing reads it and nothing authorizes on it.
     *
     * ## `eligible` / `ineligible_reason` — the refusal, BEFORE the click
     *
     * The screen offered "Enable parent portal sign-in" on every member, so a
     * registrar working down a school roster reached a nine-year-old, clicked,
     * typed an address, and only then learned it was never permitted. Nothing on
     * the record said which members could hold one.
     *
     * Computed by `FamilyAccessService::ineligibilityReason()` — the SAME method
     * `enable()` refuses with, not a second implementation of the rule. A preview
     * that is stricter than the write hides a member who could be enabled; one
     * that is looser is the surprise 422 this is here to remove. Being one call
     * is what makes both impossible.
     *
     * It is also true for a member whose sign-in is already ENABLED, and that is
     * the point of putting it beside `state` rather than hiding it when the
     * button is absent: standing lapses (a guardian edge removed, a ward
     * deleted), the credential deliberately keeps working, and the office needs
     * the screen to say so rather than discovering it from a parent's phone call.
     */
    private function payload(Contact $contact): array
    {
        $ineligible = $this->access->ineligibilityReason($contact);

        $events = ContactLoginEvent::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ContactLoginEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'login_email' => $event->login_email,
                // The snapshot, not the live user: see the migration. Falls back
                // to a plain sentence rather than to null so the UI never has to
                // decide what "no actor" looks like.
                //
                // AND THE SENTENCE SAYS WHAT IT KNOWS. It used to read "Unknown
                // staff member", which asserts a staff member acted and that we
                // failed to record which — a claim the row does not make. An
                // actorless row means no staff principal was on the request:
                // today that is a console or seeder path, and until this round
                // it was also a `created` hook fired by an ANONYMOUS POST, so
                // the screen read "Sign-in revoked — Unknown staff member" for
                // an act no staff member performed. That hook is gone; the
                // honest fallback stays, because an audit trail that guesses at
                // an actor is the one failure it cannot afford.
                'actor_name' => $event->actor_name ?: 'No staff member recorded',
                'actor_email' => $event->actor_email,
                'created_at' => $event->created_at,
            ])
            ->all();

        $invite = $this->invites->lastInviteFor($contact);

        return [
            'contact_id' => $contact->id,
            'state' => $this->access->state($contact),
            'eligible' => $ineligible === null,
            'ineligible_reason' => $ineligible,
            'login_email' => $contact->login_email,
            'login_enabled_at' => $contact->login_enabled_at,
            'login_revoked_at' => $contact->login_revoked_at,
            'last_login_at' => $contact->last_login_at,

            /*
             * THE LAST LINK WE SENT, or null if we never sent one.
             *
             * `last_login_at` above answers "did they ever arrive?" and the
             * trail answers "who granted this?"; neither answers the question
             * that actually explains a silent family, which is whether anybody
             * ever told them. Before this block the office's only reading of
             * "enabled, never signed in" was "they are ignoring us".
             *
             * NOT filtered to live invites. An expired one is the most useful
             * row on this panel — it is the case where the answer is "send
             * another" — and hiding it would make the screen read identically to
             * one where nothing was ever sent.
             *
             * The state word is computed in PHP by the model, like
             * `FamilyAccessService::state()` and for the same reason: a second
             * copy of the rule in TypeScript is a copy that agrees today. The
             * token digest is not here, and the model hides it anyway.
             */
            'invite' => $invite === null ? null : [
                'state' => $invite->state(),
                'sent_at' => $invite->created_at,
                'expires_at' => $invite->expires_at,
                'accepted_at' => $invite->consumed_at,
                // The address it actually went to, which is not necessarily
                // `login_email` above: an office that re-addressed the login
                // since needs to see that the link went somewhere else.
                'login_email' => $invite->login_email,
            ],

            'events' => $events,
        ];
    }
}
