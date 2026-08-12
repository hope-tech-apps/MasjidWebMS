<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the SuperAdmin public-directory toggle
 * (PATCH .../directory-listing).
 *
 * Same shape and the same reason as SetCrmAccessRequest: a FormRequest so a
 * validation failure is thrown as an HttpResponseException by BaseFormRequest
 * and rendered as a clean 422 — an inline validate() raises a raw
 * ValidationException, which this app's JSON handler turns into a 500.
 */
class SetDirectoryListingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'listed' => ['required', 'boolean'],
        ];
    }
}
