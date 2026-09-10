<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;

/**
 * An order taken by staff on the lunch board (POST .../menus/{menu_id}/orders).
 *
 * Prices are never accepted: the server reads them from the menu. Nor is any
 * payment field: the order is charged through Stripe like a public order, and
 * only Stripe marks it paid. There is deliberately no "paid" flag — a
 * volunteer once marked their own order paid with no money changing hands.
 */
class StoreStaffMealOrderRequest extends BaseFormRequest
{
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
