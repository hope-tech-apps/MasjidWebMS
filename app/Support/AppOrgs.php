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
     * The first five keys, in this order, with these values, are what the
     * installed iPhone store build and Play vc13 decode. They do not change.
     * Anything additive goes AFTER them — `theme` is the first and, in the S1
     * deploy, the only such addition, and it is the ONE intended production
     * payload diff of that deploy (plan v3 §2.2).
     *
     * Why the switcher needs a theme at all: the switch overlay paints the
     * TARGET organisation's brand band before any `/menu` for it has been
     * fetched, and on a first launch with no cached menu `/orgs` is the only
     * theme the client has. Without it the overlay either flashes the home
     * org's colour behind the wrong name or paints a name onto a band it
     * cannot be read against.
     *
     * Installed decoders ignore the key: iOS `Org.swift` lists explicit
     * CodingKeys, Android `OrgsResponse.kt` is Gson. Neither is asked to
     * change to keep working.
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
            'theme' => self::theme($org),
        ];
    }

    /**
     * The four theme keys for one organisation, or null when it has no usable
     * brand colour.
     *
     * ONE builder, called by BOTH payloads — `/orgs` rows here and `/menu`
     * profiles in AppMenu::profile(). The contrast decision (which text colour
     * reads on the band, whether the brand colour may be used for text on a
     * white surface, whether the band is readable only at large sizes) is the
     * kind of thing that gets "fixed" in one place and forgotten in the other,
     * and then the same organisation's name is white in the switcher and black
     * in the drawer on the same phone.
     *
     * All four keys or none. A client handed `primary` without `on_primary`
     * would be back to deriving contrast on the phone, which is exactly the
     * per-platform divergence WcagColor exists to end.
     *
     * Note that the rest of the row is NOT shared with a `/menu` profile even
     * though the first six keys currently match: `/menu`'s body is hashed into
     * an ETag, so a future `/orgs`-only key riding in on a shared builder would
     * silently change every phone's menu tag and re-download a menu that did
     * not change. Only the piece that must agree is shared.
     *
     * @return array{primary: string, on_primary: string, primary_on_surface: string, band_text_large_only: bool}|null
     */
    public static function theme(Masjid $org): ?array
    {
        $primary = WcagColor::normalize($org->themeSettings?->primary_color);

        if ($primary === null) {
            return null;
        }

        return [
            'primary' => $primary,
            'on_primary' => WcagColor::onPrimary($primary),
            'primary_on_surface' => WcagColor::primaryOnSurface($primary),
            'band_text_large_only' => WcagColor::bandTextLargeOnly($primary),
        ];
    }
}
