<?php

namespace App\Http\Requests\Admin\Gallery;

use App\Http\Requests\BaseFormRequest;

class StoreGalleryImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
        ];
    }
}
