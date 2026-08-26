<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The student surfaces require a hand-off token PINNED TO THE MEMBERSHIP in the
 * route.
 *
 * Two refusals, and both matter:
 *   - a PARENT token is refused here, so the student routes cannot quietly
 *     become a second, weaker way for a parent to act;
 *   - a hand-off token minted for ANOTHER child is refused, because the ability
 *     names one membership and this compares it to the one being addressed.
 *     Two siblings on one phone are therefore two different sessions.
 */
class EnsureStudentHandoffToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membershipId = (int) $request->route('membership_id');

        if ($user === null || $membershipId <= 0) {
            abort(Response::HTTP_FORBIDDEN, 'This link is not a student session.');
        }

        if (! $user->tokenCan(Contact::studentAbility($membershipId))) {
            abort(Response::HTTP_FORBIDDEN, 'This student session is not for this student.');
        }

        return $next($request);
    }
}
