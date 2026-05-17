<?php

namespace App\Http\Requests\Admin\MasjidDetails;

use App\Http\Requests\BaseFormRequest;

class UpdateGeneralSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'header_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'footer_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'copyright_text' => 'nullable|string',
            'app_store_link' => 'nullable|url',
            'google_play_link' => 'nullable|url',
            'google_maps_key' => 'nullable|string',
        ];
    }
}
