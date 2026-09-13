<?php

namespace App\Http\Requests\Admin\SchoolCalendar;

use App\Http\Requests\BaseFormRequest;

/**
 * Only the reason of a no-school day can change. A different day is a
 * different closure, and must pass the register-marks check a new one does.
 */
class UpdateSchoolClosureRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:160'],
        ];
    }
}
