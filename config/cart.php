<?php

/*
|--------------------------------------------------------------------------
| The universal cart's public endpoints (DECISIONS.md 2026-09-29)
|--------------------------------------------------------------------------
|
| Other sessions ship production from `main`, so the basket is INERT there until the
| owner turns it on: with `enabled` false every cart route answers the same 404 an
| unknown route does (App\Http\Middleware\EnsureCartEnabled), and nothing is written.
| The routes stay registered either way, so the route cache is the same in both states.
|
| Parse-check a candidate .env before config:cache (a bad .env plus config:cache once
| returned 500 on every request).
|
*/

return [

    /*
     * The master switch. FALSE unless CART_ENABLED says yes; a typo reads as off, which
     * is the safe direction for a route that takes money.
     */
    'enabled' => filter_var(env('CART_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Which organisations may use it once it is enabled: a comma list of masjid ids
     * (CART_MASJID_IDS="7,12"). EMPTY (or unset) means every organisation.
     *
     * FAILS CLOSED. A list that is set but has anything in it that is not a positive
     * integer ("7;12", "all", "*") becomes [0], which names no organisation, rather than
     * being read as empty: a typo in an allowlist must never quietly switch the basket on
     * for everyone.
     */
    'masjid_ids' => (static function (): array {
        // env() turns the literals true, false and null into a bool or null: not a list of ids.
        $raw = env('CART_MASJID_IDS', '');

        if (! is_string($raw)) {
            return [0];
        }

        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if (! ctype_digit($part) || (int) $part < 1) {
                return [0];
            }

            $ids[] = (int) $part;
        }

        return array_values(array_unique($ids));
    })(),

    /*
     * How long an untouched basket lives, in days. SLIDING: every write pushes it out
     * again from that moment. Nothing in a basket is reserved, so expiry costs the
     * shopper only the retyping.
     */
    'ttl_days' => max(1, (int) env('CART_TTL_DAYS', 7)),

    /*
     * The most lines one basket may hold. It bounds what one anonymous token can make the
     * pricer re-ask on every read, and how much answers (personal data) one basket holds.
     */
    'max_lines' => max(1, (int) env('CART_MAX_LINES', 25)),

    /*
     * `cart:prune` (scheduled daily) deletes an OPEN basket once its expiry is more than
     * `grace_days` past, unless it has a PENDING order whose payment page could still be
     * paid: one whose own expiry is less than `hold_minutes` behind. A payment that lands
     * on a page as it lapses is still recorded even with the basket gone, so this is belt
     * and braces, not what makes a late payment safe.
     *
     * It also deletes an order whose status is `expired`, with its lines, once its page
     * closed more than `expired_order_days` ago: a payment page that was never completed,
     * holding the buyer's name, phone and address and the answers frozen in its lines.
     * Never a `pending` order (a delayed payment can still settle it) and never a `paid` one.
     * The floor is one day, so a typo cannot delete an order whose page has only just closed.
     */
    'prune' => [
        'grace_days' => max(0, (int) env('CART_PRUNE_GRACE_DAYS', 1)),
        'hold_minutes' => max(0, (int) env('CART_PRUNE_HOLD_MINUTES', 60)),
        'expired_order_days' => max(1, (int) env('CART_PRUNE_EXPIRED_ORDER_DAYS', 7)),
    ],

    /*
     * Named limiters (AppServiceProvider). Each is per hour unless its key says otherwise.
     * A basket's own limiters are keyed by the HMAC of its token (never the token itself,
     * which is a bearer secret and would sit in the cache); a call whose token names no
     * live basket meets the per-connection bucket of the same size instead, so junk tokens
     * cannot be sprayed for a fresh allowance each.
     */
    'throttle' => [
        // Creating a basket: per connection and organisation. 200, not a handful, because a
        // festival venue is one Wi-Fi network and so one public address: every phone in the
        // hall shares this allowance, and an abandoned basket is one cheap row that
        // `cart:prune` deletes. (DECISIONS.md, slice 5 fix round 2.)
        'create_per_hour' => max(1, (int) env('CART_CREATE_PER_HOUR', 200)),
        // Adding, removing, acknowledging: per basket.
        'write_per_hour' => max(1, (int) env('CART_WRITE_PER_HOUR', 120)),
        // Reading the priced basket: per basket.
        'read_per_hour' => max(1, (int) env('CART_READ_PER_HOUR', 600)),
        // Opening a Stripe page: per basket.
        'checkout_per_hour' => max(1, (int) env('CART_CHECKOUT_PER_HOUR', 20)),
        // The return page's payment-state read: per order uuid, and the per-connection
        // guard (per minute) a made-up uuid meets instead, as the form status read does.
        'order_status_per_hour' => max(1, (int) env('CART_ORDER_STATUS_PER_HOUR', 30)),
        'order_status_guard_per_minute' => max(1, (int) env('CART_ORDER_STATUS_GUARD_PER_MINUTE', 300)),
    ],

];
