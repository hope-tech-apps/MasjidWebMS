<?php

namespace App\Http\Requests\Api\V1\LunchOrders;

use App\Http\Requests\BaseFormRequest;

/**
 * The request boundary for a customer changing their own order
 * (PATCH /api/v1/lunch-orders/{uuid}).
 *
 * `items` is the FULL set of lines the order should have after the edit, not a
 * change list: a line left out is removed, and a quantity of 0 removes one that
 * is still listed. Sending the whole basket is what the order page already holds,
 * and it means two edits crossing cannot add up to a basket nobody chose.
 *
 * Only shape is checked here. Whether an item is on this order's menu, whether it
 * is still available, the kitchen's cap, and what any of it costs are decided in
 * the controller from the database — a request body never prices an order.
 */
class EditLunchOrderRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:100',
            'items.*.meal_menu_item_id' => 'required|integer|min:1',
            // 0 is allowed here and nowhere else in this module: it is how a
            // customer removes a line they no longer want.
            'items.*.quantity' => 'required|integer|min:0|max:99',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Your order must keep at least one plate.',
        ];
    }
}
