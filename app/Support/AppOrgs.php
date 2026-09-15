<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Collection;

/**
 * The organisations one app may offer as somewhere to switch into: its home,
 * then the children that home has published.
 *
 * This list is answered by TWO endpoints — `/masjids/{id}/orgs` (what the
 * switcher draws) and `/masjids/{id}/menu` (the profiles the side menu carries,
 * each with its own feature list). They are fetched separately, cached
 * separately and refreshed on different cadences, so the one thing that must
 * never happen is the two DISAGREEING about which organisations exist or what
 * order they come in: a member would open the switcher, pick a name that the
 * menu has no profile for, and land on an organisation the drawer cannot
 * describe.
 *
 * So membership and order live here, in one query, and both controllers call
 * it. The rules are the ones `/orgs` already had, unchanged:
 *
 *  - the home organisation first, flagged, so a client can always get back
 *    without having to remember which id it started from;
 *  - then its LISTED children by name — `listed_at` is the deliberate act of
 *    publishing an organisation, and a child mid-setup must not turn up in a
 *    switcher on somebody's phone;
 *  - asking for a CHILD's id returns just that child: it has no children of its
 *    own to offer, and its parent is not its to publish.
 *
 * Soft-deleted children are absent (the relation excludes them), and the public
 * identity served here — name, type, logo — is the same one the anonymous
 * organisation directory already serves.
 */
class AppOrgs
{
    /**
     * The home organisation followed by its listed children, ordered by name.
     *
     * Eager-loads what BOTH payloads need (the logo and the theme row) so
     * neither caller pays per-row queries for the other's fields.
     *
     * @return Collection<int, Masjid>
     */
    public static function forHome(Masjid $home): Collection
    {
        $home->loadMissing(['logo', 'themeSettings']);

        return collect([$home])->concat(
            $home->listedChildren()->with(['logo', 'themeSettings'])->orderBy('name')->get()
        )->values();
    }

    /**
     * One row of `GET /masjids/{id}/orgs`.
     *
     * These five keys, in this order, with these values, are what the installed
     * iPhone store build and Play vc13 decode. They do not change. Anything
     * additive goes AFTER them.
     *
     * @return array<string, mixed>
     */
    public static function row(Masjid $org, Masjid $home): array
    {
        return [
            'id' => (int) $org->id,
            'name' => $org->name,
            'org_type' => $org->orgType(),
            'is_home' => (int) $org->id === (int) $home->id,
            'logo_url' => $org->logo?->original_url,
        ];
    }
}
