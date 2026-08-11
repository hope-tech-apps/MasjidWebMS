<?php

namespace App\Http\Requests\Admin\Credentials;

use App\Models\ContactCredential;
use Illuminate\Validation\Rule;

/**
 * Create a credential on a contact.
 */
class StoreContactCredentialRequest extends ContactCredentialFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(ContactCredential::KINDS)],
            // The free-text name is mandatory exactly when the constant set
            // cannot say what this is — that pairing is what lets KINDS stay
            // small without ever losing information.
            'label' => ['required_if:kind,' . ContactCredential::KIND_OTHER, 'nullable', 'string', 'max:255'],
            'issuing_body' => 'nullable|string|max:255',
            // Length-checked as PLAINTEXT here; the encrypted cast expands it
            // in the column, which is why the column is TEXT.
            'identifier' => 'nullable|string|max:255',
            'issued_at' => 'nullable|date',
            // Ordering is only checkable when both dates were sent;
            // `after_or_equal` against an absent field cannot decide anything.
            // Same idiom as StoreGroupRequest's date window.
            'expires_at' => array_values(array_filter([
                'nullable', 'date',
                $this->filled('issued_at') ? 'after_or_equal:issued_at' : null,
            ])),
            'notes' => 'nullable|string',
            'document' => $this->documentRules(),
        ];
    }
}
