<?php

namespace App\Http\Requests\Admin\Hadiths;

use App\Enums\HadithStrength;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateHadithRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $hadithId = $this->route('hadith_id');

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
            'show_date' => 'required|date|unique:hadiths,show_date,' . $hadithId,
        ];
    }
}
