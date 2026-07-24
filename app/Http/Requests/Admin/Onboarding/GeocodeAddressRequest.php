<?php

namespace App\Http\Requests\Admin\Onboarding;

use App\Http\Requests\BaseFormRequest;

/**
 * Validation for the onboarding intake geocode call. Extends BaseFormRequest so
 * a validation failure throws the legacy { status:'failed', data:<errors> }
 * 422 envelope rather than a raw ValidationException (which this app's JSON
 * handler would 500 on). Route middleware (auth:sanctum + admin + super)
 * enforces that only a SuperAdmin reaches here.
 */
class GeocodeAddressRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'address' => 'required|string|max:1000',
        ];
    }
}
