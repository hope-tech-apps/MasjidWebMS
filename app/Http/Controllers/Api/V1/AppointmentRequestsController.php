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
 *  - The success payload carries NO identifier: `data.id` is null on both the
 *    real and the honeypot branch. The primary key is a global auto-increment,
 *    so returning it would let any anonymous caller meter platform-wide intake
 *    volume by sampling it. See the comment on the return below.
 *
 * NO PHI IN LOGS — the payload (name, DOB, reason) must never reach Log::* on
 * any path here. The catch below reports through Errors::publicMessage, which
 * logs exception metadata only, never request input.
 *
 * ---------------------------------------------------------------------------
 * THIS PATH SENDS NO MAIL, AND THAT IS A DECISION — NOT AN OVERSIGHT (T-043h)
 * ---------------------------------------------------------------------------
 *
 * The obvious next ticket on any intake form is "email the applicant a
 * confirmation". Do not add one here. On an endpoint that is unauthenticated
 * and accepts an arbitrary `email`, a confirmation is an open mail relay
 * wearing the organisation's face: the caller chooses the RECIPIENT (anyone at
 * all, including someone who never contacted the clinic), the caller chooses
 * the CONTENT, because `applicant_name` and `reason` are free text a
 * confirmation would quote back, and the message goes out signed by and
 * charged against the organisation's own sending domain — the same domain they
 * need in order to reach real patients.
 *
 * The `appointment-request` throttle is not the control for that. Eight rows an
 * hour per ip|masjid-id is a sensible cap on a triage queue; it is not a
 * sensible cap on outbound mail to strangers, and treating it as one makes a
 * limiter that exists to protect the queue into the only thing standing between
 * the clinic's domain and a blocklist.
 *
 * What the visitor gets instead is the sentence the success message already
 * carries: the office will call. If a confirmation is ever genuinely wanted, it
 * belongs on the ADMIN side, sent to a request a human has opened and looked
 * at — a completely different trust position, where the recipient is a row
 * somebody chose rather than a string somebody posted.
 *
 * AppointmentRequestPublicAbuseSurfaceTest fakes the mailer and the notifier and
 * fails if anything is ever sent, queued or notified from this path.
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
            AppointmentRequest::create([
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

            // Nothing identifying goes back, and `id` is null ON PURPOSE — the
            // same payload the honeypot branch returns.
            //
            // `appointment_requests.id` is a GLOBAL auto-increment. Handing it
            // to an anonymous submitter turns the intake form into a live meter
            // of platform-wide intake volume: two junk submissions a day apart
            // subtract to the exact number of appointment requests every tenant
            // created in between, and on a single-clinic deployment that IS
            // that clinic's daily patient volume, sampleable indefinitely by
            // anyone who can POST the form. Nothing can consume the value —
            // there is no public read endpoint for an appointment request — and
            // the honeypot branch above has always returned null here, so any
            // client that leaned on it was already broken by the first bot.
            //
            // The key stays present so the response SHAPE does not change and
            // the two branches stay byte-identical (a submitter must not be able
            // to tell a honeypot trip from a real save).
            //
            // If the intake page ever genuinely needs a handle, it must be an
            // opaque per-row value (a uuid column), never the sequence.
            return response()->api(200, 'Thank you — your request has been received.', [
                'id' => null,
            ]);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }
}
