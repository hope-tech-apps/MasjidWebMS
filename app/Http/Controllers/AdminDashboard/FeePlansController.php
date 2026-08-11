<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Offerings\StoreFeePlanRequest;
use App\Models\FeePlan;
use App\Models\Offering;
use App\Services\Registrations\RegistrationException;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin fee plans — CREATE and DEACTIVATE only (T-006d,
 * docs/t006-registration-billing-design.md).
 *
 * FEE PLANS ARE IMMUTABLE. T-006a/b snapshot `list_total_minor` /
 * `adjusted_total_minor` onto every registration from the plan at intake, and
 * `fee_plans.currency` is the denomination those snapshots are read back in.
 * Editing a plan that registrations already point at would therefore
 * retroactively restate what somebody agreed to pay — the exact
 * price-change-while-open failure the ratified design set out to kill. So the
 * write surface is deliberately create + deactivate, `registrations.fee_plan_id`
 * is a RESTRICT FK, and `update()` below REFUSES with a clear message rather
 * than quietly dropping the fields it was sent.
 *
 * Tenant isolation is the same guardrail as everywhere else in the CRM: the
 * `tenant` middleware binds TenantContext, BelongsToMasjid scopes every query,
 * and the offering is resolved through that scope first — so a plan can only
 * ever be created under an offering this organization owns.
 */
class FeePlansController extends Controller
{
    /**
     * The plans of one offering. Ordered oldest-first so the replacement chain
     * (deactivate-and-replace) reads in the order it happened.
     */
    public function index(Request $request, $masjid_id, $offering_id)
    {
        $offering = Offering::findOrFail($offering_id);

        $plans = FeePlan::query()
            ->where('offering_id', $offering->id)
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $plans,
        ], Response::HTTP_OK);
    }

    /**
     * Create a plan for this offering.
     *
     * offering_id comes from the ALREADY-SCOPED route lookup, never from the
     * body, and masjid_id is stamped by the BelongsToMasjid creating hook — so
     * neither can be pointed at another organization. Replacing a live price is
     * done by deactivating the old plan and creating a new one; in-flight
     * registrations keep the snapshot they were quoted.
     */
    public function store(StoreFeePlanRequest $request, $masjid_id, $offering_id)
    {
        $offering = Offering::findOrFail($offering_id);

        try {
            $plan = FeePlan::create($request->validated() + [
                'offering_id' => $offering->id,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $plan,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * REFUSED, always — a fee plan cannot be edited (see the class docblock).
     *
     * The route exists precisely so the refusal is explicit: an admin (or a
     * screen) that tries to edit a price gets a 422 saying what to do instead,
     * rather than a 200 whose fields were silently ignored. The plan is
     * re-resolved through the tenant scope first, so another organization's id
     * is still a 404 and this endpoint leaks nothing about what exists.
     */
    public function update($masjid_id, $offering_id, $fee_plan_id)
    {
        $offering = Offering::findOrFail($offering_id);

        FeePlan::query()
            ->where('offering_id', $offering->id)
            ->findOrFail($fee_plan_id);

        return response()->json([
            'status' => 'failed',
            'data' => RegistrationException::feePlanImmutable()->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * DEACTIVATE — never delete.
     *
     * Registrations reference their plan with a RESTRICT FK and read their
     * currency back through it, so the row must survive forever; there are no
     * softDeletes on this table for the same reason. Deactivating hides the
     * plan from the public offering payload (which lists active plans only) and
     * from any new registration, while every existing one keeps resolving.
     * Idempotent: deactivating a deactivated plan is a no-op success.
     */
    public function destroy($masjid_id, $offering_id, $fee_plan_id)
    {
        $offering = Offering::findOrFail($offering_id);

        $plan = FeePlan::query()
            ->where('offering_id', $offering->id)
            ->findOrFail($fee_plan_id);

        try {
            if ($plan->is_active) {
                $plan->is_active = false;
                $plan->save();
            }

            return response()->json([
                'status' => 'success',
                'data' => $plan,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
