<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Zakat\CalculateZakatRequest;
use App\Models\Masjid;
use App\Support\Errors;
use App\Support\ZakatCalculator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public zakat calculator (PLAN T-031).
 *
 * Recon found a calculator to be the single most-requested zakat tool and
 * missing from every generic donation platform, so it ships as a first-class
 * public endpoint rather than as JavaScript on one tenant's marketing site: the
 * assumptions behind the number belong in one auditable place, not copied into
 * every front end that wants to show it.
 *
 * Follows the /api/v1 idiom exactly (FormSubmissionsController,
 * AppointmentRequestsController):
 *
 *  - The organization comes from the `masjid-id` header and must exist. Same
 *    404 for a missing header target as for a bogus one — no probing which
 *    tenant ids are live.
 *  - Throttled by name (`throttle:zakat-calculator`), keyed by IP and target
 *    organization, so hammering one masjid's calculator cannot lock a visitor
 *    out of another's.
 *  - Validation failures return the legacy {status:'failed'} 422 field bag.
 *
 * ## This endpoint WRITES NOTHING
 *
 * Unlike the other public POSTs here, nothing is persisted — the tenant is
 * resolved for throttling, branding and future per-org configuration, and no
 * row is created. That is also why `calculate` is a POST despite being a pure
 * read: its body is a person's complete net worth, and a GET would put that in
 * a query string and from there into access logs and browser history. The
 * figures must never reach Log::* on any path; the catch below reports through
 * Errors::publicMessage, which logs exception metadata only, never input.
 *
 * The arithmetic and, more importantly, every disputed position it rests on live
 * in App\Support\ZakatCalculator and travel back in the payload.
 */
class ZakatCalculatorController extends Controller
{
    /**
     * POST /api/v1/zakat/calculate
     */
    public function calculate(CalculateZakatRequest $request)
    {
        $tenantMiss = $this->resolveTenant($request);

        if ($tenantMiss !== null) {
            return $tenantMiss;
        }

        try {
            return response()->api(
                200,
                'Zakat calculated.',
                ZakatCalculator::fromConfig()->calculate($request->validated())
            );
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * GET /api/v1/zakat/nisab
     *
     * The threshold on its own, so a site can publish "nisab today is X" without
     * asking a visitor for their net worth. A GET is right here precisely
     * because nothing personal is involved — only a basis and a metal price.
     */
    public function nisab(Request $request)
    {
        $tenantMiss = $this->resolveTenant($request);

        if ($tenantMiss !== null) {
            return $tenantMiss;
        }

        // Validated OUTSIDE the catch below on purpose: a validation failure is
        // an HttpResponseException, which IS an \Exception, so catching it here
        // would turn the 422 field bag into an opaque 500.
        $filters = $this->referenceFilters($request);

        try {
            return response()->api(
                200,
                'Nisab reference.',
                ZakatCalculator::fromConfig()->reference($filters)
            );
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * The `masjid-id` header contract shared by both methods.
     *
     * Returns null when the tenant resolves, or the response to send when it
     * does not — the same two answers FormSubmissionsController gives, in the
     * same order, so a caller cannot tell an unknown id from one that exists but
     * is not reachable.
     */
    private function resolveTenant(Request $request)
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return response()->api(400, 'A masjid must be specified.', null);
        }

        if (! Masjid::whereKey($masjidId)->exists()) {
            return response()->api(404, 'The zakat calculator is not available.', null);
        }

        return null;
    }

    /**
     * Query-string options for the nisab reference, validated against the same
     * whitelist the calculator uses.
     *
     * Validated rather than silently coerced for the reason
     * DonationStatsController states: a mistyped basis that degraded into "use
     * the default" would hand a reader a confident threshold computed on a
     * position they did not choose. Thrown rather than returned so the JSON
     * renderer hands the 422 envelope back verbatim.
     *
     * @return array<string,mixed>
     */
    private function referenceFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'basis' => ['nullable', Rule::in(ZakatCalculator::BASES)],
            'nisab_price_per_gram' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        // Nulls stripped so an empty ?basis= falls through to the configured
        // default instead of being read as an explicit (invalid) choice.
        return array_filter($validator->validated(), fn ($v) => $v !== null && $v !== '');
    }
}
