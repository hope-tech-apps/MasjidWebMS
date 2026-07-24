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

            // Richer per-khutbah shifts (backward-compat: `athans` still accepted).
            // Each shift needs a time; khateeb/khutbah metadata is optional.
            'shifts' => 'nullable|array',
            'shifts.*.time' => 'required|date_format:H:i',
            'shifts.*.khateeb_name' => 'nullable|string|max:255',
            'shifts.*.khateeb_title' => 'nullable|string|max:255',
            'shifts.*.khutbah_title' => 'nullable|string|max:255',
        ];
    }
}
