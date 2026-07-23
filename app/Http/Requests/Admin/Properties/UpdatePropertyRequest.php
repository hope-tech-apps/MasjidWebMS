<?php

namespace App\Http\Requests\Admin\Properties;

use App\Http\Requests\BaseFormRequest;

class UpdatePropertyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tenant_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'monthly_rent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
