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
