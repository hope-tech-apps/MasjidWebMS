<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\EnableFamilyLoginRequest;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\User;
use App\Services\Family\FamilyAccessService;
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

    /** Current sign-in state for this contact, plus who changed it and when. */
    public function show(Request $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

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
     */
    public function store(EnableFamilyLoginRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        try {
            $contact = $this->access->enable(
                $contact,
                $request->string('login_email')->toString(),
                $this->actor($request),
                $request->ip(),
            );
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

    /**
     * Revoke family sign-in.
     *
     * What this does to a session already holding a token is documented on
     * FamilyAccessService: `family.active` re-checks liveness on every family
     * request so the token is refused on the next one, AND the tokens are
     * deleted here so it stops existing. Two mechanisms, tested separately.
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
     */
    private function payload(Contact $contact): array
    {
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
                'actor_name' => $event->actor_name ?: 'Unknown staff member',
                'actor_email' => $event->actor_email,
                'created_at' => $event->created_at,
            ])
            ->all();

        return [
            'contact_id' => $contact->id,
            'state' => $this->access->state($contact),
            'login_email' => $contact->login_email,
            'login_enabled_at' => $contact->login_enabled_at,
            'login_revoked_at' => $contact->login_revoked_at,
            'last_login_at' => $contact->last_login_at,
            'events' => $events,
        ];
    }
}
