<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates POST /api/admin/studio/drafts/{draft_id}/logo (multipart `logo`).
 *
 * The type is matched with `mimetypes`, which reads the type sniffed from the
 * bytes — never `mimes` on the client's extension, never the Content-Type
 * header (.claude/rules/private-uploads.md). A text file renamed logo.png is
 * text/plain here and is refused. SVG is refused too: GD cannot rasterise it
 * into the favicon and share image Step 3 derives, and it can carry script.
 */
class StoreStudioDraftLogoRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $min = (int) config('studio.logo.min_px', 96);

        return [
            'logo' => [
                'required',
                'file',
                'mimetypes:' . config('studio.logo.mime_types', 'image/png,image/jpeg'),
                'max:' . (int) config('studio.logo.max_kb', 8192),
                "dimensions:min_width={$min},min_height={$min},max_width=8000,max_height=8000",
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimetypes' => 'The logo must be a PNG or JPEG image. SVG is not accepted; export the logo as a PNG.',
        ];
    }
}
