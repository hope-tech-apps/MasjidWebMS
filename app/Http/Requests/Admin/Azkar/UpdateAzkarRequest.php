<?php

namespace App\Http\Requests\Admin\Azkar;

use App\Http\Requests\BaseFormRequest;

class UpdateAzkarRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'azkar_category_id' => 'nullable|integer|min:1|exists:azkar_categories,id',
            'title' => 'required|array',
            'title.*' => 'required|string',
            'text' => 'required|array',
            'text.*' => 'required|string',
            'bless' => 'nullable|array',
            'bless.*' => 'nullable|string',
            'pronunciation' => 'required|string',
            'frequency' => 'nullable|integer|min:0',
            'reference' => 'nullable|string',
        ];
    }
}
