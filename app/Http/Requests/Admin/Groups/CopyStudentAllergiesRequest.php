<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

class CopyStudentAllergiesRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('dry_run')) $this->merge(['dry_run' => filter_var($this->input('dry_run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
    }

    public function rules(): array
    {
        return ['form_id' => 'required|integer|min:1', 'field' => 'required|string|max:100', 'dry_run' => 'sometimes|boolean'];
    }
}
