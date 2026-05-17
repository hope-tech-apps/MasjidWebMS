<?php

namespace App\Http\Requests\Admin\Pages;

use App\Http\Requests\BaseFormRequest;

class UpdatePageRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = [];
        if ($this->has('is_active')) {
            $payload['is_active'] = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($this->has('show_in_menu')) {
            $payload['show_in_menu'] = filter_var($this->input('show_in_menu'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($this->has('show_as_button')) {
            $payload['show_as_button'] = filter_var($this->input('show_as_button'), FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($payload)) {
            $this->merge($payload);
        }
    }

    public function rules(): array
    {
        $masjidId = $this->route('masjid_id');
        $pageId = $this->route('page_id');

        return [
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                "unique:pages,slug,{$pageId},id,masjid_id,{$masjidId},deleted_at,NULL",
            ],
            'title' => 'sometimes|string|max:255',
            'page_title' => 'nullable|string|max:255',
            'page_title_background_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'is_active' => 'nullable|boolean',
            'order' => 'nullable|integer',
            'show_in_menu' => 'nullable|boolean',
            'show_as_button' => 'nullable|boolean',
            'meta_description' => 'nullable|string',
        ];
    }
}
