<?php

namespace App\Http\Requests\Admin\Properties;

use App\Http\Requests\BaseFormRequest;

/**
 * Create a rental property. Amounts arrive as DOLLARS from the form and are
 * converted to integer cents in the controller. BaseFormRequest so a bad payload
 * renders 422, not 500.
 */
class StorePropertyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tenant_name' => ['nullable', 'string', 'max:255'],
            'monthly_rent' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
