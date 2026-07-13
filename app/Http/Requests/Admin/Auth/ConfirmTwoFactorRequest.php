<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;

class ConfirmTwoFactorRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // 6-digit TOTP code from the authenticator app.
            'code' => 'required|string',
        ];
    }
}
