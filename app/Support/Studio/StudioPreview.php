<?php

namespace App\Support\Studio;

use App\Http\Controllers\Mobile\TvConfigController;
use App\Models\Masjid;
use App\Models\StudioDraft;
use App\Models\ThemeSetting;
use App\Support\AppFeaturePivot;
use App\Support\AppMenu;
use App\Support\CapabilityCatalogue;
use App\Support\DesignTokens;
use App\Support\HostName;
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
 *  - the iOS tabs and sections are AppMenu's, which /menu serves;
 *  - the Android tabs are the legacy pivot rows AppFeaturePivot::rowsFor()
 *    derives, which S8 seeds and /features serves;
 *  - the web plan is StarterSite::plan(), which S8 writes;
 *  - the tvOS values are TvConfigController's own constants.
 *
 * The organisation is an UNSAVED Masjid built from the answers. Every switch
 * reader (Masjid::hasCapability, moduleIsOff, AppMenu) reads attributes, not
 * rows, so it answers exactly as the provisioned org will. Nothing is written:
 * not the draft, not an organisation, not a theme.
 *
 * `platform_contrast` is ADVISORY (R16). The iOS home header is hard-coded
 * white and Android's selected tab is hard-coded green, and no stored token can
 * change either, so a failing row there informs the operator and never blocks.
 * Only the palette's blocking pairs gate Step 3.
 *
 * Until all four brand colours are chosen, `palette`, `web_tokens` and
 * `platform_contrast` are null: DesignTokens fills a missing colour with
 * Burlington's green (R25), and grading or painting that would show a live
 * client's brand, not this one's.
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

    /** The draft keys StarterFacts reads; nothing else (not `vibe`, R12) leaves the draft. */
    private const FACT_KEYS = [
        'identity' => ['name', 'description', 'email', 'phone', 'address', 'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url', 'donation_link'],
        'content' => ['about', 'mission', 'vision'],
    ];

    /**
     * @param  array<string, array<string, mixed>|null>  $answerOverrides  whole sections that replace the
     *                                                                     saved ones for this preview only; null clears one
     * @return array<string, mixed>
     */
    public static function build(StudioDraft $draft, array $answerOverrides = []): array
    {
        $answers = $draft->answers;

        foreach ($answerOverrides as $section => $value) {
            if ($value === null) {
                unset($answers[$section]);
            } else {
                $answers[$section] = $value;
            }
        }

        // An unsaved draft carrying the effective answers, so the colour rules
        // are StudioDraft's own. It is never saved.
        $effective = new StudioDraft;
        $effective->answers = $answers;

        $identity = $effective->section('identity');
        $orgType = in_array($identity['org_type'] ?? null, Masjid::ORG_TYPES, true)
            ? $identity['org_type']
            : Masjid::ORG_TYPE_MASJID;

        $org = self::organisation($orgType, $identity, $effective->section('features'));

        $layout = $effective->section('layout');
        $chosen = is_string($layout['preset'] ?? null) ? $layout['preset'] : null;
        $presetKey = in_array($chosen, LayoutPresets::keysFor($orgType), true) ? $chosen : LayoutPresets::defaultFor($orgType);
        $preset = LayoutPresets::find($presetKey);

        $plan = StarterSite::plan($org, $presetKey, self::facts($effective));

        $colours = $effective->brandColours();
        $palette = null;
        $webTokens = null;

        if ($colours !== null) {
            $inks = $effective->section('brand')['ink_overrides'] ?? [];

            $palette = PaletteContrast::report(
                $colours,
                is_array($inks) ? $inks : [],
                $draft->hasLogo() ? ['width' => $draft->logo_width, 'height' => $draft->logo_height] : null,
            );

            $webTokens = DesignTokens::resolve(new ThemeSetting($colours + [
                'tokens' => ['color' => $palette['tokens']['color'], 'layout' => $preset['theme_layout']],
            ]))['color'];
        }

        $platforms = $effective->section('platforms')['platforms'] ?? [];
        $donationLink = trim((string) ($identity['donation_link'] ?? ''));

        return [
            'org' => [
                'name' => $org->name,
                'org_type' => $orgType,
                'host' => self::host($identity, $effective->section('domain')),
            ],
            'platforms' => is_array($platforms) ? array_values($platforms) : [],
            'palette' => $palette,
            'web_tokens' => $webTokens,
            'platform_contrast' => $colours === null ? null : self::platformContrast($colours['primary_color'], $webTokens),
            'app' => [
                'ios' => [
                    'tabs' => AppMenu::tabs($org),
                    'sections' => AppMenu::sections($org),
                ],
                'android' => [
                    'tabs' => self::androidTabs($org),
                ],
            ],
            'web' => $plan->toArray() + [
                'theme_layout' => $preset['theme_layout'],
                // 'draft' when this is the draft's own choice; 'default' when the
                // draft has none for this org type and Step 2 starts on the default.
                'preset_source' => $presetKey === $chosen ? 'draft' : 'default',
                // R27: web needs an approved preset, and only the draft's own
                // choice can have been approved.
                'approved' => $presetKey === $chosen && filled($layout['approved_at'] ?? null),
            ],
            'tvos' => [
                'theme' => TvConfigController::THEME,
                // The board falls back to the organisation's name when tv-config
                // sends no header_title, which is what it will send.
                'header_title' => $org->name,
                'carousel_interval_seconds' => TvConfigController::CAROUSEL_INTERVAL_SECONDS,
                'show_prayer_panel' => $org->isMasjid(),
                'show_qr' => $donationLink !== '',
                'donate_caption' => TvConfigController::DONATE_CAPTION,
                'announcement_selection' => TvConfigController::ANNOUNCEMENT_SELECTION,
            ],
        ];
    }

    /**
     * The organisation Step 3 would create, unsaved, with its switches set the
     * way S8's writer sets them: each column-backed grant on its column, and
     * every other key stored only where it departs from the org type's default
     * at creation (R9, R26).
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $features
     */
    private static function organisation(string $orgType, array $identity, array $features): Masjid
    {
        $name = is_string($identity['name'] ?? null) ? trim($identity['name']) : '';

        $org = new Masjid(['name' => $name, 'org_type' => $orgType]);

        $choices = is_array($features['capabilities'] ?? null) ? $features['capabilities'] : [];
        $desired = CapabilityCatalogue::resolve($orgType, $choices);
        $overrides = [];

        foreach ((array) config('capabilities', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $value = $desired[$key] ?? CapabilityCatalogue::defaultAtCreation($key, $orgType);

            if (! empty($definition['column'])) {
                $org->setAttribute($definition['column'], $value);
            } elseif (array_key_exists($key, $desired) && $value !== CapabilityCatalogue::defaultAtCreation($key, $orgType)) {
                $overrides[$key] = $value;
            }
        }

        // Not fillable, on purpose (Masjid::hasCapability); set on this
        // in-memory model only.
        $org->forceFill(['capability_overrides' => $overrides === [] ? null : $overrides]);

        return $org;
    }

    private static function facts(StudioDraft $effective): StarterFacts
    {
        $facts = [];

        foreach (self::FACT_KEYS as $section => $keys) {
            $facts += array_intersect_key($effective->section($section), array_flip($keys));
        }

        return StarterFacts::fromArray($facts + ['locale' => StarterFacts::DEFAULT_LOCALE]);
    }

    /**
     * The host the site will answer on: the client's own domain when one is
     * set, otherwise the managed subdomain, otherwise null (no slug yet).
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $domain
     */
    private static function host(array $identity, array $domain): ?string
    {
        $custom = $domain['custom']['host'] ?? null;
        $custom = is_string($custom) ? HostName::normalize($custom) : null;

        if ($custom !== null) {
            return $custom;
        }

        $slug = is_string($identity['slug'] ?? null) ? strtolower(trim($identity['slug'])) : '';

        return $slug !== '' && HostName::isLabel($slug)
            ? $slug . '.' . config('cloudflare.managed_suffix')
            : null;
    }

    /** @return list<string> */
    private static function androidTabs(Masjid $org): array
    {
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
