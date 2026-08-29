<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\MobileCache;
use App\Support\MobileMedia;
use Illuminate\Support\Facades\Cache;

class MasjidsController extends Controller
{
    /**
     * The public organisation directory the apps open with.
     *
     * Gated on `masjids.listed_at` (Masjid::scopeListed): this used to return
     * EVERY row, so creating a tenant published it to every app user inside the
     * one-day cache window whether anyone was ready or not. An organisation is
     * offered here only once a SuperAdmin has listed it.
     *
     * Anything that changes listing state must flush MASJIDS_LIST — the cache
     * outlives the decision by a day otherwise.
     */
    public function index()
    {
        $masjids = Cache::remember(
            MobileCache::globalKey(MobileCache::MASJIDS_LIST),
            MobileCache::TTL_DAY,
            // makeHidden at the boundary, not $hidden on the model: these
            // columns are legitimately read by the admin surfaces, and this is
            // the one place the row meets an anonymous caller. See
            // Masjid::PUBLIC_DIRECTORY_DENYLIST for what was being published
            // and why email/phone stay.
            fn() => Masjid::listed()->with('logo')->get()
                ->makeHidden(Masjid::PUBLIC_DIRECTORY_DENYLIST)
        );

        return response()->json([
            'status' => 'success',
            'data' => $masjids
        ]);
    }

    public function show($masjid_id)
    {
        $masjid = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::SHOW),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                $masjid = Masjid::with(
                    'logo',
                    'header_logo',
                    'donationLink.image',
                    'masjidAbout.aboutImage',
                    'masjidAbout.missionIcon',
                    'masjidAbout.visionIcon',
                    'socialMediaLinks',
                    'themeSettings'
                )->findOrFail($masjid_id);

                // Same boundary rule as index() — an anonymous caller gets the
                // organisation's public identity, never its credentials.
                $masjid->makeHidden(Masjid::PUBLIC_DIRECTORY_DENYLIST);

                // Per-masjid color theme, baked into the cached payload so the apps
                // read it from the same SHOW structure they already fetch. Same
                // { primary, secondary, accent, background } shape as the web's
                // /api/v1/settings; null when no row → apps fall back to defaults.
                $masjid->setAttribute('theme', self::themePayload($masjid));

                // Masjid building/cover photo for the app brand header. Apps render
                // this remote image UNDER the primary-color tint when present, else
                // fall back to their bundled header. Uses the existing `header_logos`
                // media collection (managed from admin General Settings); null when
                // no image exists. String|null — backward-compatible additive field.
                $masjid->setAttribute('header_image_url', $masjid->header_logo->original_url ?? null);

                // Expose only the canonical keys, not the raw snake_case relations,
                // to keep the payload tidy.
                $masjid->makeHidden(['themeSettings', 'header_logo']);

                return $masjid;
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ]);
    }

    /**
     * Canonical theme shape shared with the web surface (Api/V1/SettingController).
     * Returns null when the masjid has no theme_settings row.
     *
     * @return array{primary: ?string, secondary: ?string, accent: ?string, background: ?string, tokens: array}|null
     */
    public static function themePayload(Masjid $masjid): ?array
    {
        $theme = $masjid->themeSettings;

        if (! $theme) {
            return null;
        }

        return [
            'primary' => $theme->primary_color,
            'secondary' => $theme->secondary_color,
            'accent' => $theme->accent_color,
            'background' => $theme->background_color,
            // Full resolved design-token tree — additive; the four keys above are
            // unchanged for older app builds. See App\Support\DesignTokens.
            'tokens' => $theme->resolvedTokens(),
        ];
    }

    public function gallery($masjid_id)
    {
        $gallery = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::GALLERY),
            MobileCache::TTL_MEDIUM,
            // A gallery item IS a media row, rendered by the app as
            // gallery[i].originalUrl! — a row with a null url would crash the
            // grid (the featuresIcons class). A gallery image with no file is
            // nothing to show, so drop it rather than serve a broken tile.
            fn() => Masjid::with('gallery')->findOrFail($masjid_id)->gallery
                ->filter(fn($media) => ! empty($media->original_url))
                ->values()
                ->toArray()
        );

        return response()->json([
            'status' => 'success',
            'data' => $gallery
        ]);
    }

    public function about($masjid_id)
    {
        // Wrap in [value => ...] because Cache::remember refuses to cache null —
        // a masjid with no MasjidAbout record would otherwise re-query every call.
        $wrapped = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::ABOUT),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                $about = Masjid::with(
                    'masjidAbout.aboutImage',
                    'masjidAbout.missionIcon',
                    'masjidAbout.visionIcon'
                )->findOrFail($masjid_id)->masjidAbout;

                if ($about === null) {
                    return ['value' => null];
                }

                // The About screen force-unwraps aboutImage/missionIcon/
                // visionIcon .originalUrl!; any one missing blanks the screen.
                // Guarantee each is a non-null envelope.
                $row = $about->toArray();
                $row['about_image'] = MobileMedia::envelope($about->aboutImage, MobileMedia::imagePlaceholderUrl());
                $row['mission_icon'] = MobileMedia::envelope($about->missionIcon, MobileMedia::imagePlaceholderUrl());
                $row['vision_icon'] = MobileMedia::envelope($about->visionIcon, MobileMedia::imagePlaceholderUrl());

                return ['value' => $row];
            },
        );

        return response()->json([
            'status' => 'success',
            'data' => $wrapped['value']
        ]);
    }

    public function donationLink($masjid_id)
    {
        // Same null-cache workaround as ::about above.
        $wrapped = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::DONATION_LINK),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                $link = Masjid::with('donationLink.image')->findOrFail($masjid_id)->donationLink;

                if ($link === null) {
                    return ['value' => null];
                }

                // The Donate screen force-unwraps image.originalUrl!; a link with
                // no banner would crash it. Guarantee a non-null envelope.
                $row = $link->toArray();
                $row['image'] = MobileMedia::envelope($link->image, MobileMedia::imagePlaceholderUrl());

                return ['value' => $row];
            },
        );

        return response()->json([
            'status' => 'success',
            'data' => $wrapped['value']
        ]);
    }
}
