<?php

namespace App\Http\Requests\Admin\Arabic;

use App\Http\Requests\BaseFormRequest;
use App\Support\Arabic\ArabicCurriculum;
use Illuminate\Validation\Rule;

class MarkDrillRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Validated against the CLASS'S STAGE in the controller, not here:
            // a drill id alone cannot be judged without knowing what the room is
            // working on, and accepting one from a later stage would put a mark
            // in a cell the progress bar never counts.
            'drill_id' => ['required', 'string', 'max:40'],
            'status' => ['required', Rule::in(ArabicCurriculum::STATUSES)],
        ];
    }
}
