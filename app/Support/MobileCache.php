<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Centralized cache key generation and invalidation for the public/mobile API.
 *
 * The mobile and /api/v1 endpoints are read-heavy and change infrequently. Each one
 * is wrapped in Cache::remember with a key produced here; the admin controllers that
 * mutate the same data call the matching flush method.
 *
 * Cache driver is `database` (see config/cache.php) so tags aren't available — we
 * use explicit keys and explicit forgets instead of tagged invalidation.
 */
class MobileCache
{
    // Per-masjid resources — change when an admin edits that masjid's data.
    public const SHOW = 'show';
    public const ABOUT = 'about';
    public const DONATION_LINK = 'donation_link';
    public const GALLERY = 'gallery';
    public const FEATURES = 'features';
    public const ANNOUNCEMENTS = 'announcements';
    public const EVENTS = 'events';
    public const SERVICES = 'services';
    public const PRAYERS_SETTINGS = 'prayers_settings';
    public const CONTACT_REASONS = 'contact_reasons';
    public const SPLASH = 'splash';
    public const APP_CONFIG = 'app.config';   // per-masjid emergency app-version gate

    // Global resources — change when an admin edits library content (azkar/hadith/tasabih).
    public const AZKAR_ALL = 'azkar.all';
    public const AZKAR_CATEGORIZED = 'azkar.categorized';
    public const AZKAR_BY_CATEGORY = 'azkar.category';
    public const AZKAR_CATEGORIES = 'azkar.categories';
    public const TASABIH_ALL = 'tasabih.all';
    public const HADITH_TODAY = 'hadith.today';
    public const HADITH_CATEGORIES = 'hadith.categories';
    public const MASJIDS_LIST = 'masjids.list';

    // TTLs (seconds). Tuned per how often content changes.
    public const TTL_SHORT = 300;       //  5 min — announcements, events
    public const TTL_MEDIUM = 600;      // 10 min — masjid info, services, features
    public const TTL_LONG = 3600;       //  1 hour — azkar/tasabih library, reasons
    public const TTL_DAY = 86400;       //  1 day — hadith of the day, masjids list

    /** Build a per-masjid cache key. */
    public static function masjidKey(int $masjidId, string $resource): string
    {
        return "mobile.masjid.{$masjidId}.{$resource}";
    }

    /** Build a global (non-masjid-scoped) cache key. */
    public static function globalKey(string $resource, $suffix = null): string
    {
        return $suffix !== null ? "mobile.{$resource}.{$suffix}" : "mobile.{$resource}";
    }

    public static function flushMasjid(int $masjidId, string $resource): void
    {
        Cache::forget(self::masjidKey($masjidId, $resource));
    }

    public static function flushGlobal(string $resource, $suffix = null): void
    {
        Cache::forget(self::globalKey($resource, $suffix));
    }

    /**
     * Invalidate every per-masjid key in one call. Use after broad updates
     * (e.g. updating masjid details that flow into multiple read endpoints).
     */
    public static function flushMasjidAll(int $masjidId): void
    {
        foreach ([
            self::SHOW, self::ABOUT, self::DONATION_LINK, self::GALLERY, self::FEATURES,
            self::ANNOUNCEMENTS, self::EVENTS, self::SERVICES, self::PRAYERS_SETTINGS,
            self::CONTACT_REASONS, self::SPLASH, self::APP_CONFIG,
        ] as $resource) {
            self::flushMasjid($masjidId, $resource);
        }
    }
}
