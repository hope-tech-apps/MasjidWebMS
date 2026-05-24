<?php

namespace App\Http\Requests\Admin\SplashAnnouncements;

use App\Http\Requests\BaseFormRequest;

/**
 * Update is identical to Store except every field is `sometimes` — admins
 * can patch one field (e.g. just is_active) without re-posting the whole form.
 * Image is still rejected if its mime is SVG (security sweep).
 */
class UpdateSplashAnnouncementRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|nullable|string|max:5000',

            'cta_label' => 'sometimes|nullable|string|max:120',
            'cta_url' => 'sometimes|nullable|url:http,https|max:2048',

            'starts_at' => 'sometimes|required|date',
            'ends_at' => 'sometimes|required|date|after:starts_at',

            'priority' => 'sometimes|nullable|integer|min:0|max:100',
            'is_active' => 'sometimes|nullable|boolean',

            'image' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
        ];
    }
}
