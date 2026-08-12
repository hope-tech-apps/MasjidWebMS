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
     */
    public function index(Request $request, $masjid_id)
    {
        $search = $request->query('search');

        $offerings = Offering::query()
            ->with(['feePlans' => fn ($q) => $q->orderBy('id')])
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
            ->paginate($request->query('per_page', 15));

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
     *  - `is_open` / `closed_reason`, so "published but the window shut in
     *    March" is visible at the moment of attaching, not after a family
     *    complains. Derived server-side (Offering::getIsOpenAttribute); the
     *    browser must not reimplement the null-bound window and the server clock.
     *  - `active_fee_plan_count`, because POST /offerings/{slug}/register takes a
     *    `fee_plan_id` and an offering with no ACTIVE plan cannot take a single
     *    registration. That is the one misconfiguration a page builder cannot
     *    see from the offering's name.
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
                ->withCount(['feePlans as active_fee_plan_count' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (Offering $offering) => [
                    'id' => $offering->id,
                    'name' => $offering->name,
                    'slug' => $offering->slug,
                    'kind' => $offering->kind(),
                    'is_active' => (bool) $offering->is_active,
                    // is_active AND the window — what the public register path
                    // actually enforces, never is_active alone.
                    'is_open' => (bool) $offering->is_open,
                    'closed_reason' => $offering->closed_reason,
                    'is_full' => $offering->isAtCapacity(),
                    'active_fee_plan_count' => (int) $offering->active_fee_plan_count,
                ]);

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
        $offering = Offering::with(['feePlans', 'intakeForm', 'group'])
            ->findOrFail($offering_id);

        return response()->json([
            'status' => 'success',
            'data' => $offering,
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
