<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

class RecordStudentAllergiesRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('allergies'))) $this->merge(['allergies' => preg_replace('/^\s+|\s+$/u', '', $this->input('allergies'))]);
    }

    public function rules(): array
    {
        return ['allergies' => 'present|nullable|string|max:500'];
    }
}
