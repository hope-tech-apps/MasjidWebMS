<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\ImpactMetrics;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the impact report — an organization's REAL figures for a date range,
 * computed from the rows it already holds, with the provenance a grant reviewer
 * will ask for (PLAN T-024).
 *
 * Read-only and deliberately thin, exactly like DonationStatsController: the
 * definitions and the SQL live in App\Support\ImpactMetrics so the same numbers
 * can be produced by anything else that needs them (a console report, the
 * Assistant) without a second implementation drifting away from this one.
 *
 * THIS ENDPOINT PUBLISHES NOTHING. The `impact_stats` page section (T-020)
 * remains the authoritative source of what a visitor or funder sees on the
 * public site, and its values remain display text an admin typed. There is no
 * write path from here to `page_sections` — an admin who wants a computed
 * figure on the public page copies it there themselves, as an editorial act.
 * See the ImpactMetrics docblock and .claude/rules/impact-metrics.md.
 *
 * Tenant-scoped by the `tenant` middleware + BelongsToMasjid, plus the explicit
 * re-binding inside ImpactMetrics — nothing here hand-filters by $masjid_id.
 * See .claude/rules/tenant-scoping.md.
 */
class ImpactMetricsController extends Controller
{
    /** GET /api/admin/masjids/{masjid_id}/impact/report */
    public function report(Request $request, $masjid_id)
    {
        $filters = $this->filters($request);
        $metrics = ImpactMetrics::forMasjid($this->masjidFor($masjid_id));

        // Money-bearing metrics take the DONATIONS permission family; everything
        // else rides the CONTACTS family the route is gated on. The T-006d
        // precedent, applied to a payload rather than to separate routes: one
        // report cannot be split across two routes without making the admin
        // assemble it, so the split moves inside — and a caller without
        // `view donations` gets the report with the money metrics named in
        // `meta.omitted` rather than silently missing. Reusing both families
        // rather than minting `view impact` keeps the seeded permission set
        // (Permission::count() === 8) that RolePermissionBridgeTest pins.
        $includeMoney = (bool) $request->user()?->can('view donations');

        $report = $metrics->report($filters, $includeMoney);

        return response()->json([
            'status' => 'success',
            'data' => ['metrics' => $report['metrics']],
            'meta' => array_merge($metrics->meta($filters), [
                'omitted' => $report['omitted'],
            ]),
        ], Response::HTTP_OK);
    }

    /**
     * The masjid is read for its TIMEZONE and its ORG_TYPE — "this year" has to
     * mean the organization's year, and which metrics are in the default set is
     * a function of the vertical. It is not a scoping device: `tenant` has
     * already bound the context to this masjid (and 403'd a MasjidAdmin aiming
     * at anyone else's), and `crm` has already 403'd if the row does not exist.
     *
     * findOrFail rather than DonationStatsController's find(): a report names
     * the organization it is about in every figure's provenance, so there is no
     * sane fallback for "no masjid resolved" — 404 is the honest answer.
     */
    private function masjidFor($masjid_id): Masjid
    {
        return Masjid::findOrFail($masjid_id);
    }

    /**
     * The reporting period. Both bounds optional, and a null bound is unbounded
     * on that side — the same convention as the donation stats filters and as
     * offerings' registration windows. With neither bound the report covers all
     * time, and says so (`meta.period` is null/null) rather than defaulting to
     * a window the reader was never told about.
     *
     * @return array{from:?string,to:?string}
     */
    private function filters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            // Same 422 envelope BaseFormRequest produces. Thrown rather than
            // returned because the JSON renderer in bootstrap/app.php hands an
            // HttpResponseException's response back verbatim, while a bare
            // ValidationException would be caught by its generic 500 branch.
            // Mirrors DonationStatsController::filters().
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $validator->validated();
    }
}
