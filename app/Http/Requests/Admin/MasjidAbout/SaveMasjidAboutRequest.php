<?php

namespace App\Http\Requests\Admin\MasjidAbout;

use App\Http\Requests\BaseFormRequest;
use App\Models\Masjid;

class SaveMasjidAboutRequest extends BaseFormRequest
{
    /**
     * Image fields are required only when no MasjidAbout record exists yet for this masjid.
     * Once one exists, image fields become optional (just edit text without re-uploading).
     */
    public function rules(): array
    {
        $masjidId = $this->route('masjid_id');
        $masjid = Masjid::find($masjidId);
        $aboutExists = $masjid && $masjid->masjidAbout()->exists();

        // `bail` stops at the first failure, so a file that is not an image is not also
        // told to rename it.
        $presence = $aboutExists ? 'bail' : 'bail|required';

        // Each field's rule is written whole, so `image`, `mimes` and `extensions` can be
        // read together (UploadFileNameCoverageTest reads them that way). `extensions`
        // pins the file's NAME to what a real file of that field can be called: the media
        // library keeps the client's file name on the public disk, where `x.html` would be
        // served as a page (Concerns\ValidatesVideoSection::sectionUploadRules). An icon's
        // `mimes` names `ico`, but `image` beside it refuses ICO bytes first, so its name
        // list is `png,webp` only (StoreServiceRequest says the same of its icon).
        return [
            'about' => 'required|string|max:5000',
            'mission' => 'required|string|max:5000',
            'vision' => 'required|string|max:5000',
            'about_image' => $presence . '|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'mission_icon' => $presence . '|image|mimes:png,ico,webp|extensions:png,webp|max:25600',
            'vision_icon' => $presence . '|image|mimes:png,ico,webp|extensions:png,webp|max:25600',
        ];
    }

    public function messages(): array
    {
        return [
            'about_image.extensions' => 'The About Us image\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
            'mission_icon.extensions' => 'The mission icon\'s file name must end in .png or .webp. Rename the file and upload it again.',
            'vision_icon.extensions' => 'The vision icon\'s file name must end in .png or .webp. Rename the file and upload it again.',
        ];
    }
}
