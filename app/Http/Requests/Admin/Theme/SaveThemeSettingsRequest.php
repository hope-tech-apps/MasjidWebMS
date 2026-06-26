<?php

namespace App\Http\Requests\Admin\Theme;

use App\Http\Requests\BaseFormRequest;

class SaveThemeSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        // Each color is nullable so a partial theme is allowed; when present it
        // must be a hex value (#RGB, #RRGGBB or #RRGGBBAA — up to 9 chars to
        // match the column width).
        $hex = ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', 'max:9'];

        return [
            'primary_color' => $hex,
            'secondary_color' => $hex,
            'accent_color' => $hex,
            'background_color' => $hex,
        ];
    }
}
