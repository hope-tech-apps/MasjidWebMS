<?php

namespace App\Http\Requests\Admin\ContactReasons;

use App\Http\Requests\BaseFormRequest;

class UpdateContactReasonRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
            'order' => 'nullable|integer|min:0',
        ];
    }
}
