<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;

/**
 * An order taken by staff on the lunch board (POST .../menus/{menu_id}/orders).
 *
 * Prices are never accepted: the server reads them from the menu. `paid` is
 * the only flag, and the SPA posts it form-encoded, so "1"/"0"/"true"/"false"
 * are coerced here — Laravel's `boolean` rule rejects the words, which once
 * blocked every live lunch order (see .claude/rules/shipping.md).
 */
class StoreStaffMealOrderRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('paid')) {
            $this->merge([
                'paid' => filter_var($this->input('paid'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:100',
            'items.*.item_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'customer_name' => 'required|string|max:120',
            // A walk-up at the table may give only a name.
            'customer_phone' => 'nullable|string|max:32',
            'customer_email' => 'nullable|email|max:190',
            'customer_notes' => 'nullable|string|max:500',
            'paid' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'customer_name.required' => "Enter the customer's name.",
        ];
    }
}
