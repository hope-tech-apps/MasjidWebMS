<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps the universal cart's public routes DARK until the owner turns them on
 * (alias `cart.enabled`, config/cart.php; DECISIONS.md 2026-09-29).
 *
 * Other sessions ship production from `main`. The routes are registered
 * unconditionally, so the route cache is the same whether the basket is on or off and
 * a deploy never has to rebuild it; this middleware is the only thing that decides. With
 * the cart off, or off for the organisation the `masjid-id` header names, the answer is
 * the NotFoundHttpException the router itself throws for a path it does not know, with
 * the router's own message, so the response is the same bytes as a route that does not
 * exist, in debug and out of it. A caller cannot tell "switched off" from "never built",
 * and nothing was read or written: this runs before the throttles and the controller.
 *
 * The organisation is read from the header exactly as the controllers read it
 * (`(int) header('masjid-id')`), so the two can never disagree about which organisation a
 * request is for.
 */
class EnsureCartEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enabledFor((int) $request->header('masjid-id'))) {
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        return $next($request);
    }

    /**
     * Whether the basket is on for this organisation id: the master switch, and then the
     * allowlist. An EMPTY allowlist means every organisation; a non-empty one admits only
     * the ids in it, so a missing header (id 0) is never admitted by it.
     */
    public static function enabledFor(int $masjidId): bool
    {
        if (! (bool) config('cart.enabled', false)) {
            return false;
        }

        $allowed = array_map('intval', (array) config('cart.masjid_ids', []));

        return $allowed === [] || ($masjidId > 0 && in_array($masjidId, $allowed, true));
    }
}
