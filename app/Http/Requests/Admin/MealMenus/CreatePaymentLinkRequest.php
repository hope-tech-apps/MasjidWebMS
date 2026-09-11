<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;
use App\Models\MealOrder;

/**
 * POST .../orders/{order_id}/payment-link. The optional extra and whether the
 * card fee is covered, the same two choices the Add-order form offers. Both
 * are optional: without them an open payment page is reused as it is. The fee
 * is a yes/no only; its amount is computed on the server (LunchOrderExtras).
 */
class CreatePaymentLinkRequest extends BaseFormRequest
{
    /** Laravel's boolean rule rejects "true"/"false", which a form-encoded client sends. */
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
            'donation_minor' => 'nullable|integer|min:0|max:' . MealOrder::MAX_DONATION_MINOR,
            'cover_fees' => 'sometimes|boolean',
        ];
    }
}
