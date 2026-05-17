<?php

namespace App\Http\Requests\Admin\PrayerCalculation;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class SavePrayerCalculationSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(array_column(PrayerCalculationMethod::cases(), 'value'))],
            'madhab' => ['required', 'string', Rule::in(array_column(Madhab::cases(), 'value'))],
            'high_latitude_rule' => ['required', 'string', Rule::in(array_column(HighLatitudeRule::cases(), 'value'))],
        ];
    }
}
