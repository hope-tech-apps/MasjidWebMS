<?php

namespace App\Http\Requests\Admin\Sections;

use App\Http\Requests\BaseFormRequest;

class StoreSectionRequest extends BaseFormRequest
{
    /**
     * Section editors submit FormData (because they upload image files alongside JSON
     * content). The `content` and `settings` fields arrive as JSON-encoded strings —
     * parse them into arrays here so the rules() see the real shape.
     */
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
            'section_type' => 'required|string',
            'title' => 'nullable|string|max:255',
            'content' => [
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

        // Every uploaded file gets a corresponding nullable-image validation rule.
        foreach ($this->allFiles() as $fieldName => $_) {
            $rules[$fieldName] = 'nullable|file|mimes:jpeg,png,jpg,gif,webp,webp|max:25600';
        }

        return $rules;
    }

    /**
     * Recursively scan content for base64-encoded images; section content must reference
     * uploaded files by URL, not embed data: URIs.
     */
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
