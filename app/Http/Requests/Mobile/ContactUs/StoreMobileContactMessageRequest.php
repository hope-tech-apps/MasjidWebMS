<?php

namespace App\Http\Requests\Mobile\ContactUs;

use App\Http\Requests\BaseFormRequest;

class StoreMobileContactMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'device_id' => 'required|exists:mobile_app_users,device_id',
            'email' => 'required|email',
            'name' => 'required|string',
            'phone' => 'nullable|string|regex:/^\+\d+$/',
            'reason_text' => 'required|string',
            'message' => 'required|string',
        ];
    }
}
