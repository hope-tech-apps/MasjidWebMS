<?php

namespace App\Support;

use App\Models\MealOrder;

/**
 * Where a kitchen customer's order lives: the organisation's OWN website (the
 * Nuxt renderer's /kitchen/order/{uuid} page), never the admin app.
 *
 * Only an origin the CORS allowlist already trusts is used (LunchOrderLink::
 * trusted, the one rule for "a site we may send a customer back to"), taken from
 * the request that placed the order and remembered in `site_origin`, because the
 * card customer's email is sent later, from the webhook. With no trusted origin
 * there is no page to link to — the kitchen page exists only on the renderer —
 * so the answer is null and the email simply has no button, rather than a link
 * to a page that is not there.
 */
final class KitchenOrderLink
{
    public static function url(MealOrder $order, ?string $origin = null): ?string
    {
        $base = LunchOrderLink::trusted($origin) ?? LunchOrderLink::trusted($order->site_origin);

        return $base === null ? null : $base . '/kitchen/order/' . $order->uuid;
    }
}
