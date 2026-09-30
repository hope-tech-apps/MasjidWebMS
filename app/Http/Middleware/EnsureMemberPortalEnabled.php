<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps the member portal's "Your orders" routes DARK until the owner picks the
 * organisation that gets them (alias `member.portal`, config/member_portal.php;
 * DECISIONS.md 2026-09-30).
 *
 * The routes are registered unconditionally, so the route cache is the same whether the
 * portal is on or off; this middleware is the only thing that decides. With the portal off,
 * or off for the organisation in the URL, the answer is the NotFoundHttpException the router
 * itself throws for a path it does not know, with the router's own message: the same bytes as
 * a route that does not exist. It is ranked ahead of authentication and the throttles
 * (bootstrap/app.php), so an unauthenticated probe cannot tell a dark route from a missing one
 * by a 401, a 429 or a rate-limit header, and no token is looked up and no limiter is spent.
 *
 * The organisation is the ROUTE's `{masjid_id}` (whereNumber-constrained on the group that
 * carries these routes), so this and the controllers cannot disagree about whose portal it is.
 */
class EnsureMemberPortalEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enabledFor((int) $request->route('masjid_id'))) {
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        return $next($request);
    }

    /**
     * Whether the portal is on for this organisation id: the master switch, and then the
     * allowlist. An EMPTY allowlist means every organisation; a non-empty one admits only the
     * ids in it, so id 0 is never admitted by it.
     */
    public static function enabledFor(int $masjidId): bool
    {
        if (! (bool) config('member_portal.enabled', false)) {
            return false;
        }

        $allowed = array_map('intval', (array) config('member_portal.masjid_ids', []));

        return $allowed === [] || ($masjidId > 0 && in_array($masjidId, $allowed, true));
    }
}
