<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Family\RequestLoginCodeRequest;
use App\Http\Requests\Family\VerifyLoginCodeRequest;
use App\Models\Contact;
use App\Services\Family\FamilyLoginService;
use Symfony\Component\HttpFoundation\Response;

/**
 * How a parent gets a token (T-015d) — the two endpoints
 * `docs/t015-parent-identity-design.md` §3 specifies, and the only way a
 * `family` credential comes into existence.
 *
 * ---------------------------------------------------------------------------
 * These are the only UNAUTHENTICATED routes in the family realm
 * ---------------------------------------------------------------------------
 *
 * They cannot sit behind `auth:family` — a caller with no token is exactly who
 * they are for — so the stack under them is `family.guest` (bind the tenant from
 * the URL, or 404), `crm` (the same per-masjid feature gate the rest of the
 * realm carries) and a named throttle. What they do NOT carry, and must never
 * carry, is `admin`, `super`, `permission:` or `tenant`: see routes/family.php.
 *
 * ---------------------------------------------------------------------------
 * NEITHER RESPONSE IS AN ORACLE
 * ---------------------------------------------------------------------------
 *
 * `request-code` answers 202 with one fixed body for every well-formed address,
 * whether it names a live parent, a contact whose login was revoked this
 * morning, a contact that never had one, or nobody at all. The controller
 * CANNOT do otherwise: `FamilyLoginService::issue()` returns void, so there is
 * no value here to branch on. That is deliberate — a helpful 404 on this
 * endpoint would answer "does this family attend this school?" for anyone with
 * a list of email addresses, about a roster of children.
 *
 * `verify-code` collapses six different failures into one 410: unknown address,
 * no code outstanding, wrong code, expired code, already-used code, and a code
 * that has been guessed at too many times. A parent needs to know only that they
 * must ask for a fresh code; an attacker learns nothing about which of the six
 * they hit, and in particular cannot use the difference between "wrong code" and
 * "no such address" as a directory.
 *
 * 410 rather than 401, for both the design's reason (§3 names it) and a
 * practical one: 401 on this route would collide with the envelope the guard and
 * `family.active` emit everywhere else in the realm, and a client cannot tell
 * "your token died, sign in again" from "that sign-in attempt failed" if both
 * are 401.
 */
class FamilyAuthController extends Controller
{
    public function __construct(private FamilyLoginService $logins)
    {
    }

    /**
     * POST /api/family/masjids/{masjid_id}/auth/request-code
     *
     * Always 202. Always this body.
     */
    public function requestCode(RequestLoginCodeRequest $request)
    {
        $this->logins->issue(
            (string) $request->input('email'),
            $request->ip(),
        );

        return $this->accepted();
    }

    /**
     * POST /api/family/masjids/{masjid_id}/auth/verify-code
     *
     * The code is single-use and burned inside the same transaction that mints
     * the token (FamilyLoginService::consume), so a replay of a code that just
     * worked lands on the identical 410 an expired one does.
     */
    public function verifyCode(VerifyLoginCodeRequest $request)
    {
        $result = $this->logins->redeem(
            (string) $request->input('email'),
            (string) $request->input('code'),
            $request->ip(),
        );

        if ($result === null) {
            return $this->refuse();
        }

        /** @var Contact $contact */
        $contact = $result['contact'];

        return response()->json([
            'status' => 'success',
            'data' => [
                'token' => $result['token']->plainTextToken,
                // Echoed so the app can render a name and pin the organisation
                // without a second round-trip. The SAME hand-built projection
                // MeController uses, for the same reason: `notes` is a
                // staff-authored free-text field and `email` / `phone` are the
                // office's contact data, and serializing the model would
                // publish every column added to `contacts` from now on.
                'contact' => [
                    'id' => (int) $contact->id,
                    'masjid_id' => (int) $contact->masjid_id,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'login_email' => $contact->login_email,
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The one accepted response. Extracted so the two callers cannot drift, and
     * so a future third caller cannot introduce a second wording that would
     * itself become the oracle.
     */
    private function accepted()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'If that address is on file, a sign-in code is on its way.',
        ], Response::HTTP_ACCEPTED);
    }

    /** The one refusal. Six causes, one body — see the class docblock. */
    private function refuse()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'That sign-in code is no longer valid. Please request a new one.',
        ], Response::HTTP_GONE);
    }
}
