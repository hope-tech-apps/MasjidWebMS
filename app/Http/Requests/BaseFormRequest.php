<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for all Form Requests in the application.
 *
 * Standardizes:
 * - 422 Unprocessable Entity on validation failure (was inconsistent: 400/422/500).
 * - { status: 'failed', data: <errors> } body shape (matches existing client expectations).
 * - Authorization defaults to true; route middleware (auth:sanctum + admin/super) handles auth.
 */
abstract class BaseFormRequest extends FormRequest
{
    /**
     * Authorize the request.
     *
     * Authorization is enforced by route middleware (auth:sanctum + admin/super aliases
     * registered in bootstrap/app.php). Per-resource policies can override this in subclasses.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Render validation failures in the legacy { status, data } envelope so existing
     * Vue admin and mobile clients keep working without changes.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'failed',
                'data' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
