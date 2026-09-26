<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;
use App\Support\PaymentMethods;
use Illuminate\Validation\Rule;

/**
 * An order taken by staff on the lunch board (POST .../menus/{menu_id}/orders).
 *
 * Prices are never accepted: the server reads them from the menu. Nor is any
 * "paid" field: an order is charged through Stripe like a public order, or (a
 * kitchen order) left unpaid for Mark paid, and only those mark it paid. There
 * is deliberately no "paid" flag — a volunteer once marked their own order paid
 * with no money changing hands. `payment_method` says how a kitchen order WILL
 * be paid, never that it was.
 *
 * The two optional amounts match the public form exactly: an extra on top of
 * the food, in bounded integer minor units, and a yes/no to cover the card
 * fee, whose amount the server computes (LunchOrderExtras).
 */
class StoreStaffMealOrderRequest extends BaseFormRequest
{
    /**
     * Laravel's `boolean` rule rejects the strings "true" and "false", which a
     * form-encoded client sends. Coerce first; genuine nonsense becomes null
     * and still fails validation rather than reading as "no".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('cover_fees')) {
            $this->merge([
                'cover_fees' => filter_var($this->input('cover_fees'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
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
            // Optional extra, integer minor units: bounded so a crafted body can
            // never open a five-figure Checkout Session on the account.
            'donation_minor' => 'nullable|integer|min:0|max:' . \App\Models\MealOrder::MAX_DONATION_MINOR,
            // Yes/no only; the surcharge is computed on the server.
            'cover_fees' => 'sometimes|boolean',
            // A kitchen (catalogue) order's pickup, on the organisation's wall
            // clock as a datetime-local input sends it. Required there and ignored
            // on a Friday menu (MealOrdersController::store). The office taking an
            // order by phone is not held to the public lead time: it is the office
            // deciding it can make it.
            'pickup_at' => 'nullable|string|max:40',
            // How a kitchen order will be paid, from the organisation's accepted
            // methods (checked against its list by the controller). Card opens a
            // Stripe page; any other is settled later with Mark paid. Ignored on a
            // Friday menu, where an order taken here is always a card order.
            'payment_method' => ['nullable', 'string', Rule::in(PaymentMethods::KEYS)],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'customer_name.required' => "Enter the customer's name.",
            'donation_minor.max' => 'The extra can be at most $' . number_format(\App\Models\MealOrder::MAX_DONATION_MINOR / 100) . '.',
            'donation_minor.integer' => 'Enter the extra in whole cents.',
        ];
    }
}
