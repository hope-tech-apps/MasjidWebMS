<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\MobileCache;
use App\Support\MobileMedia;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class MasjidMobileAppFeaturesController extends Controller
{
    /**
     * Fallback icon file per feature, keyed by the feature's NORMALISED key
     * (lower-cased, non-alphanumerics stripped — so both `qur'an` and `quran`
     * resolve, and `adhkar` maps to the file it was actually shipped as). These
     * are the surviving SVGs under storage/app/public/icons that predate the
     * media table and were not deleted in the wipe; they let the drawer show the
     * RIGHT glyph even with the featuresIcons media rows gone. Anything not here
     * falls back to the generic placeholder rather than a null icon.
     */
    private const ICON_FALLBACKS = [
        'quran' => 'alqurann.svg',
        'hadith' => 'hadith.svg',
        'adhkar' => 'azkar.svg',
        'azkar' => 'azkar.svg',
        'qibla' => 'qibla.svg',
        'tasbih' => 'tasbih.svg',
        'donate' => 'donate.svg',
        'aboutus' => 'about_us.svg',
        'gallery' => 'gallery.svg',
        'services' => 'services.svg',
        'announcements' => 'announcements.svg',
        'contactus' => 'contact.svg',
    ];

    public function index($masjid_id)
    {
        $features = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::FEATURES),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                return Masjid::with('features.icon')->findOrFail($masjid_id)->features
                    ->map(function ($feature) {
                        $row = $feature->toArray();

                        // THE 2026-08-28 INCIDENT, MADE UNREPEATABLE FROM THE
                        // SERVER SIDE. The Flutter drawer force-unwraps
                        // feature.icon!.originalUrl!; a feature whose
                        // `featuresIcons` media row is missing serialises as
                        // icon:null and crashes the entire grid. Never emit a
                        // null icon: fall back to the surviving per-feature SVG,
                        // or the generic placeholder. A shipped client can no
                        // longer be handed the shape that blanks its drawer.
                        $fallback = MobileMedia::iconPlaceholderUrl(
                            self::ICON_FALLBACKS[self::normaliseKey($feature->key)] ?? null
                        );

                        $row['icon'] = MobileMedia::envelope($feature->icon, $fallback);

                        return $row;
                    })
                    ->values();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $features,
        ], Response::HTTP_OK);
    }

    private static function normaliseKey(?string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $key));
    }
}
