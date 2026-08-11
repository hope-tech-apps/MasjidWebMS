<?php

namespace App\Http\Requests\Admin\AppointmentRequests;

use App\Http\Requests\BaseFormRequest;
use App\Models\AppointmentRequest;
use Illuminate\Validation\Rule;

/**
 * Move an appointment request through triage (new -> contacted -> scheduled ->
 * closed). The allowed set is AppointmentRequest::STATUSES — PHP constants, not
 * a DB enum — validated here at the boundary with Rule::in, exactly as group
 * kinds and org types are.
 *
 * Any status may be set, in any order, on purpose: triage is a label for staff,
 * not a state machine — a closed request must be reopenable when the patient
 * calls back, without a workflow edit.
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 instead of a raw ValidationException, which this app's JSON renderer
 * would turn into a 500.
 */
class UpdateAppointmentRequestStatusRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(AppointmentRequest::STATUSES)],
        ];
    }
}
