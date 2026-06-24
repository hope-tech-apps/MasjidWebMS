<?php

namespace App\Http\Requests\Admin\AzkarCategories;

use App\Http\Requests\BaseFormRequest;

class StoreAzkarCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
        ];
    }
}
