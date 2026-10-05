<?php

namespace App\Http\Requests\Admin\Pages;

use App\Http\Requests\BaseFormRequest;

class UpdatePageRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = [];
        if ($this->has('is_active')) {
            $payload['is_active'] = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($this->has('show_in_menu')) {
            $payload['show_in_menu'] = filter_var($this->input('show_in_menu'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($this->has('show_as_button')) {
            $payload['show_as_button'] = filter_var($this->input('show_as_button'), FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($payload)) {
            $this->merge($payload);
        }
    }

    public function rules(): array
    {
        $masjidId = $this->route('masjid_id');
        $pageId = $this->route('page_id');

        return [
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                "unique:pages,slug,{$pageId},id,masjid_id,{$masjidId},deleted_at,NULL",
            ],
            'title' => 'sometimes|string|max:255',
            'page_title' => 'nullable|string|max:255',
            // `image` and `mimes` read the file's BYTES; `extensions` pins its NAME to the same
            // list. The media library keeps the client's file name on the public disk and the
            // web server picks the Content-Type from the extension, so image bytes uploaded as
            // `x.html` would be served as a page on this app's own origin. The same pair every
            // section upload has (Concerns\ValidatesVideoSection::sectionUploadRules). `bail`
            // stops at the first failure, so a file that is not an image is not also told to
            // rename it.
            'page_title_background_image' => 'bail|nullable|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'is_active' => 'nullable|boolean',
            'order' => 'nullable|integer',
            'show_in_menu' => 'nullable|boolean',
            'show_as_button' => 'nullable|boolean',
            'meta_description' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'page_title_background_image.extensions' => 'The background image\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
