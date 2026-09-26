<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;

/**
 * The contacts a bulk tag or untag acts on.
 *
 * The SPA posts form-encoded (`contact_ids[]=4&contact_ids[]=9`, per
 * .claude/rules/shipping.md) and a single-contact tag is the same request with
 * one id, so there is one shape for both. The cap is a page of the directory
 * several times over; it exists so one request cannot be made to hold a lock
 * over an organisation's whole contact table.
 *
 * Whether each id belongs to THIS organisation is decided in the controller,
 * through the tenant-scoped Contact query, not here: a rule that ran
 * `exists:contacts,id` would answer the question for every organisation.
 */
class ContactTagContactsRequest extends BaseFormRequest
{
    public const MAX_CONTACTS = 1000;

    public function rules(): array
    {
        return [
            'contact_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_CONTACTS],
            'contact_ids.*' => ['integer', 'min:1'],
        ];
    }

    /** @return array<int, int> distinct ids, in the order given */
    public function contactIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('contact_ids'))));
    }
}
