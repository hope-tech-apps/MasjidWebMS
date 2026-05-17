<?php

namespace App\Http\Requests\Api\V1\ContactUs;

use App\Http\Requests\BaseFormRequest;

class StoreV1ContactMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'device_id' => 'required|string',
            'email' => 'required|email',
            'name' => 'required|string',
            'phone' => 'nullable|string|regex:/^\+\d+$/',
            'reason_text' => 'required|string',
            'message' => 'required|string',
        ];
    }
}
