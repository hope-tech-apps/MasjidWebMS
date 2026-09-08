<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;

/**
 * POST /api/family/masjids/{masjid_id}/auth/password.
 *
 * Shape only, exactly like VerifyLoginCodeRequest and for the same reason: no
 * `exists`, no lookup, nothing that could answer "is that address on file
 * here?" with a status code.
 *
 * NOTE what is deliberately NOT validated here: the password's strength. Rules
 * belong on the door where a password is CHOSEN (SetFamilyPasswordRequest), not
 * on the one where it is presented. A minimum length on sign-in would refuse a
 * short submission with a 422 while a wrong-but-long one got the uniform 410 —
 * which tells an attacker that the stored credential is at least that long, and
 * would eventually tell every parent whose password predates a tightened rule
 * that they are locked out. `max:255` alone, so an unbounded string never
 * reaches the hashing path.
 */
class PasswordSignInRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
