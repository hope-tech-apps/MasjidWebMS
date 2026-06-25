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
            'image' => 'required_without:images|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'images' => 'required_without:image|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:25600',
        ];
    }
}
