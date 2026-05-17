<?php

namespace App\Http\Requests\Admin\Pages;

use App\Http\Requests\BaseFormRequest;

class StorePageRequest extends BaseFormRequest
{
    /**
     * Coerce the form-data boolean strings ("true"/"false"/"1"/"0") into real booleans
     * so validation and downstream creation see consistent types.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => filter_var($this->input('is_active', false), FILTER_VALIDATE_BOOLEAN),
            'show_in_menu' => filter_var($this->input('show_in_menu', false), FILTER_VALIDATE_BOOLEAN),
            'show_as_button' => filter_var($this->input('show_as_button', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function rules(): array
    {
        $masjidId = $this->route('masjid_id');

        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                "unique:pages,slug,NULL,id,masjid_id,{$masjidId},deleted_at,NULL",
            ],
            'title' => 'required|string|max:255',
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
