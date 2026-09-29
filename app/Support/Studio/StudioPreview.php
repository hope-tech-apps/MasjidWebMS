<?php

namespace App\Support\Studio;

use App\Http\Controllers\Mobile\TvConfigController;
use App\Models\Masjid;
use App\Models\StudioDraft;
use App\Models\ThemeSetting;
use App\Support\AppFeaturePivot;
use App\Support\AppMenu;
use App\Support\DesignTokens;
use App\Support\WcagColor;

/**
 * Everything Studio's Step 2 shows about a draft, derived on the server in one
 * place (docs/manara-studio-w1.md S4, R16, R19): the palette gate, the web
 * colours, the per-platform contrast rows, the app tab bars and menu, the web
 * starter plan and the tvOS board.
 *
 * One derivation, so the gate, the mockups and S8's writer cannot disagree.
 * Each part is computed by the code that serves the real thing:
 *
 *  - the palette is PaletteContrast::report(), the gate Step 3 enforces;
 *  - `web_tokens` is DesignTokens::resolve() over the colours plus the tokens
 *    S8 writes (the auto-ink and the preset's header and footer);
 *  - the iOS tabs and sections are AppMenu's, which /menu serves; while the
 *    /menu kill row is set they are what the phone builds from /features
 *    instead (iosTabs, iosSections);
 *  - the Android tabs: for a draft what AppFeaturePivot::rowsFor() derives,
 *    which S8 seeds and /features serves; for a live organisation the bar the
 *    Play production build draws (androidTabs);
 *  - the web plan is StarterSite::plan(), which S8 writes;
 *  - the tvOS values are TvConfigController's own constants.
 *
 * The organisation is an UNSAVED Masjid built from the answers, or, for an
 * organisation that already exists (Studio W2 S9), an in-memory clone of it
 * with the switches being considered (PreviewInput). Every switch reader
 * (Masjid::hasCapability, moduleIsOff, AppMenu) reads attributes, not rows, so
 * it answers exactly as the saved org will. Nothing is written: not the draft,
 * not an organisation, not a theme.
 *
 * `platform_contrast` is ADVISORY (R16). The iOS home header is hard-coded
 * white and Android's selected tab is hard-coded green, and no stored token can
 * change either, so a failing row there informs the operator and never blocks.
 * Only the palette's blocking pairs gate Step 3.
 *
 * Until all four brand colours are chosen, `palette`, `web_tokens` and
 * `platform_contrast` are null: DesignTokens fills a missing colour with
 * Burlington's green (R25), and grading or painting that would show a live
 * client's brand, not this one's. That is a draft's rule. A live organisation
 * already draws in some palette, so its missing colour is filled the way the
 * renderer fills it (PreviewInput::liveColours) and its report is never null.
 */
final class StudioPreview
{
    /** iOS home header text, hard-coded (ios: Masjid/Views/Main/Home/HomeView.swift:174). */
    public const IOS_HOME_HEADER_INK = '#FFFFFF';

    /** Android's selected tab icon, hard-coded (android: ui/views/bottomBar/BottomBar.kt:38-100). */
    public const ANDROID_SELECTED_TAB = '#00AA55';

    /** The tvOS board's header: white on its fixed dark background (MasjidTV). */
    public const TVOS_HEADER_INK = '#FFFFFF';

    public const TVOS_BACKGROUND = '#0F0F0F';

    /** WCAG large-text threshold (1.4.3). */
    public const AA_LARGE = 3.0;

    /**
     * Android's tab bar after Home: legacy feature id => tab, in bar order
     * (News, Contact, Donate; android: ui/shell/BottomTabs.kt).
     */
    public const ANDROID_TABS = [10 => 'announcements', 11 => 'contact', 6 => 'donate'];

    /**
     * A draft's preview (W1): unchanged, now by way of PreviewInput::fromDraft.
     *
     * @param  array<string, array<string, mixed>|null>  $answerOverrides  whole sections that replace the
     *                                                                     saved ones for this preview only; null clears one
     * @return array<string, mixed>
     */
    public static function build(StudioDraft $draft, array $answerOverrides = []): array
    {
        return self::forInput(PreviewInput::fromDraft($draft, $answerOverrides));
    }

    /**
     * The preview of whatever the input describes: a draft, or an existing
     * organisation with the changes being considered (Studio W2 S9). Writes
     * nothing.
     *
     * @return array<string, mixed>
     */
    public static function forInput(PreviewInput $in): array
    {
        $org = $in->org;
        $palette = null;
        $webTokens = null;

        if ($in->colours !== null) {
            $palette = PaletteContrast::report($in->colours, $in->inks, $in->logoDims);

            // A draft is painted with the inks Step 3 will write; an existing
            // organisation with the tokens its theme already stores, which is
            // what the renderer will use once the colours are saved.
            $tokens = $in->paintsPaletteInks
                ? ['color' => $palette['tokens']['color']] + $in->storedTokens
                : $in->storedTokens;

            $webTokens = DesignTokens::resolve(new ThemeSetting($in->colours + ['tokens' => $tokens]))['color'];
        }

        return [
            'org' => [
                'name' => $org->name,
                'org_type' => $in->orgType,
                'host' => $in->host,
            ],
            'platforms' => $in->platforms,
            'palette' => $palette,
            'web_tokens' => $webTokens,
            'platform_contrast' => $in->colours === null ? null : self::platformContrast($in->colours['primary_color'], $webTokens),
            'app' => [
                'ios' => [
                    'tabs' => self::iosTabs($org, $in),
                    'sections' => self::iosSections($org, $in),
                ] + ($in->menuKilled ? ['source' => 'features'] : []),
                'android' => [
                    'tabs' => self::androidTabs($org, $in->storedFeatureIds !== null),
                ],
            ],
            'web' => $in->web,
            'tvos' => [
                'theme' => TvConfigController::THEME,
                // The board falls back to the organisation's name when tv-config
                // sends no header_title, which is what it will send.
                'header_title' => $org->name,
                'carousel_interval_seconds' => TvConfigController::CAROUSEL_INTERVAL_SECONDS,
                'show_prayer_panel' => $org->isMasjid(),
                'show_qr' => $in->donationLink !== '',
                'donate_caption' => TvConfigController::DONATE_CAPTION,
                'announcement_selection' => TvConfigController::ANNOUNCEMENT_SELECTION,
            ],
        ];
    }

    /**
     * A draft's pivot is seeded from its switches (S8), so the switches say what
     * its tabs will be.
     *
     * A live organisation is drawn as the Play PRODUCTION build draws it. That
     * is versionCode 13 (2.8.1, burlington-masjid-Android commit 8579eee,
     * HANDOFF.md "vc13 shipped"), whose BottomBar composable lists Home,
     * Announcement, ContactUs and Donate unconditionally
     * (app/src/main/java/com/app/masajid/ui/views/bottomBar/BottomBar.kt:28-33):
     * it reads no feature, so neither the switches nor the stored rows move it.
     * Later builds do read them (vc14, tag play-vc14-2.9.0, BottomBar.kt:121-138,
     * gates on the available ids and draws the four when none is available; vc15,
     * ui/shell/BottomTabs.kt:41-46, draws Home alone then) but neither is on
     * production; when one is, this is what changes.
     *
     * @return list<string>
     */
    private static function androidTabs(Masjid $org, bool $live): array
    {
        if ($live) {
            return ['home', ...array_values(self::ANDROID_TABS)];
        }

        $rows = AppFeaturePivot::rowsFor($org);
        $tabs = ['home'];

        foreach (self::ANDROID_TABS as $id => $tab) {
            if ($rows[$id] ?? false) {
                $tabs[] = $tab;
            }
        }

        return $tabs;
    }

    /**
     * The iOS tab bar. While /menu answers 404 (the kill row) the phone builds
     * its menu from the organisation's stored /features rows instead
     * (LegacyMenuAdapter.menu: Home, then the registry's tabs whose legacy id
     * is available, in bar order, no fallback bar: none available is Home
     * alone). Otherwise it is /menu's, AppMenu::tabs. The phone prefers a cached good
     * menu before either (the adapter's header), which cannot be known here, and the
     * shipped main build reads the /features rows for its tabs whether or not the kill
     * row is set, so on that build the non-killed frame may differ from the phone.
     *
     * @return list<string>
     */
    private static function iosTabs(Masjid $org, PreviewInput $in): array
    {
        if (! $in->menuKilled || $in->storedFeatureIds === null) {
            return AppMenu::tabs($org);
        }

        $enabled = self::legacyEnabledKeys($in->storedFeatureIds);

        return array_values(array_filter(
            AppMenu::registry()['tabs'],
            fn (string $key) => in_array($key, $enabled, true)
        ));
    }

    /**
     * The iOS drawer, from the same source as iosTabs: /menu's sections, or,
     * while it is killed, the registry's sections holding the entries the stored
     * /features rows switch on (no `parts`, which the fallback cannot know).
     *
     * @return array<int, array{key: string, items: array<int, array<string, mixed>>}>
     */
    private static function iosSections(Masjid $org, PreviewInput $in): array
    {
        if (! $in->menuKilled || $in->storedFeatureIds === null) {
            return AppMenu::sections($org);
        }

        $registry = AppMenu::registry();
        $enabled = self::legacyEnabledKeys($in->storedFeatureIds);
        $out = [];

        foreach ($registry['sections'] as $section => $keys) {
            $items = [];

            foreach ($keys as $key) {
                if (in_array($key, $enabled, true)) {
                    $items[] = ['key' => $key, 'legacy_feature_id' => $registry['items'][$key]['legacy_feature_id']];
                }
            }

            if ($items !== []) {
                $out[] = ['key' => $section, 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * The registry entries the legacy /features list switches on: `home`, which
     * that list never had a row for, and every entry whose legacy id is an
     * available row (LegacyMenuAdapter.enabledKeys).
     *
     * @param  list<int>  $availableIds
     * @return list<string>
     */
    private static function legacyEnabledKeys(array $availableIds): array
    {
        $enabled = [];

        foreach (AppMenu::registry()['items'] as $key => $item) {
            $legacyId = $item['legacy_feature_id'];

            if ($legacyId === null ? $key === 'home' : in_array($legacyId, $availableIds, true)) {
                $enabled[] = $key;
            }
        }

        return $enabled;
    }

    /**
     * @param  array<string, string>  $webTokens
     * @return list<array<string, mixed>>
     */
    private static function platformContrast(string $primary, array $webTokens): array
    {
        return [
            self::row('ios.home_header', self::IOS_HOME_HEADER_INK, $primary),
            // Android's header draws DesignTokens' onPrimary (android: ui/theme/Color.kt:30-40).
            self::row('android.home_header', $webTokens['onPrimary'], $primary),
            self::row('app.menu_band', WcagColor::onPrimary($primary), $primary),
            self::row('ios.selected_tab', WcagColor::primaryOnSurface($primary), WcagColor::SURFACE),
            self::row('android.selected_tab', self::ANDROID_SELECTED_TAB, WcagColor::SURFACE),
            self::row('web.primary_button', $webTokens['onPrimary'], $primary),
            self::row('tvos.header', self::TVOS_HEADER_INK, self::TVOS_BACKGROUND),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(string $key, string $foreground, string $background): array
    {
        $ratio = WcagColor::ratio($foreground, $background);

        return [
            'key' => $key,
            'foreground' => WcagColor::normalize($foreground),
            'background' => WcagColor::normalize($background),
            'ratio' => round($ratio, 2),
            // Judged unrounded, as the palette's pairs are.
            'aa_normal' => $ratio >= WcagColor::AA_NORMAL,
            'aa_large' => $ratio >= self::AA_LARGE,
            'blocking' => false,
        ];
    }
}
