<?php

namespace App\Http\Requests\Admin\Services;

use App\Http\Requests\BaseFormRequest;

class StoreServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'summary' => 'nullable|string',
            'description' => 'required|string',
            'text' => 'required|string',
            // `extensions` pins each file's NAME to what a real file of that field can be
            // called: the media library keeps the client's file name on the public disk, where
            // `x.html` would be served as a page
            // (Concerns\ValidatesVideoSection::sectionUploadRules). `bail` stops at the first
            // failure, so a file that is not an image is not also told to rename it.
            //
            // The icon's name list is shorter than its `mimes` on purpose. `mimes` names
            // `ico`, but `image` beside it refuses ICO bytes first and always has, so no real
            // file can be an icon here under that name: the only bytes that pass are a PNG's
            // or a WebP's, and those are the names the sentence asks for.
            'image' => 'bail|required|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'icon' => 'bail|required|image|mimes:png,ico,webp|extensions:png,webp|max:25600',
        ];
    }

    public function messages(): array
    {
        return [
            'image.extensions' => 'The service image\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
            'icon.extensions' => 'The service icon\'s file name must end in .png or .webp. Rename the file and upload it again.',
        ];
    }
}
