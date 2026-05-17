<?php

namespace App\Http\Requests\Admin\MasjidDetails;

use App\Http\Requests\BaseFormRequest;

class UpdateMasjidDetailsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'logo' => 'image|mimes:jpeg,png,jpg,gif,webp|max:25600',
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
}
