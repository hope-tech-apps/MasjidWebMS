<?php

namespace App\Http\Requests\Api\V1\Registrations;

use App\Http\Requests\BaseFormRequest;

/**
 * The request boundary for the PUBLIC registration endpoint (T-006c).
 *
 * Only the ENVELOPE is validated here — who is paying, who is being registered,
 * and which plan. The intake ANSWERS in `data` are validated against the
 * offering's stored form schema (App\Support\FormSchema) inside
 * RegistrationService, never from the payload's own shape, exactly as the
 * public form-submission endpoint does.
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 field bag rather than a raw ValidationException.
 *
 * The payer's email is REQUIRED because a Contact is keyed on (masjid, email):
 * it is what makes a repeat registration attach to the same person instead of
 * spawning a duplicate, and it is where the org reaches a family. Registrants
 * (the children a guardian signs up) may have no email of their own.
 */
class RegisterForOfferingRequest extends BaseFormRequest
{
    /**
     * Deliberately unauthenticated — this is the public signup URL. The
     * organization comes from the `masjid-id` header in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fee_plan_id' => 'required|integer|min:1',

            // The payer / guardian.
            'payer' => 'required|array',
            'payer.name' => 'nullable|string|max:255',
            'payer.email' => 'required|email|max:255',
            'payer.phone' => 'nullable|string|max:32',

            // Who the registration is FOR. Absent means the payer registers
            // themselves. One registration is ONE seat however many people are
            // on it (a household with three children takes one seat), so the
            // cap here is about payload sanity, not capacity.
            'registrants' => 'nullable|array|max:20',
            'registrants.*.name' => 'required|string|max:255',
            'registrants.*.email' => 'nullable|email|max:255',
            'registrants.*.phone' => 'nullable|string|max:32',

            // The intake answers; the form's own schema validates the contents.
            'data' => 'required|array',
        ];
    }

    public function messages(): array
    {
        return [
            'payer.email.required' => 'An email address is required so the organization can reach you.',
        ];
    }
}
