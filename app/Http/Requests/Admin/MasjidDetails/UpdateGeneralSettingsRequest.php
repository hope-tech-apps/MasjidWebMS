<?php

namespace App\Http\Requests\Admin\MasjidDetails;

use App\Http\Requests\BaseFormRequest;

class UpdateGeneralSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // `extensions` pins each file's NAME to the kinds `mimes` holds its BYTES to: the
            // media library keeps the client's file name on the public disk, where `x.html`
            // would be served as a page (Concerns\ValidatesVideoSection::sectionUploadRules).
            // `bail` stops at the first failure, so a file that is not an image is not also
            // told to rename it.
            'header_logo' => 'bail|nullable|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'footer_logo' => 'bail|nullable|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            // The photo behind the app's screen headers (Masjid::app_header_image()): the same
            // kinds and limit as the logos beside it.
            'app_header_image' => 'bail|nullable|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'copyright_text' => 'nullable|string',
            'app_store_link' => 'nullable|url',
            'google_play_link' => 'nullable|url',
            // What the apps will open: both refuse anything but an absolute https address with
            // no sign-in details in it (iOS HomeLinks, Android ServerLink), silently, so an
            // address they would drop is refused here where the office can see why. 255 is the
            // column: MySQL refuses a longer one, SQLite in the tests would not.
            'privacy_policy_url' => ['nullable', 'string', 'max:255', 'url:https', 'not_regex:/^[a-z]+:\/\/[^\/?#]*@/i'],
            'google_maps_key' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'header_logo.extensions' => 'The header logo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
            'footer_logo.extensions' => 'The footer logo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
            'privacy_policy_url.url' => 'The privacy policy link must be a full address that starts with https://.',
            'privacy_policy_url.not_regex' => 'The privacy policy link must not contain a user name or password.',
            'app_header_image.extensions' => 'The app header photo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
