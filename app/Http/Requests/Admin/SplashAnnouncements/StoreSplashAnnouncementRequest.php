<?php

namespace App\Http\Requests\Admin\SplashAnnouncements;

use App\Http\Requests\BaseFormRequest;

/**
 * Per the security sweep:
 *  - image `mimes:` allowlist does NOT include svg (stored XSS via inline JS).
 *  - cta_url is validated as a URL so admins can't slip a `javascript:` payload through.
 */
class StoreSplashAnnouncementRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:5000',

            // Optional CTA — either both fields are present, or neither.
            'cta_label' => 'nullable|string|max:120|required_with:cta_url',
            'cta_url' => 'nullable|url:http,https|max:2048|required_with:cta_label',

            // Schedule. ISO 8601 with timezone is what the Vue admin's datetime-local
            // emits via new Date().toISOString(), so we accept that liberally.
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',

            'priority' => 'nullable|integer|min:0|max:100',
            'is_active' => 'nullable|boolean',

            // Keeping SVG off the `mimes` list is half of it: `mimes` reads the BYTES, and the
            // media library keeps the client's file NAME on the public disk, where image
            // bytes named `x.html` would be served as a page. `extensions` pins the name to
            // the same kinds (Concerns\ValidatesVideoSection::sectionUploadRules). `bail`
            // stops at the first failure, so a file that is not an image is not also told to
            // rename it.
            'image' => 'bail|required|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
        ];
    }

    public function messages(): array
    {
        return [
            'image.extensions' => 'The splash image\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
