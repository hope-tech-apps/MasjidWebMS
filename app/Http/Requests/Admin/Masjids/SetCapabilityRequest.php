<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the SuperAdmin capability toggle (PATCH .../capabilities/{capability}).
 *
 * A FormRequest for the same reason as SetCrmAccessRequest: BaseFormRequest
 * renders a failure as a clean 422, where an inline validate() would 500. The
 * SPA sends "1"/"0" (form-encoded), which `boolean` accepts.
 */
class SetCapabilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
