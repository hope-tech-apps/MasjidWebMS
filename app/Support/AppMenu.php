<?php

namespace App\Support;

use App\Models\AppMenuSetting;
use App\Models\Masjid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The mobile app's side menu, derived from one organisation's switches.
 *
 * Today a feature row in the app is controlled in one place (the Mobile App
 * Features pivot) and the SCREEN behind it in another (the per-organisation
 * switches). The two already disagree on production. This class is the single
 * derivation that replaces the pair: an entry is in the menu when the switch
 * behind it is on, and the tab bar is the subset of those entries the bar can
 * carry.
 *
 * What it answers, all from config/app_menu.php (§3.4 of the server plan):
 *
 *   visibleItems()       the ordered entries one organisation's menu lists
 *   sections()           those entries grouped, empty sections dropped
 *   tabs()               the bar for one organisation, `home` first, capped
 *   payload()            the whole `/menu` body for a home organisation
 *   legacyAvailability() the same question asked in legacy /features terms
 *
 * Three rules hold the whole thing up:
 *
 *  1. **Visibility is switch-only.** Never "and the donation link has a URL",
 *     never "and Stripe is onboarded". The app's fallback menu is built from
 *     the legacy /features when /menu is killed, and it cannot know those
 *     things — a kill switch is only worth having if what it falls back to is
 *     the same menu.
 *  2. **It fails open.** Masjid::moduleIsOff() answers a key the loaded
 *     capability config has not caught up with by its org type's default, so a
 *     stale config cache during a deploy can only ever SHOW an entry.
 *  3. **No labels, no icons, no per-user data.** Labels and icons are the
 *     clients'; the body is identical with and without an Authorization
 *     header, which is what makes it cacheable and ETag-able.
 *
 * The one documented asymmetry against the legacy list: `/menu` shows
 * Announcements when only Events is on, because the drawer entry opens both.
 * Legacy id 10 means Announcements alone. See legacyAvailability().
 */
class AppMenu
{
    /**
     * Where the kill switch's answer is remembered. 60 s: short enough that
     * `app-menu:kill` is felt while the operator is still watching, long enough
     * that the menu does not query for it on every request in a crowd.
     */
    public const KILL_CACHE_KEY = 'mobile.app_menu.kill';

    public const KILL_CACHE_TTL = 60;

    /**
     * A verbatim copy of config/app_menu.php's registry keys.
     *
     * Held in code for the reason Masjid::MODULE_KEYS is: bin/deploy merges,
     * installs and migrates BEFORE it rebuilds the config cache, so for a
     * stretch of every deploy the new PHP reads the previous cache. A menu that
     * lost its Worship section for ninety seconds because of that would look
     * exactly like a bug in the switches.
     *
     * registry() validates the config and returns THIS when it does not hold
     * up. AppMenuRegistryMirrorTest pins the two together.
     */
    public const DEFAULT_REGISTRY = [
        'schema_version' => 1,
        'max_tabs' => 4,
        'sections' => [
            'main' => ['home', 'announcements', 'services', 'donate'],
            'worship' => ['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'],
            'about' => ['about_us', 'gallery', 'contact'],
        ],
        'tabs' => ['home', 'announcements', 'contact', 'donate'],
        'items' => [
            'home' => ['legacy_feature_id' => null, 'always' => true],
            'announcements' => ['legacy_feature_id' => 10, 'any_of' => ['announcements', 'events'], 'parts' => ['announcements', 'events']],
            'services' => ['legacy_feature_id' => 9, 'any_of' => ['services']],
            'donate' => ['legacy_feature_id' => 6, 'any_of' => ['donation_link', 'giving'], 'parts' => ['donation_link', 'giving']],
            'quran' => ['legacy_feature_id' => 1, 'any_of' => ['quran']],
            'hadith' => ['legacy_feature_id' => 2, 'any_of' => ['hadith']],
            'adhkar' => ['legacy_feature_id' => 3, 'any_of' => ['adhkar']],
            'qibla' => ['legacy_feature_id' => 4, 'any_of' => ['qibla']],
            'tasbih' => ['legacy_feature_id' => 5, 'any_of' => ['tasbih']],
            'about_us' => ['legacy_feature_id' => 7, 'any_of' => ['about_us']],
            'gallery' => ['legacy_feature_id' => 8, 'any_of' => ['gallery']],
            'contact' => ['legacy_feature_id' => 11, 'any_of' => ['contact_requests']],
        ],
    ];

    /** The registry keys read out of config; anything else in the file is ignored here. */
    private const REGISTRY_KEYS = ['schema_version', 'max_tabs', 'sections', 'tabs', 'items'];

    /**
     * The answer for one exact config value, so a single request validates
     * once and logs at most one warning. Keyed by a fingerprint OF THE CONFIG,
     * not by "have we been here before": editing config('app_menu') mid-process
     * — which is what a test does — invalidates it automatically, so this can
     * never be the reason a stale registry outlives the value that produced it.
     *
     * @var array{key: string, registry: array<string, mixed>}|null
     */
    private static ?array $memo = null;

    /**
     * The validated registry, or the code copy.
     *
     * Validation is not defensive programming for its own sake — it is the
     * condition under which a config cache is allowed to decide what a menu
     * contains. Anything it cannot vouch for reads as a stale or hand-edited
     * file and gets one warning and the code copy.
     */
    public static function registry(): array
    {
        $config = config('app_menu');

        // Read in a FIXED key order, so the shape this returns never depends on
        // the order someone happened to write the config file in.
        $candidate = [];

        foreach (self::REGISTRY_KEYS as $key) {
            if (is_array($config) && array_key_exists($key, $config)) {
                $candidate[$key] = $config[$key];
            }
        }

        $fingerprint = md5(serialize($candidate));

        if (self::$memo !== null && self::$memo['key'] === $fingerprint) {
            return self::$memo['registry'];
        }

        $problem = self::validate($candidate);

        if ($problem !== null) {
            Log::warning('app_menu registry rejected; using the code copy', ['problem' => $problem]);
            $candidate = self::DEFAULT_REGISTRY;
        }

        self::$memo = ['key' => $fingerprint, 'registry' => $candidate];

        return $candidate;
    }

    /**
     * Drop the memo.
     *
     * Only tests need this, and only the ones that assert on the WARNING: the
     * fingerprint already handles a changed config, but two tests in one
     * process that install the SAME broken config would otherwise see the
     * warning once between them.
     */
    public static function forgetRegistry(): void
    {
        self::$memo = null;
    }

    public static function schemaVersion(): int
    {
        return (int) self::registry()['schema_version'];
    }

    /**
     * Why the registry cannot be trusted, or null when it can.
     *
     * @param array<string, mixed> $registry
     */
    private static function validate(array $registry): ?string
    {
        foreach (self::REGISTRY_KEYS as $key) {
            if (! array_key_exists($key, $registry)) {
                return "missing key: {$key}";
            }
        }

        if (! is_int($registry['schema_version']) || $registry['schema_version'] < 1) {
            return 'schema_version must be a positive integer';
        }

        if (! is_int($registry['max_tabs']) || $registry['max_tabs'] < 1) {
            return 'max_tabs must be a positive integer';
        }

        if (! is_array($registry['sections']) || $registry['sections'] === []) {
            return 'sections must be a non-empty array';
        }

        if (! is_array($registry['items']) || $registry['items'] === []) {
            return 'items must be a non-empty array';
        }

        if (! is_array($registry['tabs']) || $registry['tabs'] === []) {
            return 'tabs must be a non-empty array';
        }

        $placed = [];

        foreach ($registry['sections'] as $section => $keys) {
            if (! is_string($section) || ! is_array($keys) || $keys === []) {
                return 'each section must be a named, non-empty list of item keys';
            }

            foreach ($keys as $key) {
                if (! is_string($key) || ! isset($registry['items'][$key])) {
                    return "section {$section} names an unknown item";
                }

                if (isset($placed[$key])) {
                    return "item {$key} appears in more than one section";
                }

                $placed[$key] = $section;
            }
        }

        $legacyIds = [];

        foreach ($registry['items'] as $key => $item) {
            if (! is_string($key) || ! is_array($item)) {
                return 'each item must be a named array';
            }

            if (! isset($placed[$key])) {
                return "item {$key} is in no section";
            }

            if (! array_key_exists('legacy_feature_id', $item)) {
                return "item {$key} has no legacy_feature_id";
            }

            if ($item['legacy_feature_id'] !== null) {
                if (! is_int($item['legacy_feature_id'])) {
                    return "item {$key} has a non-integer legacy_feature_id";
                }

                if (in_array($item['legacy_feature_id'], $legacyIds, true)) {
                    return "legacy_feature_id {$item['legacy_feature_id']} is claimed twice";
                }

                $legacyIds[] = $item['legacy_feature_id'];
            }

            $always = ($item['always'] ?? false) === true;
            $anyOf = $item['any_of'] ?? [];

            if (! $always && (! is_array($anyOf) || $anyOf === [])) {
                return "item {$key} names no switch and is not always on";
            }

            foreach (is_array($anyOf) ? $anyOf : [] as $moduleKey) {
                if (! in_array($moduleKey, Masjid::MODULE_KEYS, true)) {
                    return "item {$key} names an unknown module: " . var_export($moduleKey, true);
                }
            }

            if (array_key_exists('parts', $item)) {
                if (! is_array($item['parts']) || $item['parts'] === []) {
                    return "item {$key} declares parts that are not a non-empty list";
                }

                foreach ($item['parts'] as $part) {
                    if (! in_array($part, Masjid::MODULE_KEYS, true)) {
                        return "item {$key} names an unknown part: " . var_export($part, true);
                    }
                }
            }
        }

        sort($legacyIds);

        if ($legacyIds !== range(1, 11)) {
            return 'the eleven legacy feature ids must each appear exactly once';
        }

        if (($registry['tabs'][0] ?? null) !== 'home') {
            return 'home must be the first tab';
        }

        foreach ($registry['tabs'] as $key) {
            if (! is_string($key) || ! isset($placed[$key])) {
                return 'every tab must also be an item in a section';
            }
        }

        if (count($registry['tabs']) !== count(array_unique($registry['tabs']))) {
            return 'a tab is listed twice';
        }

        return null;
    }

    /**
     * Has an operator taken the menu away from every phone?
     *
     * Set by `app-menu:kill`, cleared by `app-menu:restore`. While it is set,
     * /menu answers 404 and both clients fall back to the menu they build from
     * the legacy /features — which is why the derivation is switch-only, so the
     * two menus are the same menu.
     *
     * FAILS OPEN, deliberately and in every direction: no table (the code is
     * deployed, the migration has not run), no row, an unreadable cache store,
     * a database blip — all of it reads as NOT killed. The failure this guards
     * against is the whole fleet losing its menu because a support table was
     * briefly unavailable; the failure it accepts is a kill switch that takes a
     * few seconds longer to bite, which an operator is watching for anyway.
     */
    public static function killed(): bool
    {
        try {
            return (bool) Cache::remember(
                self::KILL_CACHE_KEY,
                self::KILL_CACHE_TTL,
                fn () => (bool) AppMenuSetting::query()->orderBy('id')->value('menu_disabled')
            );
        } catch (Throwable $e) {
            Log::warning('app menu kill switch unreadable; treating the menu as live', [
                'exception' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * The entries this organisation's menu lists, in registry order.
     *
     * Flat, each carrying the section it rides, so callers that want the
     * payload shape (sections()) and callers that want a lookup
     * (legacyAvailability()) read one derivation.
     *
     * @return array<int, array{key: string, section: string, legacy_feature_id: int|null, parts: array<string, bool>|null}>
     */
    public static function visibleItems(Masjid $org): array
    {
        $registry = self::registry();
        $out = [];

        foreach ($registry['sections'] as $section => $keys) {
            foreach ($keys as $key) {
                $item = $registry['items'][$key] ?? null;

                if ($item === null || ! self::itemIsVisible($org, $item)) {
                    continue;
                }

                $out[] = [
                    'key' => $key,
                    'section' => $section,
                    'legacy_feature_id' => $item['legacy_feature_id'],
                    'parts' => self::partsFor($org, $item),
                ];
            }
        }

        return $out;
    }

    /**
     * The payload's `sections`: registry order, empty sections omitted.
     *
     * An item key the client does not know is dropped by the client, and an
     * item key the server does not know cannot exist — the registry is the
     * only source of both ends.
     *
     * @return array<int, array{key: string, items: array<int, array<string, mixed>>}>
     */
    public static function sections(Masjid $org): array
    {
        $grouped = [];

        foreach (self::visibleItems($org) as $item) {
            $row = [
                'key' => $item['key'],
                'legacy_feature_id' => $item['legacy_feature_id'],
            ];

            if ($item['parts'] !== null) {
                $row['parts'] = $item['parts'];
            }

            $grouped[$item['section']][] = $row;
        }

        $out = [];

        foreach (array_keys(self::registry()['sections']) as $section) {
            if (! empty($grouped[$section])) {
                $out[] = ['key' => $section, 'items' => $grouped[$section]];
            }
        }

        return $out;
    }

    /**
     * The hybrid tab bar for one organisation: `home` first, then the eligible
     * entries that are visible, in BAR order (which is not menu order), capped
     * at max_tabs.
     *
     * The client appends its own "Menu" tab, always last. "Menu" is never
     * server-controlled — a payload that could omit it would make the menu
     * unreachable.
     *
     * @return array<int, string>
     */
    public static function tabs(Masjid $org): array
    {
        $registry = self::registry();
        $visible = array_column(self::visibleItems($org), 'key');

        $tabs = array_values(array_filter(
            $registry['tabs'],
            fn (string $key) => in_array($key, $visible, true)
        ));

        return array_slice($tabs, 0, (int) $registry['max_tabs']);
    }

    /**
     * Is the legacy Mobile App Features row with this id available to this
     * organisation, judged by the switches?
     *
     * The bridge S2's derived /features reads. It is NOT simply "is the menu
     * entry visible": legacy id 10 is Announcements ALONE, while the menu's
     * `announcements` entry also opens Events and so appears when only Events
     * is on. That single asymmetry is documented in the plan (§3.4) and is the
     * one exception the client-side parity check allows.
     *
     * An id the registry does not know fails OPEN — an installed build asking
     * about a row nobody has heard of gets the row, not a blank screen.
     */
    public static function legacyAvailability(Masjid $org, int $legacyId): bool
    {
        if ($legacyId === 10) {
            return ! $org->moduleIsOff('announcements');
        }

        foreach (self::registry()['items'] as $item) {
            if (($item['legacy_feature_id'] ?? null) === $legacyId) {
                return self::itemIsVisible($org, $item);
            }
        }

        return true;
    }

    /**
     * The whole `/menu` body for one home organisation.
     *
     * Build order is fixed, which is what makes `hash` deterministic and the
     * ETag worth anything. `hash` is a sha1 over everything else in `data`, so
     * it covers home_id too and one organisation's tag can never satisfy
     * another's conditional request.
     *
     * @return array<string, mixed>
     */
    public static function payload(Masjid $home): array
    {
        $profiles = AppOrgs::forHome($home)
            ->map(fn (Masjid $org) => self::profile($org, $home))
            ->values()
            ->all();

        $data = [
            'schema_version' => self::schemaVersion(),
            'home_id' => (int) $home->id,
            'account' => [
                // Derived, never the column: `crm_enabled` stays out of public
                // payloads (it is on Masjid::PUBLIC_DIRECTORY_DENYLIST). The
                // same state is already observable — auth/request-code 403s
                // under the crm gate — so this leaks nothing new; it tells the
                // app whether to draw a Sign in row at all.
                'sign_in_available' => (bool) $home->crm_enabled,
                // The live public page (S1a). It replaces the string both apps
                // compiled in, so the address can move without a release.
                'deletion_page_url' => url('/account-deletion'),
            ],
            'profiles' => $profiles,
        ];

        // JSON_THROW_ON_ERROR on purpose: a name that will not encode must
        // become a 503 the client falls back from, never a silently wrong ETag
        // that pins a broken body in every phone's cache.
        $hash = sha1(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'schema_version' => $data['schema_version'],
            'hash' => $hash,
            'home_id' => $data['home_id'],
            'account' => $data['account'],
            'profiles' => $data['profiles'],
        ];
    }

    /**
     * One profile: the nine keys, in this order.
     *
     * @return array<string, mixed>
     */
    public static function profile(Masjid $org, Masjid $home): array
    {
        return [
            'id' => (int) $org->id,
            'name' => $org->name,
            'org_type' => $org->orgType(),
            'is_home' => (int) $org->id === (int) $home->id,
            'logo_url' => $org->logo?->original_url,
            'theme' => self::theme($org),
            'home' => [
                // The org-type floor never lifts: a school with prayer_times
                // switched on still shows no prayer table, because it has no
                // iqama times to show and inventing them is worse than the
                // switch reading as optimistic (owner, decision 4).
                'prayer_times' => $org->isMasjid() && ! $org->moduleIsOff('prayer_times'),
            ],
            'tabs' => self::tabs($org),
            'sections' => self::sections($org),
        ];
    }

    /**
     * The four theme keys, or null when this organisation has no usable brand
     * colour. All four or none — a client that got `primary` without
     * `on_primary` would be back to deriving contrast on the phone.
     *
     * @return array{primary: string, on_primary: string, primary_on_surface: string, band_text_large_only: bool}|null
     */
    private static function theme(Masjid $org): ?array
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

    /** @param array<string, mixed> $item */
    private static function itemIsVisible(Masjid $org, array $item): bool
    {
        if (($item['always'] ?? false) === true) {
            return true;
        }

        foreach ($item['any_of'] ?? [] as $moduleKey) {
            if (! $org->moduleIsOff($moduleKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The two halves of a two-part entry, ALWAYS both booleans when the item
     * declares them.
     *
     * Reserved in R1: the apps ship parts-unaware, because the fallback menu
     * cannot know them and consuming them would make the /menu drawer differ
     * from the fallback drawer (plan v3, OQ-1).
     *
     * @param array<string, mixed> $item
     * @return array<string, bool>|null
     */
    private static function partsFor(Masjid $org, array $item): ?array
    {
        if (empty($item['parts'])) {
            return null;
        }

        $out = [];

        foreach ($item['parts'] as $part) {
            $out[$part] = ! $org->moduleIsOff($part);
        }

        return $out;
    }
}
