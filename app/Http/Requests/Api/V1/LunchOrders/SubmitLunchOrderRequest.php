<?php

namespace App\Http\Requests\Api\V1\LunchOrders;

use App\Http\Requests\BaseFormRequest;
use App\Models\MealOrder;
use Illuminate\Validation\Rule;

/**
 * The request boundary for the PUBLIC Jummah-lunch order endpoint.
 *
 * Extends BaseFormRequest so a rejection returns the {status:'failed'} 422 field
 * bag this app's public surface uses, rather than a raw ValidationException the
 * JSON renderer would turn into a 500.
 *
 * Only shape is checked here — that the item ids and quantities are well-formed,
 * a payment method is one of the two the model allows, and contact details fit.
 * Whether an item is actually on the open menu, and what it costs, are decided
 * in the controller from the database; a request body never prices an order.
 */
class SubmitLunchOrderRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'menu_uuid' => 'required|string|max:64',
            'items' => 'required|array|min:1|max:100',
            'items.*.item_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'required|string|max:32',
            'customer_email' => 'nullable|email|max:190',
            'customer_notes' => 'nullable|string|max:500',
            'payment_method' => ['required', 'string', Rule::in(MealOrder::METHODS)],
            // Honeypot — real submitters leave it empty; checked in the controller.
            'website' => 'nullable|string|max:255',
        ];
    }
}
