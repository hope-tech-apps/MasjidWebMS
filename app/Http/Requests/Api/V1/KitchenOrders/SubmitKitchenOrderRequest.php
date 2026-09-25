<?php

namespace App\Http\Requests\Api\V1\KitchenOrders;

use App\Http\Requests\BaseFormRequest;
use App\Support\PaymentMethods;
use Illuminate\Validation\Rule;

/**
 * The request boundary for the PUBLIC kitchen order endpoint
 * (KitchenOrdersController::store).
 *
 * Shape only, as for the lunch order (SubmitLunchOrderRequest): whether an item is
 * on the catalogue and what it costs, whether the pickup respects the lead time,
 * and whether the organisation accepts the payment method are all decided in the
 * controller from the database. A request body never prices an order, never
 * decides the earliest pickup, and never switches a payment method on.
 *
 * `pickup_at` is the organisation's wall clock as a datetime-local input submits
 * it ("2026-10-03T14:00"), or an absolute instant with its own offset; MasjidTime
 * reads both. Nothing here has a boolean, so the form-encoding trap in
 * .claude/rules/shipping.md has nothing to bite.
 */
class SubmitKitchenOrderRequest extends BaseFormRequest
{
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
            'pickup_at' => 'required|string|max:40',
            'payment_method' => ['required', 'string', Rule::in(PaymentMethods::KEYS)],
            // Honeypot — real submitters leave it empty; checked in the controller.
            'website' => 'nullable|string|max:255',
        ];
    }
}
