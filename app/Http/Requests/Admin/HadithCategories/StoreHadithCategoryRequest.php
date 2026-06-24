<?php

namespace App\Http\Requests\Admin\HadithCategories;

use App\Http\Requests\BaseFormRequest;

class StoreHadithCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'order' => 'nullable|integer|min:0',
        ];
    }
}
