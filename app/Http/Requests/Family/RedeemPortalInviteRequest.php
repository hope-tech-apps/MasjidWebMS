<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;

/**
 * POST /api/family/masjids/{masjid_id}/auth/invite (2026-09-24).
 *
 * Shape only, exactly like the other two sign-in requests: no `exists`, no
 * lookup, nothing that could answer a question about a family with a status
 * code. A malformed token is a 422 and a well-formed wrong one is a 410 — a
 * difference that is a fact about the caller's own input, knowable without any
 * server, and which keeps a client able to tell "nothing was in the link" from
 * "that link is dead".
 *
 * `size:64` is the hex length `FamilyInviteService` mints (32 bytes), and it is
 * a CEILING as much as a shape check: without it an arbitrarily long string
 * reaches `hash_hmac`. `alpha_num` because hex is, and because it keeps anything
 * that could be read as markup or a path out of the one string this endpoint
 * takes.
 *
 * NOT a `regex:/^[0-9a-f]{64}$/` — the rule would have to be kept in agreement
 * with the mint by hand, and the digest comparison is what actually decides
 * this. A wrong-but-well-formed token is a 410 either way.
 */
class RedeemPortalInviteRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64', 'alpha_num'],
        ];
    }
}
