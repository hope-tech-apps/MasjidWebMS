<?php

namespace App\Http\Requests\Admin\Credentials;

use App\Models\ContactCredential;
use Illuminate\Validation\Rule;

/**
 * Update a credential.
 *
 * Every field is `sometimes`: a caller that only pushes back an expiry date
 * after a renewal must not be forced to resend the license number. Sending a
 * `document` REPLACES the stored one (there is only ever one per credential);
 * omitting it leaves the stored one alone.
 */
class UpdateContactCredentialRequest extends ContactCredentialFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'required', Rule::in(ContactCredential::KINDS)],
            // required_if only fires when this request SETS kind=other, so a
            // caller reclassifying a credential as `other` must say what it is
            // in the same breath — a stored row can never end up as a nameless
            // "other".
            'label' => ['required_if:kind,' . ContactCredential::KIND_OTHER, 'nullable', 'string', 'max:255'],
            'issuing_body' => 'sometimes|nullable|string|max:255',
            'identifier' => 'sometimes|nullable|string|max:255',
            'issued_at' => 'sometimes|nullable|date',
            // Ordering is only checkable when the caller sent both dates; same
            // idiom as UpdateGroupRequest's window.
            'expires_at' => array_values(array_filter([
                'sometimes', 'nullable', 'date',
                $this->filled('issued_at') ? 'after_or_equal:issued_at' : null,
            ])),
            'notes' => 'sometimes|nullable|string',
            'document' => $this->documentRules(),
        ];
    }
}
