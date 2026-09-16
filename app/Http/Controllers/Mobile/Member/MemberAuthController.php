<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\MemberPasswordSignInRequest;
use App\Http\Requests\Member\RequestMemberCodeRequest;
use App\Http\Requests\Member\VerifyMemberCodeRequest;
use App\Models\Contact;
use App\Services\Member\MemberSignupService;
use App\Services\Member\NewMemberNameRequired;
use Symfony\Component\HttpFoundation\Response;

/**
 * How an app member gets a token. The self-serve twin of
 * App\Http\Controllers\Family\FamilyAuthController, and it inherits that
 * controller's central property:
 *
 * ---------------------------------------------------------------------------
 * NO RESPONSE IS AN ORACLE
 * ---------------------------------------------------------------------------
 * `request-code` answers 202 with one fixed body for every well-formed address
 * — one nobody has ever used, one belonging to a congregant the office has had
 * on file for a decade, and one whose access an administrator revoked this
 * morning. `MemberSignupService::issue()` returns void, so there is no value
 * here to branch on.
 *
 * `verify-code` collapses every failure into one 410: unknown address, no code
 * outstanding, wrong code, expired code, replayed code, a code guessed at too
 * many times, a revoked contact, and an address matching two contacts. A member
 * needs to know only that they must ask for a fresh code.
 *
 * `password` (2026-09-16) signs in with an address and a password. Every
 * failure is the SAME 410, byte for byte, as `verify-code`'s: unknown address,
 * wrong password, no password chosen, a contact that never proved the address
 * to the app, a revoked contact, and an address matching two contacts. Both
 * doors answer through `refuse()`, so the bodies cannot drift apart. The app
 * shows its own sentence for it; the body's words are about codes because they
 * must not differ.
 *
 * The one other answer is a 422 for a brand-new member who left a name blank
 * (NewMemberNameRequired), and only a CORRECT, unconsumed code reaches it. The
 * service checks the code first, so "this address has no account here yet" is
 * told only to someone who has just proven the mailbox is theirs. The code is
 * not consumed, and the same code works once the name is sent. The body is the
 * BaseFormRequest shape, `{status: "failed", message, data: {field: [message]}}`,
 * which both apps read field by field. Until 2026-09-15 this was a 410 that also
 * burned the code, so a new member who typed one name was told to start over.
 *
 * 410 rather than 401 for the same reason the family realm chose it: 401 is
 * what the guard and `member.active` emit once a token exists, and a client
 * cannot tell "your session died" from "that sign-in attempt failed" if both
 * are 401.
 *
 * A 422 also comes from the request rules, before any code is looked at: a
 * malformed address, or a `password` shorter than the parent portal's minimum.
 * Those depend only on what was typed. Both 422s have one shape,
 * `{status: "failed", message, data: {field: [message]}}`.
 *
 * There is deliberately NO register endpoint, because a separate registration
 * route could not avoid answering whether an address is already known to this
 * organisation. "Create an account" is `request-code` then `verify-code` with a
 * name and a `password`; "Forgot password?" is the same two calls with only the
 * `password`. The mailbox is proven before either writes anything. On an
 * address that already has an account, the password replaces the old one, in
 * the parent portal too, and every other session ends. Provenance: the one
 * shared password is the owner's choice (2026-09-16); ending the other sessions
 * comes from the 2026-09-16 sign-in contract, which reused
 * FamilyPasswordService::set(), and is not something the owner stated.
 */
class MemberAuthController extends Controller
{
    public function __construct(private MemberSignupService $signups)
    {
    }

    /** POST /api/mobile/masjids/{masjid_id}/auth/request-code — always 202. */
    public function requestCode(RequestMemberCodeRequest $request)
    {
        $this->signups->issue(
            (string) $request->input('email'),
            $request->ip(),
        );

        return response()->json([
            'status' => 'success',
            'message' => 'If that address can be used here, a sign-in code is on its way.',
            // An empty `data` object, not an omitted key. Every mobile response
            // carries one and the client decodes them all through a single
            // `Response<T>` envelope whose `data` is non-optional, so omitting
            // it here would fail to decode on the device rather than in any
            // test. Empty because there is nothing to say: the body is
            // identical for a known address, an unknown one and a revoked one.
            'data' => new \stdClass(),
        ], Response::HTTP_ACCEPTED);
    }

    /** POST /api/mobile/masjids/{masjid_id}/auth/verify-code */
    public function verifyCode(VerifyMemberCodeRequest $request)
    {
        // Null when absent or empty: a code sign-in without a password leaves
        // the contact's password exactly as it was. Not `filled()`: that reads a
        // whitespace-only value as absent, and a password the rules accepted
        // would then be dropped while the response said the sign-in worked.
        $password = $request->input('password');
        $password = is_string($password) && $password !== '' ? $password : null;

        try {
            $result = $this->signups->redeem(
                (string) $request->input('email'),
                (string) $request->input('code'),
                $request->input('first_name'),
                $request->input('last_name'),
                $request->ip(),
                $password,
            );
        } catch (NewMemberNameRequired $refusal) {
            return response()->json([
                'status' => 'failed',
                'message' => NewMemberNameRequired::MESSAGE,
                'data' => $refusal->fieldMessages(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $result === null ? $this->refuse() : $this->session($result);
    }

    /**
     * POST /api/mobile/masjids/{masjid_id}/auth/password — the same 200 as
     * `verify-code`, or the same 410.
     */
    public function signInWithPassword(MemberPasswordSignInRequest $request)
    {
        $result = $this->signups->attemptPassword(
            (string) $request->input('email'),
            (string) $request->input('password'),
            $request->ip(),
        );

        return $result === null ? $this->refuse() : $this->session($result);
    }

    /**
     * The one refusal, for every failure at both credential doors. Shared so
     * the password door cannot say anything the code door does not.
     */
    private function refuse()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'That code is no longer usable. Please request a new one.',
            // Present on the refusal too. The iPhone app decodes error bodies
            // through the same `Response<T>` envelope, so a 410 without it
            // showed a generic failure instead of this sentence.
            'data' => new \stdClass(),
        ], Response::HTTP_GONE);
    }

    /**
     * The one success body, for both doors: the same token kind and the same
     * contact projection.
     *
     * @param  array{contact: Contact, token: \Laravel\Sanctum\NewAccessToken, created: bool}  $result
     */
    private function session(array $result)
    {
        /** @var Contact $contact */
        $contact = $result['contact'];

        return response()->json([
            'status' => 'success',
            'data' => [
                'token' => $result['token']->plainTextToken,
                // True when this exchange created the contact, so the app can
                // send a first-run member straight into the interest picker and
                // a returning one straight into the app. Safe to disclose: the
                // caller proved control of the address to reach this line.
                'created' => $result['created'],
                // The SAME hand-built projection the family realm uses, for the
                // same reason: `notes` is staff-authored free text and `email` /
                // `phone` are the office's data, so serializing the model would
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
}
