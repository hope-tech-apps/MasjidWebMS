<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Offerings\StoreOfferingRequest;
use App\Http\Requests\Admin\Offerings\UpdateOfferingRequest;
use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Services\Registrations\RegistrationException;
use App\Support\Errors;
use App\Support\OfferingRegistrationState;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for Offerings — every registerable thing an organization runs:
 * an event, a program (weekend-school semester), an admission, a membership
 * (docs/t006-registration-billing-design.md, T-006d).
 *
 * Tenant isolation is NOT hand-rolled here. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant` middleware
 * binds TenantContext and BelongsToMasjid auto-scopes every Offering query — so
 * we never filter by $masjid_id and never set masjid_id from client input (the
 * creating hook stamps it). See .claude/rules/tenant-scoping.md.
 *
 * Nothing here names the concept: "Programs" / "Services" is the TENANT'S
 * vocabulary, served as `meta.offering_label` from the vertical's terminology
 * pack (.claude/rules/verticals.md).
 *
 * PRICES DO NOT LIVE HERE. An offering carries no money; its fee plans do, and
 * they are immutable (FeePlansController). That split is why offerings are
 * gated by the contacts permissions — an offering is a program structure over
 * the member directory, exactly like a group — while anything that decides or
 * waives what somebody is charged takes the donations permissions.
 */
class OfferingsController extends Controller
{
    /**
     * Paginated list of this organization's offerings, optionally narrowed by
     * ?search= (name/slug), ?kind= and ?active_only=.
     *
     * Each row carries `registration_state` / `registration_state_reason` — the
     * SAME verdict the public payload publishes, from the same function. The
     * Status column used to render `is_open` alone, which says nothing about the
     * intake form or the fee plans, so an offering that could not take a single
     * registration showed a green "Open" (App\Support\OfferingRegistrationState).
     */
    public function index(Request $request, $masjid_id)
    {
        $search = $request->query('search');

        $offerings = Offering::query()
            // All THREE relations the verdict reads are eager-loaded so it costs
            // no per-row query: `intakeForm` resolves to null for a soft-deleted
            // form, which is exactly the fact it is consulted for, and `masjid`
            // answers `canAcceptDonations()`. `masjid` was missing while
            // registrationState() already read it — measured on a page of five
            // offerings, six `select * from masjids` instead of two.
            ->with(['feePlans' => fn ($q) => $q->orderBy('id'), 'intakeForm', 'masjid'])
            ->withCount('registrations')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('kind'), fn ($q) => $q->ofKind($request->query('kind')))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->query('per_page', 15))
            // through() keeps the paginator (and therefore the SPA's pagination
            // block) intact; toArray() keeps every field, append and eager load
            // the list already read, so this only ADDS.
            ->through(fn (Offering $offering) => array_merge(
                $offering->toArray(),
                $this->registrationState($offering)
            ));

        return response()->json([
            'status' => 'success',
            'data' => $offerings,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/offerings/options
     *
     * The picker list for the `offering` page section (T-006g) — every offering
     * this organization has, with just enough beside each one for the editor to
     * WARN rather than let an admin publish a dead registration block:
     *
     *  - `registration_state` / `registration_state_reason` — the WHOLE verdict,
     *    identical to what the public payload publishes because it comes from
     *    the same function (App\Support\OfferingRegistrationState). This is what
     *    the editor should switch on; the three fields below are the detail
     *    behind it, not three separate verdicts to re-combine in the browser.
     *  - `is_open` / `closed_reason`, so "published but the window shut in
     *    March" is visible at the moment of attaching, not after a family
     *    complains. Derived server-side (Offering::getIsOpenAttribute); the
     *    browser must not reimplement the null-bound window and the server clock.
     *  - `active_fee_plan_count`, because POST /offerings/{slug}/register takes a
     *    `fee_plan_id` and an offering with no purchasable plan cannot take a
     *    single registration. That is the one misconfiguration a page builder
     *    cannot see from the offering's name. It counts plans that are active
     *    AND of a known `FeePlan::KINDS` kind — the same predicate the public
     *    payload publishes on, because a plan it withholds is not one a
     *    registrant can name either.
     *  - `has_intake_form`, because a soft-deleted intake form shuts an offering
     *    just as completely and shows up in NO other field: `is_open` still
     *    reports true and `closed_reason` still reports null.
     *  - `is_full`, so the editor can say the block will render a waitlist.
     *
     * NO SEAT NUMBERS AND NO ROSTER COUNTS. `registration_count` is a count of
     * people; this endpoint feeds a page-builder picker, which has no business
     * with the CRM. The admin roster screens are where those numbers live.
     *
     * Sits inside the `crm` gate on `permission:view contacts`, exactly like
     * index() above — attaching an offering to a page is choosing a program, and
     * this deliberately mints no softer authorization surface for the same rows.
     */
    public function options($masjid_id)
    {
        try {
            $offerings = Offering::query()
                ->with(['feePlans' => fn ($q) => $q->orderBy('id'), 'intakeForm', 'masjid'])
                ->orderBy('name')
                ->get()
                ->map(function (Offering $offering) {
                    $state = $this->registrationState($offering);

                    return array_merge([
                        'id' => $offering->id,
                        'name' => $offering->name,
                        'slug' => $offering->slug,
                        'kind' => $offering->kind(),
                        'is_active' => (bool) $offering->is_active,
                        // is_active AND the window — what the public register
                        // path actually enforces, never is_active alone.
                        'is_open' => (bool) $offering->is_open,
                        'closed_reason' => $offering->closed_reason,
                        'is_full' => $offering->isAtCapacity(),
                    ], $state);
                });

            return response()->json([
                'status' => 'success',
                'data' => $offerings,
                // Carries `offering_label` so the section editor can call the
                // concept what THIS tenant calls it — "Programs" for a masjid or
                // a school, "Services" for a community org — instead of
                // hardcoding a word (.claude/rules/verticals.md).
                'meta' => $this->meta(),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create an offering. masjid_id is intentionally omitted: the
     * BelongsToMasjid creating hook stamps it from the bound tenant, so a
     * client-supplied masjid_id can never plant a row in another organization.
     * `registration_count` is guarded on the model and starts at 0.
     */
    public function store(StoreOfferingRequest $request, $masjid_id)
    {
        try {
            $offering = Offering::create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $offering,
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show one offering with its fee plans and its seat position. findOrFail is
     * tenant-scoped, so another organization's id resolves to a 404 rather than
     * leaking the row.
     */
    public function show($masjid_id, $offering_id)
    {
        $offering = Offering::with(['feePlans', 'intakeForm', 'group', 'masjid'])
            ->findOrFail($offering_id);

        return response()->json([
            'status' => 'success',
            // The detail header's badge reads `registration_state`, not
            // `is_open`: an offering whose intake form is gone or whose plans
            // are all deactivated is still `is_open: true` and still refuses
            // every registration.
            'data' => array_merge($offering->toArray(), $this->registrationState($offering)),
            'meta' => $this->meta() + [
                'seats' => [
                    'capacity' => $offering->capacity,
                    'taken' => (int) $offering->registration_count,
                    // Null capacity is unlimited (the forms convention), so
                    // "remaining" is genuinely unknown rather than 0.
                    'remaining' => $offering->capacity === null
                        ? null
                        : max(0, (int) $offering->capacity - (int) $offering->registration_count),
                ],
                'registrations_by_status' => $this->statusCounts($offering),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Update an offering. The scoped findOrFail runs OUTSIDE the try so a
     * cross-tenant / missing id surfaces as a clean 404 instead of being
     * swallowed into a 500 by the catch below.
     */
    public function update(UpdateOfferingRequest $request, $masjid_id, $offering_id)
    {
        $offering = Offering::findOrFail($offering_id);

        try {
            $offering->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $offering,
                'meta' => $this->meta(),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft-delete an offering — and REFUSE outright while people still hold
     * live places in it.
     *
     * A pending registration has money in flight, a confirmed one holds a paid
     * seat, and a waitlisted one is a promise the organization made. Removing
     * the offering under any of them would strand exactly the people the
     * registration engine exists to keep track of, so the destructive path
     * refuses and points at the non-destructive one (is_active = false, which
     * closes intake and leaves every existing registration intact).
     *
     * Cancelled registrations do not block: nobody is holding anything. The
     * delete is SOFT, so the rows that reference the offering — registrations,
     * payments, fee plans — keep resolving.
     */
    public function destroy($masjid_id, $offering_id)
    {
        $offering = Offering::findOrFail($offering_id);

        $live = Registration::query()
            ->where('offering_id', $offering->id)
            ->whereIn('status', [
                Registration::STATUS_PENDING,
                Registration::STATUS_CONFIRMED,
                Registration::STATUS_WAITLISTED,
            ])
            ->count();

        if ($live > 0) {
            return response()->json([
                'status' => 'failed',
                'data' => RegistrationException::offeringHasLiveRegistrations($live)->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $offering->delete();

            return response()->json([
                'status' => 'success',
                'data' => $offering,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * "Could a family register for this right now, and if not why" — the four
     * fields every admin surface renders its status from.
     *
     * The verdict itself belongs to App\Support\OfferingRegistrationState, which
     * the PUBLIC payload also calls, so an admin screen and the page a parent
     * reads can never disagree about whether a program is open. Only the INPUTS
     * are gathered here, and all three come from relations the callers eager
     * load, so a page of offerings costs no extra query:
     *
     *  - the intake form must resolve AND belong to this organisation, matching
     *    OfferingPublicPayload::intakeForm(); `intakeForm` is a plain belongsTo
     *    with no tenant scope of its own, and a form soft-deleted underneath the
     *    NOT NULL `intake_form_id` loads as null, which is the fact wanted;
     *  - the fee plans, handed over RAW. This method used to filter them itself
     *    with an inline `is_active && in_array(kind, KINDS)` — a fourth copy of
     *    a predicate that has now grown two more clauses, and the copy would
     *    have been the one that missed them. `isPurchasable()` is applied inside
     *    the decider instead;
     *  - `masjid`, for `canAcceptDonations()`, CAST to a bool so a missing
     *    organisation reads as "cannot collect" rather than as "not asked" (see
     *    the line itself). That relation was NOT in the eager loads while this
     *    line already read it, so `index` lazy-loaded one `select * from
     *    masjids` PER ROW — measured at six on a page of five, under a docblock
     *    promising "a page of offerings costs no extra query".
     *
     * @return array{registration_state:string, registration_state_reason:?string, active_fee_plan_count:int, has_intake_form:bool}
     */
    private function registrationState(Offering $offering): array
    {
        $form = $offering->intakeForm;

        $hasIntakeForm = $form !== null
            && (int) $form->masjid_id === (int) $offering->masjid_id;

        // CAST, so a MISS reads as "cannot collect" and not as "not asked".
        // `masjid` is a belongsTo and `masjids` soft-deletes, so an offboarded
        // organisation resolves the relation to null — and `decide()` treats
        // null as no objection, deliberately, so that a caller who cannot answer
        // the question does not start withholding plans that are on sale. That
        // reading is right for a caller with no organisation handy and wrong
        // here, where the organisation is the offering's own: the admin verdict
        // read a green `open` for a program whose public page answers 404.
        // `OfferingRegistrationsController::organisationCanCollect()` already
        // casts the same miss to false, with the same argument — a miss is a
        // race, not a state, and false is the safe reading of it. Unreachable
        // today (the admin routes cannot bind an offboarded tenant); the two
        // surfaces now read the fact identically, which is the point.
        $canCollect = (bool) $offering->masjid?->canAcceptDonations();

        // Same question the public payload asks, of the same predicate, so the
        // admin screen and the family's page cannot disagree about whether a
        // program is open — nor about how many plans it can actually sell.
        $state = OfferingRegistrationState::decide(
            $offering,
            $hasIntakeForm,
            $offering->feePlans,
            $canCollect
        );

        return [
            'registration_state' => $state['state'],
            'registration_state_reason' => $state['reason'],
            // Plans a registrant could actually name in `fee_plan_id`, which is
            // what this field has always claimed to be. It therefore reads 0 for
            // an offering whose only plans are paid ones an un-onboarded
            // organisation cannot collect for — and the reason beside it says
            // `org_cannot_collect` rather than `no_fee_plan`, so an admin is
            // sent to Stripe onboarding and not off looking for a missing plan.
            'active_fee_plan_count' => count(
                OfferingRegistrationState::purchasablePlans($offering->feePlans, $canCollect)
            ),
            'has_intake_form' => $hasIntakeForm,
        ];
    }

    /**
     * How many registrations sit in each seat state — the roster summary an
     * admin opens the offering to see. Scoped like every other read here.
     *
     * @return array<string,int>
     */
    private function statusCounts(Offering $offering): array
    {
        $counts = Registration::query()
            ->where('offering_id', $offering->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $summary = [];

        foreach (Registration::STATUSES as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary;
    }

    /**
     * Vertical-aware labelling plus the vocabularies the admin UI needs to
     * render a form without hardcoding a list. What an offering is CALLED comes
     * from the tenant's terminology pack, never from a hardcoded string; the
     * masjid is read from the bound TenantContext rather than the route
     * parameter, so it stays server-derived (the GroupsController idiom).
     */
    private function meta(): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'offering_label' => $masjid?->term('programs') ?? 'Programs',
            'kinds' => Offering::KINDS,
            'fee_plan_kinds' => FeePlan::KINDS,
            'billing_intervals' => FeePlan::BILLING_INTERVALS,
            'registration_statuses' => Registration::STATUSES,
            'payment_statuses' => Registration::PAYMENT_STATUSES,
        ];
    }
}
