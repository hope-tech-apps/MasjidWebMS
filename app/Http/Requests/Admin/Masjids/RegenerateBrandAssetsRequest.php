<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;

/**
 * POST .../brand-assets/regenerate: an optional `background_color` for the
 * touch icon and share image. Absent, BrandAssets takes the theme's.
 */
class RegenerateBrandAssetsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'background_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
