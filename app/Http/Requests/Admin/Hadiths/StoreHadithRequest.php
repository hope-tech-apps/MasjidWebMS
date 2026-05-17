<?php

namespace App\Http\Requests\Admin\Hadiths;

use App\Enums\HadithStrength;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreHadithRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'isnad' => 'required|string',
            'matn' => 'required|string',
            'description' => 'required|string',
            'strength' => ['required', 'string', Rule::in(HadithStrength::getValues())],
            'muhaddith_ar' => 'required|string',
            'muhaddith_en' => 'required|string',
            'references' => 'required|array',
            'references.*' => 'array',
            'show_date' => [
                'required',
                'date',
                'unique:hadiths,show_date',
                function ($attribute, $value, $fail) {
                    $today = date('Y-m-d');
                    if ($value <= $today) {
                        $fail('The ' . $attribute . ' must be a date after today.');
                    }
                },
            ],
        ];
    }
}
