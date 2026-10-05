<?php

namespace App\Http\Requests\Admin\Services;

use App\Http\Requests\BaseFormRequest;

class UpdateServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'summary' => 'nullable|string',
            'description' => 'required|string',
            'text' => 'required|string',
            // The name is pinned as well as the bytes, and `bail` stops at the first failure:
            // see StoreServiceRequest. Each `extensions` list mirrors the `mimes` list beside
            // it, and this icon rule has always named two kinds the create rule does not.
            'image' => 'bail|nullable|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'icon' => 'bail|nullable|image|mimes:png,gif,ico,icns,webp|extensions:png,gif,ico,icns,webp|max:25600',
        ];
    }

    public function messages(): array
    {
        return [
            'image.extensions' => 'The service image\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
            'icon.extensions' => 'The service icon\'s file name must end in .png, .gif, .ico, .icns or .webp. Rename the file and upload it again.',
        ];
    }
}
