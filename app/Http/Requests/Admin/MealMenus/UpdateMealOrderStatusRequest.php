<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;
use App\Models\MealOrder;
use Illuminate\Validation\Rule;

/**
 * Staff move an order along the fulfilment track. `pending` is deliberately not
 * a target — an order starts there and is never sent back.
 */
class UpdateMealOrderStatusRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                MealOrder::STATUS_CONFIRMED,
                MealOrder::STATUS_READY,
                MealOrder::STATUS_PICKED_UP,
                MealOrder::STATUS_CANCELLED,
            ])],
        ];
    }
}
