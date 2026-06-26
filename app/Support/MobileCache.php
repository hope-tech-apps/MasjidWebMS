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

    // Per-masjid Web V1 resources — served to the Nuxt website from app/Http/Controllers/Api/V1/*.
    // Distinct keys from the mobile resources above because the response SHAPE differs
    // (V1 uses JSON Resources / curated payloads; mobile returns raw Eloquent), but they
    // live in the same per-masjid namespace and are flushed together with the mobile keys
    // by the flush* helpers below so both surfaces stay consistent after an admin edit.
    public const V1_SETTINGS = 'v1.settings';
    public const V1_HOME = 'v1.home';
    public const V1_PAGES_LIST = 'v1.pages.list';
    public const V1_PAGES_MENU = 'v1.pages.menu';
    public const V1_PAGE_SHOW = 'v1.page';          // per-slug; suffix with the slug
    public const V1_SERVICES = 'v1.services';        // per query (per_page); suffix with a hash
    public const V1_ANNOUNCEMENTS = 'v1.announcements'; // per query; suffix with a hash
    public const V1_GALLERY = 'v1.gallery';          // per query; suffix with a hash
    public const V1_CONTACT_REASONS = 'v1.contact_reasons'; // global ContactUsReason dropdown

    // Global resources — change when an admin edits library content (azkar/hadith/tasabih).
    public const AZKAR_ALL = 'azkar.all';
    public const AZKAR_CATEGORIZED = 'azkar.categorized';
    public const AZKAR_BY_CATEGORY = 'azkar.category';
    public const AZKAR_CATEGORIES = 'azkar.categories';
    public const TASABIH_ALL = 'tasabih.all';
    public const HADITH_TODAY = 'hadith.today';
    public const HADITH_CATEGORIES = 'hadith.categories';
    public const MASJIDS_LIST = 'masjids.list';
    public const APP_CONFIG = 'app.config';   // global emergency app-version gate

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

    /**
     * Build a per-masjid cache key for a resource that has many query-param
     * variants (e.g. paginated V1 lists keyed by per_page / filter_active).
     *
     * The cache driver is `database` with no tag or wildcard support, so we
     * can't enumerate-and-forget every variant on invalidation. Instead the key
     * embeds a monotonically-increasing VERSION counter per (masjid, resource);
     * flushing simply bumps that counter, which orphans every previously-cached
     * variant at once (they expire on their own TTL).
     *
     * @param  string  $variant  Stable discriminator for the query (e.g. a hash of per_page+filters).
     */
    public static function masjidVariantKey(int $masjidId, string $resource, string $variant): string
    {
        $version = self::version($masjidId, $resource);

        return "mobile.masjid.{$masjidId}.{$resource}.v{$version}.{$variant}";
    }

    /** Current version number for a versioned per-masjid resource (defaults to 1). */
    public static function version(int $masjidId, string $resource): int
    {
        return (int) Cache::get(self::versionKey($masjidId, $resource), 1);
    }

    /** Internal: the key that stores a resource's version counter. */
    private static function versionKey(int $masjidId, string $resource): string
    {
        return "mobile.masjid.{$masjidId}.{$resource}.__ver";
    }

    /**
     * Invalidate every variant of a versioned per-masjid resource by bumping its
     * version counter. The old keys are left to expire on their TTL; new reads
     * miss and recompute.
     */
    public static function bumpVersion(int $masjidId, string $resource): void
    {
        $next = self::version($masjidId, $resource) + 1;
        // Version counter must outlive the data it gates — keep it effectively forever.
        Cache::put(self::versionKey($masjidId, $resource), $next, self::TTL_DAY * 30);
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
     * Invalidate every per-masjid key (mobile AND web V1) in one call. Use after
     * broad updates (e.g. updating masjid details that flow into multiple read
     * endpoints across both surfaces).
     */
    public static function flushMasjidAll(int $masjidId): void
    {
        // Mobile fixed-key resources.
        foreach ([
            self::SHOW, self::ABOUT, self::DONATION_LINK, self::GALLERY, self::FEATURES,
            self::ANNOUNCEMENTS, self::EVENTS, self::SERVICES, self::PRAYERS_SETTINGS,
            self::CONTACT_REASONS, self::SPLASH,
        ] as $resource) {
            self::flushMasjid($masjidId, $resource);
        }

        // Web V1 fixed-key resources.
        foreach ([
            self::V1_SETTINGS, self::V1_HOME, self::V1_PAGES_LIST, self::V1_PAGES_MENU,
        ] as $resource) {
            self::flushMasjid($masjidId, $resource);
        }

        // Web V1 versioned (multi-variant) resources.
        foreach ([
            self::V1_SERVICES, self::V1_ANNOUNCEMENTS, self::V1_GALLERY, self::V1_PAGE_SHOW,
            self::V1_CONTACT_REASONS,
        ] as $resource) {
            self::bumpVersion($masjidId, $resource);
        }
    }

    /* ------------------------------------------------------------------ *
     |  Unified per-area flushers                                          |
     |                                                                     |
     |  Each method invalidates BOTH the mobile and the web-V1 keys for a  |
     |  content area, so an admin controller fires ONE call and both       |
     |  surfaces reflect the edit on the next request. Keep these the      |
     |  single source of truth for "what to flush when X changes".         |
     * ------------------------------------------------------------------ */

    /**
     * About / Mission / Vision changed (masjid_abouts). Feeds /v1/home, /v1/settings,
     * mobile show+about, AND the about_us / mission_vision page-builder sections, which
     * SectionContentBinder binds to the MasjidAbout model at read time — so the cached
     * V1 pages must be busted too.
     */
    public static function flushAbout(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::ABOUT);
        self::flushMasjid($masjidId, self::SHOW);
        self::flushMasjid($masjidId, self::V1_SETTINGS);
        self::flushPages($masjidId); // covers V1_HOME + page list/menu/show
    }

    /**
     * Donation link changed (donation_links). Feeds mobile show+donation_link, the V1
     * /settings payload, AND the `donation` page-builder section, which
     * SectionContentBinder binds to the DonationLink model at read time — so the cached
     * V1 pages must be busted too. (There is no standalone V1 donation endpoint.)
     */
    public static function flushDonation(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::DONATION_LINK);
        self::flushMasjid($masjidId, self::SHOW);
        self::flushMasjid($masjidId, self::V1_SETTINGS);
        self::flushPages($masjidId); // donation section binds to DonationLink at read time
    }

    /** Services changed. Feeds /v1/services (list), /v1/home (latest 6), mobile services. */
    public static function flushServices(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::SERVICES);
        self::flushMasjid($masjidId, self::V1_HOME);
        self::bumpVersion($masjidId, self::V1_SERVICES);
    }

    /** Announcements changed. Feeds /v1/announcements (list), /v1/home (latest 3), mobile announcements. */
    public static function flushAnnouncements(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::ANNOUNCEMENTS);
        self::flushMasjid($masjidId, self::V1_HOME);
        self::bumpVersion($masjidId, self::V1_ANNOUNCEMENTS);
    }

    /** Gallery media changed. Feeds /v1/gallery (paginated), mobile gallery. */
    public static function flushGallery(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::GALLERY);
        self::bumpVersion($masjidId, self::V1_GALLERY);
    }

    /**
     * Contact reasons changed (per-masjid ContactReason). Feeds mobile /contact-reasons
     * AND the contact_form page-builder section, which SectionContentBinder::bindContact
     * injects the active reason list into at read time — so bust cached V1 pages too.
     */
    public static function flushContactReasons(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::CONTACT_REASONS);
        self::flushPages($masjidId); // contact_form section embeds the reason list
    }

    /**
     * Page / Section / placement changed (page-builder). Feeds every V1 page read:
     * /v1/pages, /v1/pages/menu, /v1/pages/{slug}, and /v1/home (about_us block can
     * be sourced from a builder section). Scoped to a single masjid.
     */
    public static function flushPages(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::V1_PAGES_LIST);
        self::flushMasjid($masjidId, self::V1_PAGES_MENU);
        self::flushMasjid($masjidId, self::V1_HOME);
        self::bumpVersion($masjidId, self::V1_PAGE_SHOW);
    }

    /** Masjid settings / branding / prayer-calc / features changed. Feeds /v1/settings + mobile. */
    public static function flushSettings(int $masjidId): void
    {
        self::flushMasjid($masjidId, self::V1_SETTINGS);
        self::flushMasjid($masjidId, self::SHOW);
    }
}
