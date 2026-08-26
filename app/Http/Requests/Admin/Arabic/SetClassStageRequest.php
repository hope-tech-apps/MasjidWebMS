<?php

namespace App\Http\Requests\Admin\Arabic;

use App\Http\Requests\BaseFormRequest;
use App\Support\Arabic\ArabicCurriculum;
use Illuminate\Validation\Rule;

class SetClassStageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(ArabicCurriculum::STAGES)],
        ];
    }
}
