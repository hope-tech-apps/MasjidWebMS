<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The parent surfaces require a PARENT token.
 *
 * Family tokens have always carried `['family']` — deliberately not `['*']` —
 * so that abilities could later gate a route without reissuing tokens already
 * in people's phones. A student hand-off token carries only
 * `student:{membership}` and therefore fails here.
 *
 * That is what makes child mode a boundary rather than a screen a child is
 * asked not to leave: with the phone in their hands and the app open, the class
 * feed, the teacher threads and every other child's record are refused by the
 * SERVER, not hidden by the client.
 */
class EnsureFamilyParentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No token at all is `auth:family`'s business, not ours — it has
        // already run and this would be a second, weaker answer to that
        // question.
        if ($user === null) {
            return $next($request);
        }

        if (! $user->tokenCan(Contact::FAMILY_TOKEN_ABILITIES[0])) {
            abort(
                Response::HTTP_FORBIDDEN,
                'This is a student session. Hand the device back to a parent to use the family portal.'
            );
        }

        return $next($request);
    }
}
