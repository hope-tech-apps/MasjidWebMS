<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

/**
 * `GET /api/mobile/masjids/{id}/tv-config` — the display configuration the tvOS
 * signage board fetches.
 *
 * ## Why this exists: the app has always asked for an endpoint that was not there
 *
 * `MasjidTV/Data/SignageStore.swift` has called this route since the TV app was
 * written (`MasjidEndpoint.tvConfig`, "GET /mobile/masjids/{id}/tv-config"), and
 * `MasjidKit/Sources/MasjidKit/Models/TVConfig.swift` says so in its own header:
 * *"The endpoint does not exist on the backend yet."* The backend shipped
 * `GET /mobile/masjids/{id}/signage` instead, which is a DIFFERENT thing — an
 * ARRAY of broadcast slides, not this object — so the tv-config fetch 404s.
 *
 * Both ends then swallow it. `SignageStore.refreshTVConfig()` catches and keeps
 * `TVConfig.defaults`; the backend's `SignageChannel` reports `sent` because
 * publishing to a pull surface really is just a local state change. So an admin
 * sends a broadcast, the composer says it went out, and every board keeps
 * rendering whatever it was already rendering. Nothing anywhere says why.
 *
 * ## The shape is COPIED from the Swift decoder, not invented
 *
 * Every key, and the JSON type of every key, is taken from `TVConfig`'s
 * `CodingKeys` and property types. `is_enabled`, `carousel_interval_seconds`,
 * `show_prayer_panel`, `show_qr`, `donate_caption`, `announcement_selection` and
 * `theme` decode into NON-OPTIONAL Swift properties, so each must be present and
 * non-null with exactly the right type — a JSON string where an `Int` is
 * expected fails the whole decode and silently drops the board back to
 * defaults, which is the failure mode this endpoint exists to end. `header_title`,
 * `donate_url` and `announcement_ids` are Swift optionals and may be null.
 * Unknown keys are ignored by `Codable`, so this payload can grow later.
 *
 * ## Where the values come from
 *
 * There is no `tv_config` table and no admin screen for one yet. Rather than
 * invent storage, this serves the design's documented defaults RESOLVED against
 * data the backend actually holds, which is exactly what the client cannot do
 * for itself:
 *
 *  - `show_prayer_panel` follows the tenant's VERTICAL. A school or community
 *    org has no worship modules at all (config/verticals.php: "a school tenant
 *    never loads prayer/Qur'an/azkar"), so a prayer board on their lobby screen
 *    is wrong. This is the punch-list item docs/recon-2026-08-11.md records as
 *    "ship the tv-config endpoint and flip .defaults per org_type".
 *  - `donate_url` is the masjid's configured donation link, which is the
 *    resolution order `TVConfig` documents (`donate_url_override ?? donation_link.link`);
 *    there is no override to apply yet. `show_qr` follows it, so a tenant with
 *    no donation link gets a board with no dead QR slot instead of one the
 *    client has to suppress on its own.
 *  - `header_title` is an OVERRIDE and stays null, which is what makes the
 *    client fall back to `masjid.name` — returning the name here would work
 *    identically but would misreport an override as configured.
 *
 * Everything else is the documented default (§6.1/§10 of the TV design), stated
 * once, here, instead of only inside the app binary. When an admin surface for
 * this arrives it replaces the constants below and nothing else moves.
 *
 * ## Scoping and exposure
 *
 * Additive: `/signage` is untouched and keeps returning its array of slides.
 * Registered inside the same `mobile` prefix group as its neighbours, so it
 * carries `throttle:mobile` (60/min/IP) like every other public read.
 *
 * routes/api.php never runs the tenant middleware, so `TenantContext` is UNBOUND
 * and no global scope applies — as intended for a public route
 * (.claude/rules/tenant-scoping.md). Isolation is the explicit `masjid_id`
 * lookup below plus the per-masjid cache key, the same contract SignageController
 * and every other mobile controller honour. Nothing in the payload is
 * non-public: it is the same donation link and organisation type already served
 * by `/masjids/{id}`.
 */
class TvConfigController extends Controller
{
    /** Per-slide carousel dwell, in seconds (TVConfig.defaults). */
    private const CAROUSEL_INTERVAL_SECONDS = 10;

    /** Caption printed under the donate QR (TVConfig.defaults). */
    private const DONATE_CAPTION = 'Scan to Donate';

    /** all_active | tv_flagged | manual — see SignageStore.activeAnnouncements. */
    private const ANNOUNCEMENT_SELECTION = 'all_active';

    /** dark | light — the client compares this against "light" and nothing else. */
    private const THEME = 'dark';

    public function index($masjid_id)
    {
        $payload = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::TV_CONFIG),
            // The board re-polls this every ~3 minutes because "admin changes
            // propagate fast" (TVAppConfig.tvConfigRefresh), so the SHORT ttl is
            // the one that matches; a longer one would make the poll pointless.
            MobileCache::TTL_SHORT,
            function () use ($masjid_id) {
                $masjid = Masjid::with('donationLink')->findOrFail($masjid_id);

                $donateUrl = trim((string) ($masjid->donationLink->link ?? ''));
                $donateUrl = $donateUrl === '' ? null : $donateUrl;

                return [
                    // No pause switch exists yet. `false` here blanks a live
                    // lobby screen down to the paused board, so it is not
                    // something to default to on a guess.
                    'is_enabled' => true,
                    'header_title' => null,
                    'carousel_interval_seconds' => self::CAROUSEL_INTERVAL_SECONDS,
                    'show_prayer_panel' => $masjid->isMasjid(),
                    'show_qr' => $donateUrl !== null,
                    'donate_url' => $donateUrl,
                    'donate_caption' => self::DONATE_CAPTION,
                    'announcement_selection' => self::ANNOUNCEMENT_SELECTION,
                    // Only read when `announcement_selection` is "manual".
                    'announcement_ids' => null,
                    'theme' => self::THEME,
                ];
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $payload,
        ]);
    }
}
