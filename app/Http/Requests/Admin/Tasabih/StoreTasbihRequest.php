<?php

namespace App\Http\Requests\Admin\Tasabih;

use App\Http\Requests\BaseFormRequest;

class StoreTasbihRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'text' => 'required|array',
            'text.*' => 'required|string',
            'pronunciation' => 'required|string',
            'reference' => 'nullable|string',
        ];
    }
}
