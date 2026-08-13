<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\EnableFamilyLoginRequest;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\User;
use App\Services\Family\AddressReassignmentRequired;
use App\Services\Family\FamilyAccessService;
use App\Support\Errors;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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
    public function __construct(private readonly FamilyAccessService $access)
    {
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

        return [
            'contact_id' => $contact->id,
            'state' => $this->access->state($contact),
            'eligible' => $ineligible === null,
            'ineligible_reason' => $ineligible,
            'login_email' => $contact->login_email,
            'login_enabled_at' => $contact->login_enabled_at,
            'login_revoked_at' => $contact->login_revoked_at,
            'last_login_at' => $contact->last_login_at,
            'events' => $events,
        ];
    }
}
