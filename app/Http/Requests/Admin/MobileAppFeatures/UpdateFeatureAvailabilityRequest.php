<?php

namespace App\Http\Requests\Admin\MobileAppFeatures;

use App\Http\Requests\BaseFormRequest;

class UpdateFeatureAvailabilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'is_available' => 'required|boolean',
        ];
    }
}
