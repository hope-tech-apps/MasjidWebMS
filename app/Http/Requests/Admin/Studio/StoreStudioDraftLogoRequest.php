<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;
use App\Support\Studio\LogoDerivatives;
use App\Support\Studio\LogoTooLarge;
use Illuminate\Contracts\Validation\Validator;

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
        // Only the minimum here. The maximum edge is LogoDerivatives::MAX_EDGE, and
        // it and the memory left are checked together in after() below, on the
        // uploaded file, by the same code provisioning uses. A `dimensions` maximum
        // would answer an oversized logo with Laravel's generic sentence instead of
        // provisioning's, and the two could drift. A logo provisioning would refuse
        // is refused here in the same words and never stored (docs/manara-studio-w2.md S8).
        return [
            'logo' => [
                'required',
                'file',
                'mimetypes:' . config('studio.logo.mime_types', 'image/png,image/jpeg'),
                'max:' . (int) config('studio.logo.max_kb', 8192),
                "dimensions:min_width={$min},min_height={$min}",
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The type and size rules come first: a file that failed them is
                // not one to read a header from.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    LogoDerivatives::assertFits((string) $this->file('logo')->getRealPath());
                } catch (LogoTooLarge $e) {
                    // The sentence is LogoTooLarge's own, the one provisioning gives.
                    $validator->errors()->add('logo', $e->errors()['logo'][0]);
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimetypes' => 'The logo must be a PNG or JPEG image. SVG is not accepted; export the logo as a PNG.',
        ];
    }
}
