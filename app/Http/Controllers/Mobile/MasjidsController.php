<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

class MasjidsController extends Controller
{
    public function index()
    {
        $masjids = Cache::remember(
            MobileCache::globalKey(MobileCache::MASJIDS_LIST),
            MobileCache::TTL_DAY,
            fn() => Masjid::with('logo')->get()
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
                    'donationLink.image',
                    'masjidAbout.aboutImage',
                    'masjidAbout.missionIcon',
                    'masjidAbout.visionIcon',
                    'socialMediaLinks',
                    'themeSettings'
                )->findOrFail($masjid_id);

                // Per-masjid color theme, baked into the cached payload so the apps
                // read it from the same SHOW structure they already fetch. Same
                // { primary, secondary, accent, background } shape as the web's
                // /api/v1/settings; null when no row → apps fall back to defaults.
                $masjid->setAttribute('theme', self::themePayload($masjid));

                // Expose only the canonical `theme` key, not the raw snake_case
                // theme_settings relation, to keep the payload tidy.
                $masjid->makeHidden('themeSettings');

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
     * @return array{primary: ?string, secondary: ?string, accent: ?string, background: ?string}|null
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
        ];
    }

    public function gallery($masjid_id)
    {
        $gallery = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::GALLERY),
            MobileCache::TTL_MEDIUM,
            fn() => Masjid::with('gallery')->findOrFail($masjid_id)->gallery->toArray()
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
            fn() => ['value' => Masjid::with(
                'masjidAbout.aboutImage',
                'masjidAbout.missionIcon',
                'masjidAbout.visionIcon'
            )->findOrFail($masjid_id)->masjidAbout],
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
            fn() => ['value' => Masjid::with('donationLink.image')->findOrFail($masjid_id)->donationLink],
        );

        return response()->json([
            'status' => 'success',
            'data' => $wrapped['value']
        ]);
    }
}
