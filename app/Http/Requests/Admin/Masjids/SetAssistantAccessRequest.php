<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the SuperAdmin Masjid Assistant feature-gate toggle
 * (PATCH .../assistant-access).
 *
 * Uses a FormRequest (not inline $request->validate()) so validation failure is
 * thrown as an HttpResponseException by BaseFormRequest and rendered as a clean
 * 422 by the app's JSON exception handler — an inline validate() throws a raw
 * ValidationException, which that handler treats as a 500.
 */
class SetAssistantAccessRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
