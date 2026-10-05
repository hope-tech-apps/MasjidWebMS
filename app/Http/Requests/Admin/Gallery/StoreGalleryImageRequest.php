<?php

namespace App\Http\Requests\Admin\Gallery;

use App\Http\Requests\BaseFormRequest;

class StoreGalleryImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Accept either a single `image` (legacy) or an array of `images` (multi-upload).
            // At least one image must be present.
            //
            // `extensions` pins each file's NAME to the kinds `mimes` holds its BYTES to: the
            // media library keeps the client's file name on the public disk, where `x.html`
            // would be served as a page (Concerns\ValidatesVideoSection::sectionUploadRules).
            // `bail` stops at the first failure, so a file that is not an image is not also
            // told to rename it. One refused file refuses the whole request: nothing is stored.
            'image' => 'bail|required_without:images|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'images' => 'required_without:image|array',
            'images.*' => 'bail|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
        ];
    }

    public function messages(): array
    {
        $name = 'A gallery photo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.';

        return [
            'image.extensions' => $name,
            'images.*.extensions' => $name,
        ];
    }
}
