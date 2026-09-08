<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `first_name` / `last_name` are OPTIONAL here and required only in effect:
 * MemberSignupService needs them when it has to CREATE a contact and ignores
 * them entirely when it links to one the office already has.
 *
 * They are not `required` rules because a rule would make the API answer
 * "would this address create a new person here?" through a 422 — which is the
 * disclosure this whole flow is built to avoid. A caller that omits them and
 * would have created a contact gets the same 410 as a wrong code. The app
 * always sends them, so no real member meets that path.
 */
class VerifyMemberCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'code' => ['required', 'string', 'max:16'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
