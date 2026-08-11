<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the parent/guardian API (alias `family.active` in bootstrap/app.php):
 * the caller must be a Contact whose login is enabled, not revoked, and not
 * deleted — re-checked on EVERY request in the family tree.
 *
 * ---------------------------------------------------------------------------
 * Why this is not redundant with the `family` guard
 * ---------------------------------------------------------------------------
 *
 * It is tempting to read `auth.guards.family.provider = contacts` and conclude
 * that anything reaching here is already a live Contact. It is not, for two
 * independent reasons:
 *
 *  1. **Sanctum checks the session guards FIRST.** `Guard::__invoke()` walks
 *     `config('sanctum.guard')` — `['web']` in this app — and returns any user
 *     it finds there immediately, with NO provider comparison. A staff member
 *     holding a live admin SPA session therefore satisfies `auth:family`
 *     outright. The provider pin cannot see that request; the `instanceof
 *     Contact` check below is the only thing that refuses it, which is why the
 *     design assigns staff-exclusion from the family surface to this middleware
 *     rather than to the guard (docs/t015-parent-identity-design.md §5, Q4).
 *  2. **Revocation is a per-request fact, not a mint-time one.** A token that
 *     is already in a parent's phone is valid until it expires; the office
 *     revoking access has to reach it on the NEXT request. Checking liveness
 *     only inside `GroupAudience` would leave any endpoint that never calls
 *     GroupAudience — this one, /me, included — open to a revoked credential.
 *
 * ---------------------------------------------------------------------------
 * One refusal, three causes
 * ---------------------------------------------------------------------------
 *
 * Not-a-contact, never-enabled, revoked and deleted all answer with the same
 * 401 body. The caller of a revoked credential learns only that it no longer
 * works — nothing about whether the contact still exists, whether the login was
 * withdrawn or whether the record was merged away. The data behind this
 * boundary is children's photographs and safeguarding conversations; a helpful
 * error message here is a disclosure.
 *
 * The envelope is deliberately DISTINCT from the two that already exist, so a
 * test can tell which layer refused: the guard's own refusal renders
 * `{status: error, message: 'Unauthenticated.'}` (bootstrap/app.php), and
 * `UserAdminMiddleware` answers `{status: failed, data: 'Unauthorized.'}`.
 */
class EnsureFamilyLoginActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Auth::user() reads the DEFAULT guard, which `auth:family` has rebound
        // to `family`. If this middleware is ever mounted without `auth:family`
        // in front of it, the default guard is `web` and a staff session user —
        // or null — arrives here instead; both fail the check below, so the
        // mistake fails closed rather than opening the tree.
        $principal = Auth::user();

        if (! $principal instanceof Contact) {
            return $this->refuse();
        }

        // Read off the principal the guard just loaded from the database this
        // request — not a cached or token-embedded copy — so `login_revoked_at`
        // written a second ago is already in force here.
        if (! $principal->familyLoginIsActive()) {
            return $this->refuse();
        }

        return $next($request);
    }

    private function refuse(): Response
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
