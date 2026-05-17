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

        $imageRule = $aboutExists ? 'image' : 'required|image';
        $iconMimes = 'mimes:png,ico,webp|max:25600';
        $imageMimes = 'mimes:jpeg,png,jpg,gif,webp|max:25600';

        return [
            'about' => 'required|string|max:5000',
            'mission' => 'required|string|max:5000',
            'vision' => 'required|string|max:5000',
            'about_image' => $imageRule . '|' . $imageMimes,
            'mission_icon' => $imageRule . '|' . $iconMimes,
            'vision_icon' => $imageRule . '|' . $iconMimes,
        ];
    }
}
