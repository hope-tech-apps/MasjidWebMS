<?php

namespace App\Http\Requests\Admin\Announcements;

use App\Http\Requests\BaseFormRequest;

class UpdateAnnouncementRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'summary' => 'nullable|string',
            'details' => 'required|string',
            'text' => 'required|string',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
        ];
    }
}
