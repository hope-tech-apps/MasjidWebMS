<?php

namespace App\Http\Requests\Admin\Pages;

use App\Http\Requests\BaseFormRequest;

class StorePageRequest extends BaseFormRequest
{
    /**
     * Coerce the form-data boolean strings ("true"/"false"/"1"/"0") into real booleans
     * so validation and downstream creation see consistent types.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => filter_var($this->input('is_active', false), FILTER_VALIDATE_BOOLEAN),
            'show_in_menu' => filter_var($this->input('show_in_menu', false), FILTER_VALIDATE_BOOLEAN),
            'show_as_button' => filter_var($this->input('show_as_button', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function rules(): array
    {
        $masjidId = $this->route('masjid_id');

        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                "unique:pages,slug,NULL,id,masjid_id,{$masjidId},deleted_at,NULL",
            ],
            'title' => 'required|string|max:255',
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
