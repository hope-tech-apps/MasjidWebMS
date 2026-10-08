<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

class ReorderClassSubjectsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['subject_ids' => ['required', 'array', 'max:65536'], 'subject_ids.*' => ['required', 'integer', 'distinct']];
    }
}
