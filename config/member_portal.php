<?php

/*
|--------------------------------------------------------------------------
| The member portal's "Your orders" endpoints (DECISIONS.md 2026-09-30)
|--------------------------------------------------------------------------
|
| GET me/orders, me/orders/{source}/{id}, me/gifts and me/receipts/{id}/pdf list what a
| member bought by their contact and their verified address. Until the owner has chosen
| the organisation that gets it, and has answered ASSUMPTIONS #61 (an imported Wix order
| and a gift are listed by contact_id alone), the four routes are DARK: with `enabled`
| false, or off for the organisation in the URL, each answers the same 404 an unknown route
| does (App\Http\Middleware\EnsureMemberPortalEnabled), before authentication and before
| any throttle. The routes stay registered either way, so the route cache is the same in
| both states.
|
| Parse-check a candidate .env before config:cache (a bad .env plus config:cache once
| returned 500 on every request).
|
*/

return [

    /*
     * The master switch. FALSE unless MEMBER_PORTAL_ENABLED says yes; a typo reads as off,
     * which is the safe direction for a route that lists what a person paid.
     */
    'enabled' => filter_var(env('MEMBER_PORTAL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Which organisations may use it once it is enabled: a comma list of masjid ids
     * (MEMBER_PORTAL_MASJID_IDS="7,12"). EMPTY (or unset) means every organisation.
     *
     * FAILS CLOSED, exactly as config/cart.php does. A list that is set but has anything in
     * it that is not a positive integer ("7;12", "all", "*") becomes [0], which names no
     * organisation, rather than being read as empty: a typo in an allowlist must never
     * quietly switch the portal on for everyone.
     */
    'masjid_ids' => (static function (): array {
        // env() turns the literals true, false and null into a bool or null: not a list of ids.
        $raw = env('MEMBER_PORTAL_MASJID_IDS', '');

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

];
