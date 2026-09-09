<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\RequestMemberCodeRequest;
use App\Http\Requests\Member\VerifyMemberCodeRequest;
use App\Models\Contact;
use App\Services\Member\MemberSignupService;
use Symfony\Component\HttpFoundation\Response;

/**
 * How an app member gets a token. The self-serve twin of
 * App\Http\Controllers\Family\FamilyAuthController, and it inherits that
 * controller's central property:
 *
 * ---------------------------------------------------------------------------
 * NEITHER RESPONSE IS AN ORACLE
 * ---------------------------------------------------------------------------
 * `request-code` answers 202 with one fixed body for every well-formed address
 * — one nobody has ever used, one belonging to a congregant the office has had
 * on file for a decade, and one whose access an administrator revoked this
 * morning. `MemberSignupService::issue()` returns void, so there is no value
 * here to branch on.
 *
 * `verify-code` collapses every failure into one 410: unknown address, no code
 * outstanding, wrong code, expired code, replayed code, a code guessed at too
 * many times, a revoked contact, an address matching two contacts, and a
 * would-be new member who sent no name. A member needs to know only that they
 * must ask for a fresh code.
 *
 * 410 rather than 401 for the same reason the family realm chose it: 401 is
 * what the guard and `member.active` emit once a token exists, and a client
 * cannot tell "your session died" from "that sign-in attempt failed" if both
 * are 401.
 *
 * There is deliberately NO register endpoint. Sign-up and sign-in are the same
 * two calls, because a separate registration route could not avoid answering
 * whether an address is already known to this organisation.
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
        $result = $this->signups->redeem(
            (string) $request->input('email'),
            (string) $request->input('code'),
            $request->input('first_name'),
            $request->input('last_name'),
            $request->ip(),
        );

        if ($result === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'That code is no longer usable. Please request a new one.',
            ], Response::HTTP_GONE);
        }

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
