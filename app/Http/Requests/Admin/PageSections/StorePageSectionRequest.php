<?php

namespace App\Http\Requests\Admin\PageSections;

use App\Enums\SectionType;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ValidatesEmbedContent;
use App\Http\Requests\Concerns\ValidatesVideoSection;
use Illuminate\Validation\Rules\Enum;

class StorePageSectionRequest extends BaseFormRequest
{
    use ValidatesEmbedContent;
    use ValidatesVideoSection;

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('content'))) {
            $merge['content'] = json_decode($this->input('content'), true);
        }
        if (is_string($this->input('settings'))) {
            $merge['settings'] = json_decode($this->input('settings'), true);
        }
        // platforms arrives as a JSON-encoded string in the multipart FormData.
        if (is_string($this->input('platforms'))) {
            $decoded = json_decode($this->input('platforms'), true);
            if (is_array($decoded)) {
                $merge['platforms'] = $decoded;
            }
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
            'section_type' => ['required', new Enum(SectionType::class)],
            'title' => 'nullable|string|max:255',
            'content' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    if ($error = $this->findBase64ImageInContent($value)) {
                        $fail($error);
                    }
                    // An embed's provider+url are allowlisted server-side; see the trait.
                    $this->validateEmbedContent($value, $fail);
                    // A video's layout and width are one of the renderer's words; see the trait.
                    $this->validateVideoContent($value, $fail);
                },
            ],
            'order' => 'nullable|integer',
            // Per-placement platform visibility. Null/absent => both (web+mobile).
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|in:web,mobile',
            'settings' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ];

        // The image rule for every uploaded file, except an MP4 in a video section's
        // `video_url` (ValidatesVideoSection).
        return array_replace($rules, $this->sectionUploadRules());
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
