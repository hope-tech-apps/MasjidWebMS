<?php

namespace App\Http\Requests\Admin\Services;

use App\Http\Requests\BaseFormRequest;

class UpdateServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'description' => 'required|string',
            'text' => 'required|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'icon' => 'nullable|image|mimes:png,gif,ico,icns,webp|max:25600',
        ];
    }
}
