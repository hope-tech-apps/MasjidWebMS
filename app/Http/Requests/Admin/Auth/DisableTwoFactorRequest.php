<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;

class DisableTwoFactorRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Disabling 2FA requires proving current possession of the device.
            'code' => 'required|string',
        ];
    }
}
