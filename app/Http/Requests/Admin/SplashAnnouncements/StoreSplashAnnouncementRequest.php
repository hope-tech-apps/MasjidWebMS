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

            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
        ];
    }
}
