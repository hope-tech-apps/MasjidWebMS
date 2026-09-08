<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;

/**
 * Price is integer minor units (cents). The admin UI collects dollars and sends
 * cents, so the server never touches a float.
 */
class StoreMealMenuItemRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'name_ar' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:500',
            'description_ar' => 'nullable|string|max:500',
            'price_minor' => 'required|integer|min:0|max:100000000',
            'is_available' => 'sometimes|boolean',
            'max_quantity' => 'nullable|integer|min:1|max:100000',
            'sort_order' => 'sometimes|integer|min:0|max:100000',
        ];
    }
}
