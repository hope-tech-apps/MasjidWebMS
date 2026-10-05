<?php

namespace App\Http\Requests\Admin\Notifications;

use App\Http\Requests\BaseFormRequest;

class SendNotificationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'message' => 'required|string',
            // `image` reads the file's BYTES and there is no `mimes` list here, so
            // `extensions` pins the file's NAME to what `image` itself admits (jpeg, png,
            // gif, bmp, webp): the media library keeps the client's file name on the public
            // disk, where `x.html` would be served as a page
            // (Concerns\ValidatesVideoSection::sectionUploadRules). `bail` stops at the first
            // failure, so a file that is not an image is not also told to rename it.
            'image' => 'bail|nullable|image|extensions:jpeg,jpg,png,gif,bmp,webp|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'image.extensions' => 'The notification image\'s file name must end in .jpg, .jpeg, .png, .gif, .bmp or .webp. Rename the file and upload it again.',
        ];
    }
}
