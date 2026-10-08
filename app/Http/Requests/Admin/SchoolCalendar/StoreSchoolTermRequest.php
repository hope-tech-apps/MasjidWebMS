<?php

namespace App\Http\Requests\Admin\SchoolCalendar;

use App\Http\Requests\BaseFormRequest;

/** Complete term writes; bounds, ordering and overlap repeat under the year lock. */
class StoreSchoolTermRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        if (! \App\Support\SchoolCalendarConfigurationRules::enabled($this)) abort(404);
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required','string','max:80'],
            'starts_on' => ['required','date_format:Y-m-d'],
            'ends_on' => ['required','date_format:Y-m-d','after_or_equal:starts_on'],
            'position' => ['required','integer','between:1,255'],
        ];
    }

    public function messages(): array
    {
        return ['ends_on.after_or_equal' => 'A term cannot end before it starts.'];
    }
}
