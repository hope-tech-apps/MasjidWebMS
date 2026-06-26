<?php

namespace App\Http\Requests\Admin\PageSections;

use App\Http\Requests\BaseFormRequest;

class AttachSectionRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        // Attach may be sent as JSON or FormData; normalize a JSON-encoded
        // platforms string into an array so the rules() see the real shape.
        if (is_string($this->input('platforms'))) {
            $decoded = json_decode($this->input('platforms'), true);
            if (is_array($decoded)) {
                $this->merge(['platforms' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'section_id' => 'required|integer|exists:sections,id',
            'order' => 'nullable|integer',
            // Per-placement platform visibility. Null/absent => both (web+mobile).
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|in:web,mobile',
        ];
    }
}
