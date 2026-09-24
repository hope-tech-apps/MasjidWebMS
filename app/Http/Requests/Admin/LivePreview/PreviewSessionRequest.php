<?php

namespace App\Http\Requests\Admin\LivePreview;

use App\Http\Requests\BaseFormRequest;
use App\Support\Renderer\PreviewToken;
use Closure;

/**
 * The page a preview session will open. Absent means the home page.
 *
 * The rule is the renderer's own (PreviewToken::isSafePath): the path becomes the
 * renderer's request path, so anything it would refuse is refused here first, with a
 * 422 the admin can read, rather than a preview that silently shows "not found".
 */
class PreviewSessionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'path' => [
                'sometimes',
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && ! PreviewToken::isSafePath($value)) {
                        $fail('The path must be a site path such as / or /about.');
                    }
                },
            ],
        ];
    }

    public function previewPath(): string
    {
        $path = $this->validated('path');

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
