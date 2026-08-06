<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\DonationMetrics;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: giving dashboard numbers — header totals and the per-fund breakdown.
 *
 * Read-only and deliberately thin; the money rules live in App\Support\DonationMetrics
 * so the Assistant can answer the same questions without a second implementation
 * drifting away from this one.
 *
 * Tenant-scoped by the `tenant` middleware + BelongsToMasjid — nothing here
 * hand-filters by $masjid_id. See .claude/rules/tenant-scoping.md.
 */
class DonationStatsController extends Controller
{
    /** GET /api/admin/masjids/{masjid_id}/donations/stats/summary */
    public function summary(Request $request, $masjid_id)
    {
        $filters = $this->filters($request);
        $metrics = $this->metricsFor($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => $metrics->summary($filters),
            'meta' => $metrics->meta($filters),
        ], Response::HTTP_OK);
    }

    /** GET /api/admin/masjids/{masjid_id}/donations/stats/by-fund */
    public function byFund(Request $request, $masjid_id)
    {
        $filters = $this->filters($request);
        $metrics = $this->metricsFor($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => $metrics->byFund($filters),
            'meta' => $metrics->meta($filters),
        ], Response::HTTP_OK);
    }

    /**
     * The masjid is read for its timezone ONLY — "this month" has to mean the
     * masjid's month, not UTC's. It is not a scoping device: `tenant` has already
     * bound the context to this masjid (and 403'd a MasjidAdmin aiming at anyone
     * else's), and `crm` has already 403'd if the row does not exist, so the
     * lookup is a plain read with a safe fallback rather than a second guard.
     */
    private function metricsFor($masjid_id): DonationMetrics
    {
        return DonationMetrics::forMasjid(Masjid::find($masjid_id));
    }

    /**
     * The ledger's filter vocabulary, validated against the same whitelists the
     * metrics use — an unknown status would otherwise sail through and report a
     * confident $0 instead of an error.
     *
     * @return array{from:?string,to:?string,source:?string,status:?string}
     */
    private function filters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'source' => ['nullable', Rule::in(DonationMetrics::SOURCES)],
            'status' => ['nullable', Rule::in(DonationMetrics::STATUSES)],
        ]);

        if ($validator->fails()) {
            // Same 422 envelope BaseFormRequest produces. Thrown rather than
            // returned because the JSON renderer in bootstrap/app.php hands an
            // HttpResponseException's response back verbatim, while a bare
            // ValidationException would be caught by its generic 500 branch.
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $validator->validated();
    }
}
