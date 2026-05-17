<?php

namespace App\Http\Requests\Mobile\Users;

use App\Http\Requests\BaseFormRequest;

class GetMasjidDetailsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'device_id' => 'required|exists:mobile_app_users,device_id',
        ];
    }
}
