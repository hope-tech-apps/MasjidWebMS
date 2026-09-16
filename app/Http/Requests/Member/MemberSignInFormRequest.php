<?php

namespace App\Http\Requests\Member;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 422 shape for the app's sign-in doors that name fields back to the caller:
 * `verify-code` and `password`.
 *
 * `{status: "failed", message, data: {field: [message]}}` — the BaseFormRequest
 * envelope with a `message` beside it. That is the exact shape `verify-code`
 * already answers with when a new member leaves a name blank
 * (App\Services\Member\NewMemberNameRequired), so a client reads a short
 * password and a blank name through one decoder, field by field. Without this
 * class a validation failure here reaches the shared renderer in
 * bootstrap/app.php and arrives with no `message`.
 *
 * `message` is the first field's sentence. Nothing here depends on whether the
 * address is known: these rules look only at what the caller typed.
 *
 * `request-code` does not use this class. Its body is unchanged.
 */
abstract class MemberSignInFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        throw new HttpResponseException(
            response()->json([
                'status' => 'failed',
                'message' => (string) $errors->first(),
                'data' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
