<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST .../brand-assets/regenerate: an optional `background_color` for the
 * touch icon and share image. Absent, BrandAssets takes the theme's.
 *
 * The SuperAdmin check is authorize(), not a controller line, for the reason
 * SetFormsCardAccountRequest gives: a FormRequest validates at injection, so a
 * check in the body would show a non-super admin (who passes the route's
 * middleware) validation errors before refusing them.
 */
class RegenerateBrandAssetsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->type === 'SuperAdmin';
    }

    /**
     * An HttpException, as the controller's abort() was, so the app's renderer
     * gives the refusal the same body it always did ({status:'error', message},
     * the message sanitised outside debug).
     */
    protected function failedAuthorization(): void
    {
        throw new HttpException(Response::HTTP_FORBIDDEN, 'Only a super admin can regenerate an organisation\'s brand images.');
    }

    public function rules(): array
    {
        return [
            'background_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
