<?php

namespace App\Http\Requests\Member;

use App\Http\Requests\Family\SetFamilyPasswordRequest;
use Illuminate\Validation\Rule;

/**
 * `first_name` / `last_name` are OPTIONAL here and required only in effect:
 * MemberSignupService needs them when it has to CREATE a contact and ignores
 * them entirely when it links to one the office already has.
 *
 * They are not `required` rules because validation runs BEFORE the code is
 * checked, so a 422 from here would answer "would this address create a new
 * person here?" for anybody who types an address, which is the disclosure this
 * whole flow is built to avoid. MemberSignupService asks for a missing name
 * itself (NewMemberNameRequired, a 422), only once the code has proven the
 * caller owns the address, and without consuming the code.
 *
 * `password` (2026-09-16) is OPTIONAL too, and when present becomes the
 * contact's password in the transaction that burns the code: "Create an
 * account" and "Forgot password?" in the apps. Unlike the name, its rule IS
 * checked here, before the code is looked at. That is safe because the rule
 * depends only on what was typed, never on the address, so a 422 for a short
 * password is the same for every address and discloses nothing. It is also
 * necessary: once the code is spent, a refusal about the password would cost
 * the member their code. The rule is the parent portal's, exactly
 * (SetFamilyPasswordRequest::strength()), because both realms read the one
 * `contacts.password`.
 *
 * No `confirmed`: the apps show the password with a show/hide control instead
 * of asking for it twice.
 *
 * Absent, null and "" (which ConvertEmptyStringsToNull makes null) all mean "no
 * password", and the redeem leaves the contact's password alone. A value made
 * only of spaces is NOT absent: TrimStrings leaves password fields untouched,
 * and Laravel skips every non-implicit rule for such a value, so without the
 * `required_if` below "   " would pass the length rule and become somebody's
 * password. The portal refuses it through `required`; this refuses it the same
 * way.
 */
class VerifyMemberCodeRequest extends MemberSignInFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'code' => ['required', 'string', 'max:16'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'password' => [
                Rule::requiredIf(fn (): bool => $this->input('password') !== null),
                'nullable',
                'string',
                'max:255',
                SetFamilyPasswordRequest::strength(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = SetFamilyPasswordRequest::strengthMessages();

        // Only a present, blank-looking password reaches `required`, and to the
        // person typing it that is a password too short to use.
        return $messages + ['password.required' => $messages['password.min']];
    }
}
