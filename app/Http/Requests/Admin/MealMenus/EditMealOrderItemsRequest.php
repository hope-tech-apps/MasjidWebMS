<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;

/**
 * Staff changing what is on an order (PATCH .../orders/{order_id}/items).
 *
 * `items` is the FULL set of lines after the edit, the same shape the customer's
 * own edit takes: a line left out is removed, and a quantity of 0 removes one
 * that is still listed.
 *
 * No price is accepted and no payment field is: the server reads every price from
 * the menu, and an edit never moves an order's payment state. A paid order whose
 * total changes carries a balance instead, which the board shows and staff settle
 * with the customer — nothing here marks money as taken.
 */
class EditMealOrderItemsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:100',
            'items.*.meal_menu_item_id' => 'required|integer|min:1',
            // 0 removes a line. An order with nothing left on it is refused by
            // the controller: cancelling an order is its own action.
            'items.*.quantity' => 'required|integer|min:0|max:99',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'An order must keep at least one plate. Cancel the order instead.',
        ];
    }
}
