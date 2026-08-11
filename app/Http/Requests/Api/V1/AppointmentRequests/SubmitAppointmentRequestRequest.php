<?php

namespace App\Http\Requests\Api\V1\AppointmentRequests;

use App\Http\Requests\BaseFormRequest;

/**
 * The request boundary for the PUBLIC appointment-request endpoint (PLAN T-021).
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 field bag instead of a raw ValidationException, which this app's JSON
 * renderer would turn into a 500 — the same contract the clinic's Nuxt site
 * already reads off the form-submission endpoint.
 *
 * date_of_birth is pinned to Y-m-d (not the looser `date`) so the value that
 * gets ENCRYPTED is canonical: ciphertext cannot be normalized later without
 * decrypting every row.
 *
 * NO PHI IN LOGS — nothing here (or anywhere on this path) may hand the input
 * to Log::*; validation failures return to the caller only.
 */
class SubmitAppointmentRequestRequest extends BaseFormRequest
{
    /**
     * Deliberately unauthenticated — this is the public submit URL. Which
     * organization receives the request is decided in the controller from the
     * `masjid-id` header; there is no user here to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'applicant_name' => 'required|string|max:255',
            // Free-form (with a sane cap): a clinic must not lose a patient to
            // a phone-format regex. Email is optional — not everyone has one.
            'phone' => 'required|string|max:32',
            'email' => 'nullable|email|max:255',
            'date_of_birth' => 'required|date_format:Y-m-d|before_or_equal:today|after:1900-01-01',
            'reason' => 'required|string|max:5000',
            'preferred_window' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.date_format' => 'The date of birth must be in YYYY-MM-DD format.',
            'date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
        ];
    }
}
