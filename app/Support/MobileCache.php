<?php

namespace App\Support;

use App\Models\Masjid;
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
    /**
     * The organisations an app may switch into: this one plus its listed
     * children.
     *
     * `orgs.v2` and not `orgs`, because S1 CHANGED THE SHAPE of these rows —
     * each one gained `theme`. bin/deploy runs migrate and re-caches config and
     * routes; it does not run `cache:clear`, and this store is the database, so
     * entries written by the pre-S1 code survive the deploy and would be served
     * as five-key rows with `theme` missing entirely for up to ten minutes
     * after it. The contract says `theme` is null, never absent, and a client
     * that took that at its word — an iOS `Org` with a non-optional `theme` —
     * would fail to decode the whole array and show an EMPTY organisation
     * switcher during exactly the window the team is watching the deploy. It
     * would read as "the deploy broke the switcher", and no server test could
     * reproduce it: every test starts with a cold cache.
     *
     * Renaming the key retires every old entry at the instant the new code goes
     * live, with no deploy step to remember and nothing to flush. The old
     * `orgs` entries are then unreachable and expire on their own TTL.
     *
     * A future change to this row's shape should bump it again rather than add
     * a deploy step.
     */
    public const ORGS = 'orgs.v2';
    /**
     * The app's side menu for this organisation and its listed children — one
     * derived view of their switches. Stored as ['hash' => …, 'data' => …]: the
     * hash is the ETag, so it has to survive the cache round trip or every
     * launch re-downloads a menu that did not change.
     *
     * A parent's MENU depends on its CHILDREN's switches, which is why flushing
     * it walks the family (MobileCache::flushFamily, S1.4) rather than just
     * forgetting the one organisation that was edited.
     */
    public const MENU = 'menu';

    public const APP_CONFIG = 'app.config';   // per-masjid emergency app-version gate
    // Signage board payload (tvOS). Written by the unified composer's signage
    // channel, read by GET /mobile/masjids/{id}/signage. See T-008.
    public const SIGNAGE = 'signage';
    // tvOS display configuration — GET /mobile/masjids/{id}/tv-config. A
    // SEPARATE key from SIGNAGE on purpose: the two endpoints answer different
    // questions (what to show vs. how to show it), refresh on different cadences
    // in the client, and are invalidated by different admin actions. Publishing
    // a broadcast must not blow away the config, and vice versa.
    public const TV_CONFIG = 'tv_config';

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
     * How far up the parent chain a family flush will walk.
     *
     * The same 32 as Masjid::setParent's cycle guard, and for the same reason:
     * a tree that is already corrupt must not be able to hang the request that
     * is trying to fix it. Real families are two deep.
     */
    public const FAMILY_HOPS = 32;

    /**
     * Invalidate every per-masjid key in one call. Use after broad updates
     * (e.g. updating masjid details that flow into multiple read endpoints).
     *
     * This is the ONE organisation's own keys. It does not reach its parent,
     * which is what flushFamily() is for — an organisation's name, logo and
     * switches all appear inside its PARENT's /orgs and /menu, so a caller that
     * changed any of those needs both.
     */
    public static function flushMasjidAll(int $masjidId): void
    {
        foreach ([
            self::SHOW, self::ABOUT, self::DONATION_LINK, self::GALLERY, self::FEATURES,
            self::ANNOUNCEMENTS, self::EVENTS, self::SERVICES, self::PRAYERS_SETTINGS,
            self::CONTACT_REASONS, self::SPLASH, self::APP_CONFIG, self::SIGNAGE,
            self::TV_CONFIG,
            // ORGS was missing from this list, which is how listing a child left
            // the parent's switcher stale for a full ten minutes. MENU has the
            // same shape and is added with it.
            self::ORGS, self::MENU,
        ] as $resource) {
            self::flushMasjid($masjidId, $resource);
        }
    }

    /**
     * Forget everything an edit to ONE organisation can have changed — its own
     * payloads AND every ancestor's list of it.
     *
     * This exists because two endpoints answer about a family rather than about
     * an organisation. A parent's `/orgs` carries each published child's name,
     * type, logo and now its brand colour; a parent's `/menu` carries a whole
     * profile per child, derived from that child's switches. So editing a CHILD
     * changes a payload cached under the PARENT's id, and forgetting only the
     * child's own keys leaves the parent serving the old answer — with an ETag
     * that makes every phone keep it — until the ten-minute entry expires.
     *
     * That was already happening before the menu existed: publishing a child
     * flushed the global directory and nothing else, so the child appeared in
     * the app's organisation list immediately and in its parent's switcher ten
     * minutes later.
     *
     * What it forgets, and what it deliberately does not:
     *
     *   this org    FEATURES, MENU, ORGS, SHOW — the four a switch, a rename, a
     *               logo or a brand colour can move.
     *   ancestors   MENU and ORGS only. An ancestor's own SHOW and FEATURES are
     *               about ITSELF and cannot have changed.
     *   never       `features.lastgood`. That key is the legacy /features
     *               contract's last line of defence (S2 §7.4) — the answer it
     *               falls back to when the derivation and the pivot have both
     *               failed. A cache flush is exactly the moment it is most
     *               needed, so nothing here may forget it. Note that it is a
     *               DIFFERENT key from FEATURES: this method names keys one at
     *               a time and never matches a prefix.
     */
    public static function flushFamily(Masjid $org): void
    {
        self::flushOwnPayloads((int) $org->id);
        self::flushAncestors($org->parent_id !== null ? (int) $org->parent_id : null);
    }

    /**
     * The same flush for a caller that holds only an id — a migration, or the
     * organisation an edit just moved AWAY from, whose model nobody loaded.
     *
     * Costs one extra query (the parent lookup) over flushFamily. Prefer the
     * model form when you have one.
     */
    public static function flushFamilyById(int $masjidId): void
    {
        self::flushOwnPayloads($masjidId);
        self::flushAncestors(self::parentIdOf($masjidId));
    }

    /** The four keys an edit to this organisation can have changed. */
    private static function flushOwnPayloads(int $masjidId): void
    {
        foreach ([self::FEATURES, self::MENU, self::ORGS, self::SHOW] as $resource) {
            self::flushMasjid($masjidId, $resource);
        }
    }

    /**
     * Walk up, forgetting each ancestor's list of its family.
     *
     * Bounded twice over — a hop cap AND a seen-set — because this runs on the
     * write path of every switch flip. A cycle in `parent_id` should be
     * impossible (setParent refuses to create one), but "impossible" is how a
     * data repair script that writes the column directly would be described
     * right up until an admin saving a phone number hangs the box.
     */
    private static function flushAncestors(?int $parentId): void
    {
        $seen = [];
        $cursor = $parentId;
        $hops = 0;

        while ($cursor !== null && $hops++ < self::FAMILY_HOPS) {
            if (isset($seen[$cursor])) {
                break;
            }

            $seen[$cursor] = true;

            self::flushMasjid($cursor, self::MENU);
            self::flushMasjid($cursor, self::ORGS);

            $cursor = self::parentIdOf($cursor);
        }
    }

    /**
     * withTrashed on purpose: a trashed ancestor still has cached payloads, and
     * a family that is mid-archive is exactly when a stale one is confusing.
     */
    private static function parentIdOf(int $masjidId): ?int
    {
        $parentId = Masjid::withTrashed()->whereKey($masjidId)->value('parent_id');

        return $parentId !== null ? (int) $parentId : null;
    }
}
