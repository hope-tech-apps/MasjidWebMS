<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Support\Errors;
use App\Support\FormStaffCodes;
use App\Support\PublicTenant;
use Illuminate\Http\Request;

/**
 * The gate's code exchange (DECISIONS.md 2026-09-11; festival brief, blocker 3).
 *
 * A staff member's phone presents their code here ONCE and gets back a short-lived
 * signed token, which it keeps for the tab and sends with each cash entry instead of
 * the code. That keeps the venue's shared wifi address out of the failure limiter
 * for every entry after the first (App\Support\FormStaffCodes explains why).
 *
 * The first phone to present a code claims it; any other phone is refused the same
 * way as a wrong code until an admin releases it. Unauthenticated and unbound, so,
 * as on the public submit, the masjid comes from the header, must still exist, and
 * must own the form. The answer is the token and its expiry and nothing else —
 * never the holder's name.
 */
class FormStaffSessionsController extends Controller
{
    /**
     * POST /api/v1/forms/{form_id}/staff-session  {staff_code, device_id}
     */
    public function store(Request $request, $form_id)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            if (! PublicTenant::exists($masjidId)) {
                return response()->api(404, 'This form is not available.', null);
            }

            $form = Form::query()
                ->where('masjid_id', $masjidId)
                ->whereKey($form_id)
                ->first();

            if (! $form) {
                return response()->api(404, 'This form is not available.', null);
            }

            $check = FormStaffCodes::attempt(
                $form,
                $masjidId,
                $request->input('staff_code'),
                $request->input('device_id'),
                $request->ip()
            );

            if ($check->isLockedOut()) {
                return FormStaffCodes::lockedOutResponse($check->retryAfter);
            }

            if (! $check->isAccepted()) {
                return FormStaffCodes::refusedResponse();
            }

            $token = FormStaffCodes::issueToken($check->code);

            return response()->api(200, 'Staff entry is ready.', [
                'staff_token' => $token['token'],
                'expires_at' => $token['expires_at']->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }
}
