<?php

namespace App\Support;

use App\Models\MealOrder;
use Illuminate\Http\Request;

/**
 * Where a customer's own order page lives: /jummah-lunch/{masjidId}/order/{uuid}
 * on the SITE the order was placed from.
 *
 * The public lunch page is proxied onto masjids' own domains (e.g.
 * burlingtonmasjid.com/jummah-lunch/{id}), so the browser's Origin is that domain.
 * Only an origin already in the CORS allowlist is honoured, so a forged Origin
 * header can never make Stripe, or an email, send a customer to a stranger's site;
 * anything else falls back to APP_URL. This was JummahLunchOrdersController::
 * returnUrlsFor's rule alone until the order-link email (2026-09-24) needed the
 * same answer long after the request was gone, which is why the order remembers
 * its origin (`meal_orders.site_origin`) and the rule is asked AGAIN when the
 * remembered value is used: an origin removed from the allowlist since stops
 * being linked to.
 */
final class LunchOrderLink
{
    /** The request's Origin when the allowlist trusts it, else null. */
    public static function siteOrigin(Request $request): ?string
    {
        return self::trusted((string) $request->headers->get('Origin'));
    }

    /** `$origin` without its trailing slash when the allowlist trusts it, else null. */
    public static function trusted(?string $origin): ?string
    {
        $origin = rtrim((string) $origin, '/');

        if ($origin === '') {
            return null;
        }

        $allowed = array_map(
            fn ($o) => rtrim((string) $o, '/'),
            (array) config('cors.allowed_origins', [])
        );

        return in_array($origin, $allowed, true) ? $origin : null;
    }

    /** The order page on `$origin`, or on APP_URL when `$origin` is not a trusted one. */
    public static function url(MealOrder $order, ?string $origin = null): string
    {
        $base = self::trusted($origin)
            ?? self::trusted($order->site_origin)
            ?? rtrim((string) config('app.url'), '/');

        return $base . '/jummah-lunch/' . (int) $order->masjid_id . '/order/' . $order->uuid;
    }
}
