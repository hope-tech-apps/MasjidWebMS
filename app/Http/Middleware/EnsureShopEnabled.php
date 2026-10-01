<?php

namespace App\Http\Middleware;

use App\Exceptions\DarkRouteException;
use App\Models\Masjid;
use App\Support\PublicTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the shop's public reads DARK until the shop is on for the organisation (alias
 * `shop.enabled`; shop slice B2, DECISIONS.md 2026-10-01).
 *
 * A listing of products is useful only to a basket that can buy them, so TWO things must be true of
 * the organisation the `masjid-id` header names: the universal basket is on for it
 * (EnsureCartEnabled::enabledFor, config/cart.php) and a SuperAdmin has granted it the `shop`
 * capability (`Masjid::hasCapability`, OFF for every organisation type). Anything else, including no
 * header, an organisation that does not exist and one that has been offboarded, is the 404 the router
 * itself throws for a path it does not know, with the router's own message (DarkRouteException): the
 * same status, headers and body as a route that was never built, so a caller cannot tell "switched
 * off" from "not there", and nothing was read about the shop. It is ranked ahead of the throttles
 * (bootstrap/app.php), so a dark shop also runs no limiter and carries no rate-limit header.
 *
 * The organisation is read from the header exactly as the controllers read it
 * (`(int) header('masjid-id')`), so the two can never disagree about which organisation a request is
 * for, and "is it still a live organisation" is asked of PublicTenant::exists(), the one resolver every
 * unauthenticated `/api/v1` route asks (an offboarded organisation is offboarded everywhere,
 * PublicTenantLifecycleTest). Two cheap reads on a route that is not hot; the capability needs the
 * row itself.
 */
class EnsureShopEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enabledFor((int) $request->header('masjid-id'))) {
            throw DarkRouteException::forPath($request->path());
        }

        return $next($request);
    }

    /** Whether the shop's public reads are on for this organisation id. */
    public static function enabledFor(int $masjidId): bool
    {
        if ($masjidId <= 0 || ! EnsureCartEnabled::enabledFor($masjidId) || ! PublicTenant::exists($masjidId)) {
            return false;
        }

        $org = Masjid::query()->find($masjidId);

        return $org !== null && $org->hasCapability('shop');
    }
}
