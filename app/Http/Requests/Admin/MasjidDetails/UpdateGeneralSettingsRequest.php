<?php

namespace App\Http\Requests\Admin\MasjidDetails;

use App\Http\Requests\BaseFormRequest;

class UpdateGeneralSettingsRequest extends BaseFormRequest
{
    /**
     * The shape both apps open: see the rule's comment in rules(). The same expression is in
     * the office's screen (GeneralSettingsView.vue); change them together.
     */
    public const PRIVACY_POLICY_URL_SHAPE = '/^https:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?([\/?#][^\s]*)?$/i';

    /**
     * The address as the apps should receive it: no surrounding spaces, and `https` in lower
     * case. A phone keyboard capitalises the first letter typed, and Android matches a link's
     * scheme to a browser by exact case. Only when the field was sent: an absent field stays
     * absent, which is how the save knows to leave the stored address alone.
     */
    protected function prepareForValidation(): void
    {
        $address = $this->input('privacy_policy_url');

        if (is_string($address)) {
            $this->merge([
                'privacy_policy_url' => preg_replace('/^https:\/\//i', 'https://', trim($address)),
            ]);
        }
    }

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
            // a host and no sign-in details in it (iOS HomeLinks, Android ServerLink), silently,
            // so an address they would drop is refused here where the office can see why. The
            // shape is narrower than `url` on purpose, because the two apps parse an address
            // with two different libraries: a plain host name in letters, digits, dots and
            // hyphens (an internationalised name is typed in its xn-- form: Android's parser
            // finds no host in raw Unicode), an optional port of up to five digits, then the
            // path. 255 is the column: MySQL refuses a longer one, SQLite in the tests would not.
            'privacy_policy_url' => ['nullable', 'string', 'max:255', 'ascii', 'url:https', 'regex:' . self::PRIVACY_POLICY_URL_SHAPE],
            'google_maps_key' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'header_logo.extensions' => 'The header logo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
            'footer_logo.extensions' => 'The footer logo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
            'privacy_policy_url.url' => 'The privacy policy link must be a full address that starts with https://.',
            'privacy_policy_url.ascii' => 'The privacy policy link must use plain letters and digits. Type an internationalised name in its xn-- form.',
            'privacy_policy_url.regex' => 'The privacy policy link must be a full address like https://www.example.org/privacy, with no user name or password in it.',
            'app_header_image.extensions' => 'The app header photo\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
