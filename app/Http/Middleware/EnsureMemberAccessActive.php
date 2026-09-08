<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member realm's liveness check — the twin of `family.active`
 * (EnsureFamilyLoginActive), and deliberately NOT the same predicate.
 *
 * `family.active` turns on `login_enabled_at`: the office GRANTED somebody
 * portal access to a child's records. This one turns on `verified_at`: somebody
 * proved they control an email address. Keeping them apart is what stops app
 * sign-up from being a route into the family realm — a self-registered member
 * never has `login_enabled_at`, so every family route refuses their token while
 * this one admits it.
 *
 * Both honour `login_revoked_at`. An administrator who revoked a contact
 * revoked the person, not one channel.
 *
 * Checked per REQUEST rather than at token-mint time so that revoking access
 * takes effect on the next call rather than whenever a 30-day token happens to
 * lapse.
 */
class EnsureMemberAccessActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();

        // Not a Contact at all means a staff token reached a member route —
        // the guard/provider pair should already have prevented it, and this is
        // the belt to that braces.
        if (! $principal instanceof Contact || ! $principal->memberAccessIsActive()) {
            abort(Response::HTTP_UNAUTHORIZED, 'Your session is no longer valid. Please sign in again.');
        }

        return $next($request);
    }
}
