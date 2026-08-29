<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;
use App\Models\MealMenu;
use Illuminate\Validation\Rule;

class UpdateMealMenuRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:120',
            'title_ar' => 'nullable|string|max:120',
            'service_date' => 'sometimes|date',
            'status' => ['sometimes', Rule::in(MealMenu::STATUSES)],
            'ordering_opens_at' => 'nullable|date',
            'ordering_closes_at' => 'nullable|date',
            'pickup_instructions' => 'nullable|string|max:255',
            'pickup_instructions_ar' => 'nullable|string|max:255',
            'flyer_image_url' => 'nullable|url|max:500',
            'notes' => 'nullable|string|max:2000',
            'allow_online_payment' => 'sometimes|boolean',
            'allow_pay_at_pickup' => 'sometimes|boolean',
            'currency' => 'sometimes|string|size:3',
        ];
    }
}
