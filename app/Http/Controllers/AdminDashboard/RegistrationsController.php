<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Registrations\GrantAdjustmentRequest;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Services\Registrations\RegistrationException;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the roster of one offering, plus the three explicit actions an
 * administrator may take on a registration — grant aid, promote off the
 * waitlist, cancel (T-006d, docs/t006-registration-billing-design.md).
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
