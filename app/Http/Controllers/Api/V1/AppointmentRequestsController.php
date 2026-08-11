<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppointmentRequests\SubmitAppointmentRequestRequest;
use App\Models\AppointmentRequest;
use App\Models\Masjid;
use App\Support\Errors;

/**
 * The public appointment-request endpoint (PLAN T-021, Community vertical).
 *
 * Replaces the free clinic's plaintext-Gmail intake: the request is stored in
 * the database with date_of_birth and reason ENCRYPTED at rest, readable only
 * through the permission-gated admin endpoints.
 *
 * An unauthenticated write, so it follows the public form-submission idiom
 * (FormSubmissionsController) defensively:
 *
 *  - The organization comes from the `masjid-id` header and must exist. Same
 *    404 for a missing header target as for a bogus one — no probing which
 *    tenant ids are live.
 *  - masjid_id is set explicitly here: /api/v1 never runs the tenant
 *    middleware, so the BelongsToMasjid creating hook has nothing bound and
 *    the controller owns the stamp (exactly as FormResponse does).
 *  - A honeypot field catches the naive bots; `throttle:appointment-request`
 *    catches the rest.
 *  - Validation failures return the legacy {status:'failed'} 422 field bag
 *    via SubmitAppointmentRequestRequest (a BaseFormRequest).
 *
 * NO PHI IN LOGS — the payload (name, DOB, reason) must never reach Log::* on
 * any path here. The catch below reports through Errors::publicMessage, which
 * logs exception metadata only, never request input.
 */
class AppointmentRequestsController extends Controller
{
    /**
     * POST /api/v1/appointment-requests
     */
    public function store(SubmitAppointmentRequestRequest $request)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            // The FK would reject an unknown tenant anyway, but as a 500; this
            // answers with the same shape the form endpoint uses for a miss.
            if (! Masjid::whereKey($masjidId)->exists()) {
                return response()->api(404, 'Appointment requests are not available.', null);
            }

            // A bot filling every input trips this; a human never sees the
            // field. Report success so a scripted submitter gets no signal to
            // adapt to, while nothing is written.
            if (filled($request->input('website'))) {
                return response()->api(200, 'Thank you — your request has been received.', [
                    'id' => null,
                ]);
            }

            // Only the validated fields, listed explicitly — a client-supplied
            // masjid_id / status / source in the body never reaches create().
            $created = AppointmentRequest::create([
                'masjid_id' => $masjidId,
                'applicant_name' => $request->input('applicant_name'),
                'phone' => $request->input('phone'),
                'email' => $request->input('email'),
                'date_of_birth' => $request->input('date_of_birth'),
                'reason' => $request->input('reason'),
                'preferred_window' => $request->input('preferred_window'),
                'status' => AppointmentRequest::STATUS_NEW,
                'source' => AppointmentRequest::SOURCE_WEB,
                // Operational metadata for abuse response, mirroring
                // form_responses — never used for anything clinical.
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            ]);

            // Only the id goes back — the submitter typed the rest and the
            // response must not become a second copy of the PII in transit.
            return response()->api(200, 'Thank you — your request has been received.', [
                'id' => $created->id,
            ]);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }
}
