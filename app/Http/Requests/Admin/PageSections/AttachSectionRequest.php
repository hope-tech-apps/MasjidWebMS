<?php

namespace App\Http\Requests\Admin\PageSections;

use App\Http\Requests\BaseFormRequest;

class AttachSectionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'section_id' => 'required|integer|exists:sections,id',
            'order' => 'nullable|integer',
        ];
    }
}
