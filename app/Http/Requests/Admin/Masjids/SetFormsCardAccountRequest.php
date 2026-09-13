<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * PATCH /api/admin/masjids/{masjid_id}/forms-card-account (DECISIONS.md 2026-09-15).
 *
 * The SuperAdmin check lives in authorize(), NOT in the controller body the way
 * setCapability does it. A FormRequest validates at injection, before the
 * controller runs, so a body check would hand a non-super admin (who passes the
 * tenant check on their own route) 422s describing the link and the holder
 * before refusing them. authorize() runs first, so a non-super caller gets a 403
 * carrying no validation keys, whatever the payload.
 */
class SetFormsCardAccountRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->type === 'SuperAdmin';
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Only a super admin can change where an organisation\'s form card payments are charged.',
        ], Response::HTTP_FORBIDDEN));
    }

    public function rules(): array
    {
        $linking = fn (): bool => $this->input('via_masjid_id') !== null;

        return [
            // `present`, so an empty body is a 422 and never an accidental unlink.
            'via_masjid_id' => ['present', 'nullable', 'integer', 'min:1'],
            'typed_holder_name' => [Rule::requiredIf($linking), 'nullable', 'string', 'max:255'],
            'consent_reference' => [Rule::requiredIf($linking), 'nullable', 'string', 'max:1000'],
        ];
    }
}
