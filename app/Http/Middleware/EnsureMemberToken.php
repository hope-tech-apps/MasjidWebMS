<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member realm requires a MEMBER token.
 *
 * `auth:family`, `member.active` and `family.tenant` all ask about the CONTACT:
 * is there one, has it proved an address, which organisation is it in. None of
 * them asks which door the credential came through, and one contact can hold
 * three kinds of token:
 *
 *  - `member`, minted by app sign-in (MemberSignupService);
 *  - `family`, minted by the family portal's sign-in;
 *  - `student:{membership}`, the hand-off token a parent mints FROM THEIR OWN
 *    CONTACT for a child holding the device (Contact::createStudentHandoffToken).
 *
 * A parent who also signed in to the app has `verified_at`, so without this
 * check a child holding that hand-off token passes all three gates and can call
 * `DELETE .../me`: every one of the parent's tokens revoked, the office-granted
 * family login cleared, perhaps the contact erased. The family portal refuses the
 * same token with `family.parent`; this is the member realm's equivalent.
 *
 * Runs AFTER `member.active`, which has already refused anything that is not a
 * Contact. That order matters: a staff `web` session reaches here as a User with
 * a TransientToken, and a TransientToken answers yes to every ability.
 *
 * The refusal is written here rather than with abort(): the production renderer
 * replaces an HttpException's message with "Request failed.", and the iPhone app
 * cannot decode a body without a `data` key.
 */
class EnsureMemberToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $contact = $request->user();

        if (! $contact instanceof Contact || ! $contact->tokenCan(Contact::MEMBER_TOKEN_ABILITIES[0])) {
            return response()->json([
                'status' => 'error',
                'message' => 'This sign-in cannot be used in the app. Please sign in to the app again.',
                'data' => new \stdClass(),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
