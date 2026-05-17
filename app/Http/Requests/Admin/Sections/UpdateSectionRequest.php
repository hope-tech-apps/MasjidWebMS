<?php

namespace App\Http\Requests\Admin\Sections;

use App\Http\Requests\BaseFormRequest;

class UpdateSectionRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('content'))) {
            $merge['content'] = json_decode($this->input('content'), true);
        }
        if (is_string($this->input('settings'))) {
            $merge['settings'] = json_decode($this->input('settings'), true);
        }
        if ($this->has('is_active')) {
            $merge['is_active'] = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $rules = [
            'section_type' => 'sometimes|required|string',
            'title' => 'nullable|string|max:255',
            'content' => [
                'sometimes',
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    if ($error = $this->findBase64ImageInContent($value)) {
                        $fail($error);
                    }
                },
            ],
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ];

        foreach ($this->allFiles() as $fieldName => $_) {
            $rules[$fieldName] = 'nullable|file|mimes:jpeg,png,jpg,gif,webp,webp|max:25600';
        }

        return $rules;
    }

    private function findBase64ImageInContent(?array $content): ?string
    {
        if (!is_array($content)) {
            return null;
        }
        foreach ($content as $value) {
            if (is_array($value)) {
                if ($error = $this->findBase64ImageInContent($value)) {
                    return $error;
                }
            } elseif (is_string($value) && preg_match('/^data:image\/[a-zA-Z]+;base64,/', $value)) {
                return 'Images must be uploaded as files, not base64 encoded strings. Please use the file upload feature.';
            }
        }
        return null;
    }
}
