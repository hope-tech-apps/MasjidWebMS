<?php

namespace App\Http\Requests\Admin\Announcements;

use App\Http\Requests\BaseFormRequest;

class StoreAnnouncementRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'summary' => 'nullable|string',
            'details' => 'required|string',
            'text' => 'required|string',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            // `extensions` pins the file's NAME to the kinds `mimes` holds its BYTES to: the
            // media library keeps the client's file name on the public disk, where `x.html`
            // would be served as a page (Concerns\ValidatesVideoSection::sectionUploadRules).
            // `bail` stops at the first failure, so a file that is not an image is not also
            // told to rename it. The publish composer borrows these rules for its feed channel.
            'image' => 'bail|required|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
        ];
    }

    public function messages(): array
    {
        return [
            'image.extensions' => 'The announcement image\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
