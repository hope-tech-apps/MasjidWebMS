<?php

namespace App\Http\Requests\Mobile\Users;

use App\Http\Requests\BaseFormRequest;

class StoreMobileAppUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'masjid_id' => 'required|exists:masjids,id',
            'device_id' => 'required|string',
        ];
    }
}
