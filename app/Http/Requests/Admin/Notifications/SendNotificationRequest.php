<?php

namespace App\Http\Requests\Admin\Notifications;

use App\Http\Requests\BaseFormRequest;

class SendNotificationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'message' => 'required|string',
            'image' => 'nullable|image|max:5120',
        ];
    }
}
