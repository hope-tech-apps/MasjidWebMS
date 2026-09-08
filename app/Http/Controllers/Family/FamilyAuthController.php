<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Family\PasswordSignInRequest;
use App\Http\Requests\Family\RequestLoginCodeRequest;
use App\Http\Requests\Family\VerifyLoginCodeRequest;
use App\Models\Contact;
use App\Services\Family\FamilyLoginService;
use App\Services\Family\FamilyPasswordService;
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
 * `password` (2026-09-08) collapses four more into the SAME 410, from the same
 * private method: unknown address, revoked login, no password chosen, wrong
 * password. Sharing the body is not tidiness — the two doors now open the same
 * account, so any observable difference between them would answer "does this
 * family use a password?" about a specific family. They share the throttle
 * bucket for the matching reason: separate allowances would hand an attacker
 * twice the guesses against one address.
 *
 * 410 rather than 401, for both the design's reason (§3 names it) and a
 * practical one: 401 on this route would collide with the envelope the guard and
 * `family.active` emit everywhere else in the realm, and a client cannot tell
 * "your token died, sign in again" from "that sign-in attempt failed" if both
 * are 401.
 */
class FamilyAuthController extends Controller
{
    public function __construct(
        private FamilyLoginService $logins,
        private FamilyPasswordService $passwords,
    ) {
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

        return $result === null ? $this->refuse() : $this->session($result);
    }

    /**
     * POST /api/family/masjids/{masjid_id}/auth/password
     *
     * The SECOND door (2026-09-08). A parent who chose a password signs in with
     * it instead of fetching a code from their mailbox.
     *
     * It answers with the SAME 410 body `verify-code` does, and that sameness is
     * load-bearing in a way the other endpoints' is not. This route and
     * `verify-code` are now two ways to attack the same account, so any
     * difference between them — a distinct status, a distinct wording, even a
     * distinct field ordering — would let a caller ask "does this address have a
     * password?", which is a question about a specific family. One body, four
     * causes: unknown address, revoked login, no password chosen, wrong
     * password. `FamilyPasswordService::attempt()` returns null for all four and
     * pays the same hashing cost for each, so the wall clock does not answer it
     * either.
     *
     * Throttled with `family-verify`, deliberately the SAME bucket as the code
     * door rather than a new one: two doors with separate allowances would give
     * an attacker double the guesses against one address, which is exactly the
     * mistake `redeem()` avoids when it charges every live code for a wrong
     * guess.
     */
    public function signInWithPassword(PasswordSignInRequest $request)
    {
        $result = $this->passwords->attempt(
            (string) $request->input('email'),
            (string) $request->input('password'),
            $request->ip(),
        );

        return $result === null ? $this->refuse() : $this->session($result);
    }

    /**
     * The one successful body, shared by both doors.
     *
     * Extracted when the password door landed: two hand-built copies of this
     * projection would be two places for a column added to `contacts` to start
     * leaking, and the reason it is hand-built at all is that `notes` is
     * staff-authored free text and `email`/`phone` are the office's contact
     * data. A parent's own password never appears here in any form — not the
     * hash (which `$hidden` also stops), not a "you have one" flag; `/me` is
     * where a client asks that, holding a token.
     *
     * @param  array{contact: Contact, token: \Laravel\Sanctum\NewAccessToken}  $result
     */
    private function session(array $result)
    {
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
