<?php

namespace App\Http\Requests\Admin\Announcements;

use App\Http\Requests\BaseFormRequest;

class StoreAnnouncementRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'details' => 'required|string',
            'text' => 'required|string',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
        ];
    }
}
