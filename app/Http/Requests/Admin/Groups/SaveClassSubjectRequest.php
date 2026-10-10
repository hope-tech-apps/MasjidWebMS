<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Models\ClassSubject;
use App\Support\SubjectKey;
use Illuminate\Validation\Rule;

class SaveClassSubjectRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) $this->merge(['name' => SubjectKey::clean($this->input('name'))]);
    }

    public function messages(): array
    {
        return ['guide_subjects.*' => "Choose a subject from this school's curriculum."];
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'required', 'string', 'max:64'],
            'guide_subject' => ['sometimes', 'nullable', 'string', 'max:64'],
            'guide_subjects' => \App\Support\ClassSubjectMode::workEnabled($this->route('masjid_id')) ? ['sometimes', 'array', 'list'] : ['prohibited'],
            'guide_subjects.*' => ['required', 'string', 'max:64', 'distinct:strict'],
            'attach_saved_work' => [$this->isMethod('post') ? 'sometimes' : 'prohibited', 'boolean'],
            'tool' => ['sometimes', 'nullable', Rule::in(ClassSubject::TOOLS)],
        ];
    }
}
