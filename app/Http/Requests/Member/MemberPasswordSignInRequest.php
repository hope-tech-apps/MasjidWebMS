<?php

namespace App\Http\Requests\Member;

/**
 * POST /api/mobile/masjids/{masjid_id}/auth/password.
 *
 * Shape only, like App\Http\Requests\Family\PasswordSignInRequest and for the
 * same reasons:
 *
 *  - no `exists:` and no lookup, so a 422 never says whether an address is
 *    known here;
 *  - no strength rule. Strength is checked where a password is CHOSEN
 *    (VerifyMemberCodeRequest, SetFamilyPasswordRequest::strength()). Checking
 *    it where one is PRESENTED would answer a short guess with a 422 and a long
 *    wrong one with the 410, which says how long the stored password is, and
 *    it would lock out anyone whose password predates a longer minimum.
 *
 * `max:255` alone, so an unbounded string never reaches the hasher.
 */
class MemberPasswordSignInRequest extends MemberSignInFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
