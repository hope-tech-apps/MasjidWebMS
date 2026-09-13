<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Registrations\GrantAdjustmentRequest;
use App\Http\Requests\Admin\Registrations\StoreRegistrationRequest;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Services\Registrations\RegistrationException;
use App\Services\Registrations\RegistrationService;
use App\Services\Registrations\StaffEntry;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\Errors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the roster of one offering, the four explicit actions an administrator
 * may take on a registration — enter one by hand, grant aid, promote off the
 * waitlist, cancel (T-006d + T-041i, docs/t006-registration-billing-design.md).
 *
 * THE RULES LIVE IN THE SERVICE. This controller resolves the row through the
 * tenant scope, hands it to RegistrationService, and translates a refusal into
 * a clean 422 — it re-implements none of the invariants and bypasses none of
 * them. Concretely: adjustments are floored at zero and refused once a Stripe
 * leg exists inside `grantAdjustment()`; capacity is re-checked under the
 * offering's row lock inside `promoteFromWaitlist()`; the seat counter is only
 * ever written by `releaseSeat()`. If a refusal is inconvenient, the fix is in
 * the service, never here.
 *
 * Tenant isolation is the standard guardrail: `tenant` binds TenantContext,
 * BelongsToMasjid scopes every query, and every registration is resolved
 * THROUGH its offering — so another organization's id is a 404 and a
 * registration can never be acted on from a foreign offering's route.
 *
 * `store()` (T-041i) is the one method that CREATES, and it is still the same
 * controller: it resolves people to Contacts, hands the list to
 * `RegistrationService::register()` with a `StaffEntry` naming the authenticated
 * admin, and translates a refusal. Capacity, pricing, the free-path carve-out
 * and the roster writes all stay where they were. It moves no money and cannot:
 * see its own docblock.
 */
class RegistrationsController extends Controller
{
    public function __construct(
        private RegistrationService $registrations,
        private RegistrationCheckoutService $checkout,
    ) {
    }

    /**
     * The roster of one offering.
     *
     * Two views of the same list, because admins ask two different questions:
     *
     *  - the default REGISTRATION view — one row per signup (the money view: a
     *    household, its plan, its snapshot totals, its payment state);
     *  - `?view=registrants` — one row per PERSON, which is what a teacher
     *    printing a class list actually wants (a guardian who registered three
     *    children is one registration and three registrants).
     *
     * Filters: ?status=, ?payment_status=, ?search= (payer name/email),
     * ?per_page=.
     */
    public function index(Request $request, $masjid_id, $offering_id)
    {
        $offering = Offering::findOrFail($offering_id);

        if ($request->query('view') === 'registrants') {
            return response()->json([
                'status' => 'success',
                'data' => $this->registrantView($request, $offering),
            ], Response::HTTP_OK);
        }

        $registrations = $this->filteredQuery($request, $offering)
            ->with(['contact', 'feePlan', 'registrants.contact'])
            ->withCount('registrants')
            ->latest('id')
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $registrations,
        ], Response::HTTP_OK);
    }

    /**
     * One registration with everything that decides what it owes and what it
     * has paid: its adjustments (with who granted them) and its payment ledger.
     */
    public function show($masjid_id, $offering_id, $registration_id)
    {
        $registration = $this->findRegistration($offering_id, $registration_id, [
            'contact',
            'feePlan',
            'formResponse',
            'registrants.contact',
            'adjustments.grantedBy:id,name,email',
            'payments',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $registration,
        ], Response::HTTP_OK);
    }

    /**
     * Enter a registration BY HAND (T-041i).
     *
     * A family pays at the desk in cash, or phones in, or has no email address.
     * Until this route existed the office had nowhere to put them: the intake
     * FORM can be published as a page section, but submitting it writes a
     * `FormResponse` — no seat, no plan, no charge — and the roster kept saying
     * nobody had registered while the school believed it had enrolled a class.
     *
     * ------------------------------------------------------- WHAT IT RECORDS
     *
     * Exactly what the public door records, plus who typed it. Same service,
     * same transaction, same capacity lock, same snapshot pricing, same
     * free-path carve-out, same roster and guardian writes. The only additions
     * are `source = staff`, `entered_by_user_id` and the admin's note, stamped
     * through `Registration::enteredByStaff()` because they are provenance and
     * therefore not fillable.
     *
     * -------------------------------------------- WHAT IT DOES NOT CLAIM: MONEY
     *
     * NOTHING HERE CAN RECORD MONEY THAT HAS NOT MOVED. A hand-entered
     * registration on a paid plan is created `pending / awaiting` — unpaid, and
     * saying so on every screen. There is no `paid` flag, no amount, no
     * payment_method, and no "mark as paid": `payment_status` is advanced by
     * signature-verified Stripe webhooks (.claude/rules/stripe-payments.md), and
     * the alternative — a column reading "cash" beside a status Stripe never set
     * — is how a roster and a merchant account come to disagree permanently.
     *
     * The two honest routes to a hand-entered registration that owes nothing:
     *
     *   1. a FREE fee plan, confirmed synchronously in this same request by the
     *      declared free-path carve-out; or
     *   2. a paid plan, created unpaid, then waived to zero with the existing
     *      Grant aid action — a 100% waiver routes through `confirm()`.
     *
     * The modal on the roster screen says this in one sentence, because the
     * absence of a "record the cash" box is a decision and not an oversight.
     *
     * ------------------------------------ WHY IT MAY NAME AN EXISTING PERSON
     *
     * `RegistrationService::writeRosterMemberships()` writes a GUARDIAN EDGE
     * from the payer over every registrant, and that edge is the single fact the
     * parent portal reads to decide whose child's records a credential opens.
     * The PUBLIC endpoint therefore resolves a registrant only to a contact it
     * creates in that same request — nothing on an unauthenticated path
     * authorises "I am this person's guardian" about somebody who already
     * exists. This route may, and the service's docblock names it as the
     * anticipated caller, on two conditions that are both met here:
     *
     *   - it is AUTHENTICATED and gated on `permission:manage contacts` inside
     *     the `crm` gate — the same trust level as editing the directory itself,
     *     which is where the same person could establish the same relationship
     *     by hand today; and
     *   - it RECORDS THE AUTHOR, so the assertion has somebody to attribute.
     *
     * And it still grants nothing on its own: the edges written stay
     * `self_asserted`, which lists a child and opens no record, until staff
     * confirm them on the group screen. Entering a registration by hand widens
     * nobody's access to anybody's data.
     *
     * Refusals: the service's own 422 message for a closed offering, an inactive
     * plan or a cross-tenant reference; the `{status:'failed', data:{field:[…]}}`
     * bag when the intake answers fail the offering's stored schema; 404 for
     * another organisation's offering in the route.
     */
    public function store(StoreRegistrationRequest $request, $masjid_id, $offering_id)
    {
        // OUTSIDE the try, so a foreign or missing offering stays a clean 404
        // rather than being swallowed into a 500 — same placement as every other
        // resolution in this controller.
        $offering = Offering::findOrFail($offering_id);

        // Scoped resolution, again outside the try. The request has already
        // proven both ids belong to this organisation; these reads are what turn
        // them into the rows the service needs.
        $feePlan = FeePlan::query()
            ->where('offering_id', $offering->id)
            ->findOrFail((int) $request->validated('fee_plan_id'));

        try {
            // ONE TRANSACTION AROUND THE PEOPLE AND THE REGISTRATION, which the
            // public path does not have and this path needs.
            //
            // Contacts are resolved BEFORE the service runs (contacts-first:
            // RegistrationService creates no Contact rows). If the service then
            // refuses — a closed offering, an intake answer that fails the
            // form's schema — the contacts it already created would survive the
            // refusal. On the public door that is a rare orphan; here it is a
            // LOOP: the admin fixes the field the modal marked, submits again,
            // and writes the family a second time. The service's own
            // transaction nests inside this one as a savepoint, so a refusal
            // takes the whole attempt back out.
            $registration = DB::transaction(function () use ($request, $offering, $feePlan) {
                return $this->registrations->register(
                    $offering,
                    $feePlan,
                    $this->resolvePayer($request),
                    (array) $request->input('data', []),
                    $this->resolveRegistrants($request),
                    // THE CLAIM, WITH ITS AUTHOR. `$request->user()` and never
                    // input: the same call `storeAdjustment()` makes about who
                    // granted aid.
                    new StaffEntry($request->user(), $request->validated('note'))
                );
            });

            return response()->json([
                'status' => 'success',
                'data' => $registration->load(['contact', 'feePlan', 'registrants.contact']),
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            // The intake answers failed the offering's OWN schema (FormSchema,
            // from the stored form). Field bag under `data`, the same shape
            // BaseFormRequest returns, so the modal marks the offending
            // question rather than showing one opaque sentence.
            return response()->json([
                'status' => 'failed',
                'data' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RegistrationException $e) {
            return response()->json([
                'status' => 'failed',
                'data' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Grant financial aid / a discount / a code against this registration.
     *
     * Delegates wholesale to RegistrationService::grantAdjustment(), which owns
     * every rule: unsigned reductions only, `adjusted_total_minor` re-derived
     * as list − Σ adjustments floored at 0, and a REFUSAL once any Stripe leg
     * exists — aid is strictly pre-checkout, because there is no post-hoc money
     * movement in this design. A 100% waiver takes the total to 0 and the
     * service confirms the seat through the free-path carve-out; it never mints
     * a $0 session.
     *
     * The refusal surfaces as a 422 carrying the service's own message. It is
     * never worked around here — the controller has no path to the adjustment
     * row that skips the guard.
     */
    public function storeAdjustment(GrantAdjustmentRequest $request, $masjid_id, $offering_id, $registration_id)
    {
        $registration = $this->findRegistration($offering_id, $registration_id);

        try {
            $adjustment = $this->registrations->grantAdjustment(
                $registration,
                (string) $request->validated('kind'),
                (int) $request->validated('amount_minor'),
                $request->validated('reason'),
                // The audit trail's "who": the authenticated admin, never input.
                $request->user()
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'adjustment' => $adjustment,
                    'registration' => $registration->refresh(),
                ],
            ], Response::HTTP_CREATED);
        } catch (RegistrationException $e) {
            return response()->json([
                'status' => 'failed',
                'data' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Promote a waitlisted registration into a seat — MANUAL only. The ratified
     * design defers automatic promotion (and the payment window it would need)
     * to its own task, so nothing promotes anybody without an admin asking.
     *
     * Capacity is re-checked under the offering's row lock inside the service:
     * promoting can never oversell an offering, even against a public
     * registration taking the last seat at the same moment. A full offering, or
     * a registration that is not on the waitlist, comes back as a 422.
     */
    public function promote($masjid_id, $offering_id, $registration_id)
    {
        $registration = $this->findRegistration($offering_id, $registration_id);

        try {
            $promoted = $this->registrations->promoteFromWaitlist($registration);

            return response()->json([
                'status' => 'success',
                'data' => $promoted,
            ], Response::HTTP_OK);
        } catch (RegistrationException $e) {
            return response()->json([
                'status' => 'failed',
                'data' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cancel a registration explicitly.
     *
     * Two halves, in this order:
     *
     *  1. STRIPE — if this registration carries a subscription (installment or
     *     recurring; those legs arrive in T-006e), it is cancelled on the org's
     *     connected account first, so cancelling a seat can never leave a
     *     family being charged every month for a place they no longer hold. A
     *     registration without one skips the call entirely. A Stripe failure is
     *     logged, not fatal — an outage must not block the local cancel.
     *  2. LOCAL — RegistrationService::cancel() releases the seat through
     *     `releaseSeat()` (the single writer of the guarded seat counter, in
     *     its admin mode) and marks the row cancelled. A settled charge keeps
     *     its `paid` money status: v1 refunds are the org's own action in its
     *     Stripe dashboard, and restating a settled ledger row would be a lie.
     *
     * Idempotent: cancelling a cancelled registration is a no-op success.
     * Group memberships are deliberately left alone — see the service.
     */
    public function cancel($masjid_id, $offering_id, $registration_id)
    {
        $registration = $this->findRegistration($offering_id, $registration_id);

        try {
            $subscriptionCancelled = $this->checkout->cancelSubscription($registration);

            $cancelled = $this->registrations->cancel($registration);

            return response()->json([
                'status' => 'success',
                'data' => $cancelled,
                'meta' => ['stripe_subscription_cancelled' => $subscriptionCancelled],
            ], Response::HTTP_OK);
        } catch (RegistrationException $e) {
            return response()->json([
                'status' => 'failed',
                'data' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ---------------------------------------------------------- resolution

    /**
     * Who is paying, from whichever of the two payer keys the modal sent.
     *
     * ONLY ONE OF THE TWO KEYS EVER ARRIVES. `StoreRegistrationRequest::
     * withValidator()` refuses a body carrying `payer_contact_id` AND
     * `payer.name` with the same sentence it gives a registrant row, because
     * "this existing person, but called that" has no honest resolution: the
     * payer is the contact the seat is filed under and one end of every guardian
     * edge `writeRosterMemberships()` writes, so picking the id and dropping the
     * typed name attaches a child to whoever the id pointed at and says nothing
     * about it. Before that rule the branch below was that silent drop.
     *
     * The precedence it leaves behind is therefore only a defensive default —
     * unreachable over HTTP, and kept pointing at the id for the same reason the
     * offline donation does: an id is a decision the admin made by clicking a
     * real person in the typeahead, a name is only a lookup. The request has
     * already proven the id belongs to this organisation, so this is a scoped
     * read and not a second authorisation.
     *
     * A typed name always CREATES. It deliberately does not find-or-create by
     * name the way `DonorContactService` does for a cheque writer: a donation
     * attaches money to a person, while this attaches a SEAT and, through the
     * roster, a guardian edge — so a near-miss on a name would enrol one
     * family's child against another family's record. A duplicate contact is
     * visible on the directory screen and reconcilable by the merge verb; a
     * false match is not. Same direction every near-miss on the public path
     * takes, for the same reason.
     */
    private function resolvePayer(StoreRegistrationRequest $request): Contact
    {
        if (filled($request->input('payer_contact_id'))) {
            return Contact::findOrFail((int) $request->input('payer_contact_id'));
        }

        return $this->createContact((array) $request->input('payer', []));
    }

    /**
     * Who the registration is FOR, in the order the modal listed them.
     *
     * An empty list is a real answer, not a missing one:
     * `RegistrationService::normalizeRegistrants()` reads it as "the payer is
     * registering themselves", which is exactly what an adult signing up for an
     * adult class means.
     *
     * A row naming `contact_id` resolves to that existing person — the thing the
     * public endpoint refuses and this route is allowed to do; see store(). A row
     * naming only a name creates one, exactly as the public path does for a child
     * whose record does not exist yet.
     *
     * @return array<int,Contact>
     */
    private function resolveRegistrants(StoreRegistrationRequest $request): array
    {
        $registrants = [];

        foreach ((array) $request->input('registrants', []) as $person) {
            if (! is_array($person)) {
                continue;
            }

            $registrants[] = filled($person['contact_id'] ?? null)
                ? Contact::findOrFail((int) $person['contact_id'])
                : $this->createContact($person);
        }

        return $registrants;
    }

    /**
     * A new contact for somebody the directory does not hold yet.
     *
     * `masjid_id` is NOT set here: this route is tenant-BOUND, so
     * BelongsToMasjid's creating hook stamps it — the opposite of the public
     * endpoint, which runs unbound and must set it explicitly. Setting it from
     * the route parameter as well would be a second answer to a question the
     * trait already answers.
     *
     * THE ADDRESS IS LOWER-CASED, not merely trimmed. Every identity comparison
     * in this application — `GroupAudience::identitiesFor()`,
     * `FamilyAccessService`, the public registration resolver — is made on
     * `LOWER(email)`, and storing the typed capitalisation is how one child
     * ended up as four contacts.
     *
     * AND IT NEVER TAKES AN ADDRESS ANOTHER CONTACT ALREADY HOLDS — the same
     * rule `Api\V1\OfferingRegistrationsController::createContact()` follows,
     * kept identical on purpose. `contacts.email` is routinely a HOUSEHOLD
     * mailbox, so two siblings typed at the desk under one address are two
     * people and the second gets a row with no address, exactly as a child with
     * no address of their own always had. A second row carrying a live address
     * is what makes a staff identity AMBIGUOUS, and ambiguous identity is no
     * identity: it is what once 403'd a teacher out of the classroom she taught,
     * permanently, with nothing on any screen saying why. An authenticated admin
     * typing it does not make that outcome any less bad, and the office can add
     * the address to the right record afterwards.
     *
     * `last_name` is NOT NULL, so a mononym degrades to an empty last name
     * rather than failing a registration the family is standing at the desk for.
     *
     * @param  array{name?:?string,email?:?string,phone?:?string}  $person
     */
    private function createContact(array $person): Contact
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($person['name'] ?? '')));
        $space = strpos($name, ' ');

        $email = Str::lower(trim((string) ($person['email'] ?? '')));
        $phone = trim((string) ($person['phone'] ?? ''));

        // Tenant-bound, so this scoped read asks the right organisation.
        if ($email !== '' && Contact::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $email = '';
        }

        return Contact::create([
            'first_name' => $space === false ? ($name ?: 'Registrant') : substr($name, 0, $space),
            'last_name' => $space === false ? '' : trim(substr($name, $space + 1)),
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
        ]);
    }

    /**
     * Resolve a registration THROUGH its offering, both tenant-scoped. A
     * foreign offering, a foreign registration, or a registration that belongs
     * to a different offering of this same organization are all the same 404 —
     * kept outside every try/catch so a miss stays a 404 and never a 500.
     *
     * @param  array<int,string>  $with
     */
    private function findRegistration($offering_id, $registration_id, array $with = []): Registration
    {
        $offering = Offering::findOrFail($offering_id);

        return Registration::query()
            ->where('offering_id', $offering->id)
            ->with($with)
            ->findOrFail($registration_id);
    }

    /** The shared roster filter, so both views narrow identically. */
    private function filteredQuery(Request $request, Offering $offering)
    {
        $search = $request->query('search');

        return Registration::query()
            ->where('offering_id', $offering->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when(
                $request->filled('payment_status'),
                fn ($q) => $q->where('payment_status', $request->query('payment_status'))
            )
            ->when($search, function ($query, $search) {
                // The payer/guardian is who an admin searches by; the scoped
                // relation keeps the join inside this organization.
                $query->whereHas('contact', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
    }

    /**
     * The people view: one row per registrant, each carrying the registration
     * it belongs to, narrowed by the same filters as the registration view.
     */
    private function registrantView(Request $request, Offering $offering)
    {
        $matching = $this->filteredQuery($request, $offering)->select('id');

        return Registrant::query()
            ->whereIn('registration_id', $matching)
            ->with(['contact', 'registration.feePlan'])
            ->orderBy('registration_id')
            ->paginate($request->query('per_page', 15));
    }
}
