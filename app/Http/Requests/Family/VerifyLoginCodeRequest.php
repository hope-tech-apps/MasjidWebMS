<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;

/**
 * POST /api/family/masjids/{masjid_id}/auth/verify-code (T-015d).
 *
 * Shape only, for the same reason as RequestLoginCodeRequest: no `exists`, no
 * lookup, nothing that could answer "is that address on file here?" with a
 * status code.
 *
 * `digits_between` rather than `digits:6` — the code length is configurable
 * (`config/family.php`), and a validator that hardcoded six would start
 * rejecting valid codes the moment an operator lengthened them, which is the
 * kind of failure that looks like "logins are broken" rather than "a rule is
 * stale". The RANGE is still bounded so an unbounded string never reaches the
 * hashing path.
 *
 * A malformed code is a 422 and a well-formed WRONG code is a 410. That
 * difference discloses nothing — it is a fact about the caller's own input,
 * knowable without any server — and it keeps a client able to tell "you typed
 * four digits" from "that code is dead".
 */
class VerifyLoginCodeRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits_between:4,12'],
        ];
    }
}
