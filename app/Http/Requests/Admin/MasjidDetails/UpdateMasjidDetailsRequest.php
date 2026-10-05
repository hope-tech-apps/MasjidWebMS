<?php

namespace App\Http\Requests\Admin\MasjidDetails;

use App\Http\Requests\BaseFormRequest;

class UpdateMasjidDetailsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // `extensions` pins the file's NAME to the kinds `mimes` holds its BYTES to: the
            // media library keeps the client's file name on the public disk, where `x.html`
            // would be served as a page (Concerns\ValidatesVideoSection::sectionUploadRules).
            // `bail` stops at the first failure, so a file that is not an image is not also
            // told to rename it.
            'logo' => 'bail|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'name' => 'required|string',
            'website_link' => 'nullable|string',
            'email' => 'required|string|email',
            'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
            'timezone' => 'required|string|timezone',
            'latitude' => 'required|numeric|min:-90|max:90',
            'longitude' => 'required|numeric|min:-180|max:180',
            'facebook_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?facebook\.com\/[A-Za-z0-9_.-]+\/?$/',
            'youtube_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?(youtube\.com\/.*)$/',
            'instagram_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?instagram\.com\/[A-Za-z0-9_.-]+\/?$/',
            'whatsapp_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?wa\.me\/[0-9]+\/?$/',
            'whatsapp_number' => 'nullable|string|regex:/^\+?[0-9 ]+$/',
        ];
    }

    public function messages(): array
    {
        return [
            'logo.extensions' => 'The logo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
