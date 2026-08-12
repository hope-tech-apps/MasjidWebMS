<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;

/**
 * POST /api/family/masjids/{masjid_id}/auth/request-code (T-015d).
 *
 * ---------------------------------------------------------------------------
 * WHAT IS NOT HERE, AND MUST NEVER BE ADDED
 * ---------------------------------------------------------------------------
 *
 * `exists:contacts,login_email`.
 *
 * `docs/t015-parent-identity-design.md` §11 names the staff login's
 * `exists:users,email` as a pre-existing enumeration oracle that the family
 * realm "must not copy", and a validation rule is the easiest place in Laravel
 * to reintroduce one by accident: it turns "no such address" into a 422 with a
 * message, against the 202 a real address gets. Here the roster is a list of
 * children and the question the oracle answers is "does this family attend this
 * school", so the rules below check SHAPE only. Whether the address names
 * anybody is decided inside App\Services\Family\FamilyLoginService, which is
 * built to answer it silently.
 *
 * `email` (the RFC validator) rather than `email:dns`: a DNS lookup makes the
 * response time depend on the domain, and the whole design of this endpoint is
 * that its response does not depend on its input.
 */
class RequestLoginCodeRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
