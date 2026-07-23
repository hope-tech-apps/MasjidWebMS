<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;

/**
 * Merge a (placeholder) contact into another member: either an existing one
 * (target_contact_id) or a new member built from the supplied name/contact fields.
 */
class MergeContactRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'target_contact_id' => ['nullable', 'integer'],
            'first_name' => ['required_without:target_contact_id', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
        ];
    }
}
