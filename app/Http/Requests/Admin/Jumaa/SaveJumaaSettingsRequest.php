<?php

namespace App\Http\Requests\Admin\Jumaa;

use App\Http\Requests\BaseFormRequest;

class SaveJumaaSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'iqama' => 'date_format:H:i',
            'athans' => 'array',
            'athans.*' => 'date_format:H:i|before:iqama',
        ];
    }
}
