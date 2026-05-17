<?php

namespace App\Http\Requests\Admin\Services;

use App\Http\Requests\BaseFormRequest;

class StoreServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'description' => 'required|string',
            'text' => 'required|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'icon' => 'required|image|mimes:png,ico,webp|max:25600',
        ];
    }
}
