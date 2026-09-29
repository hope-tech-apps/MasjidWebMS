<?php

namespace App\Support\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\Page;
use App\Models\StudioDraft;
use App\Support\AppMenu;
use App\Support\CapabilityCatalogue;
use App\Support\CapabilityWriter;
use App\Support\DesignTokens;
use App\Support\HostName;
use App\Support\WcagColor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What StudioPreview draws, whoever it is drawn for (Studio W2 S9): a draft
 * walking the steps (fromDraft, W1's derivation moved here unchanged) or an
 * organisation that already exists (fromMasjid), each with the changes being
 * considered applied in memory only.
 *
 * `org` is the Masjid every switch reader (hasCapability, moduleIsOff, AppMenu,
 * AppFeaturePivot) is asked about. For a draft it is unsaved and built from the
 * answers; for a live organisation it is a CLONE of the real row with the
 * candidate switches forced onto the clone's attributes. Nothing here, and
 * nothing StudioPreview does with it, is ever saved.
 */
final readonly class PreviewInput
{
    /**
     * @param  array<string, string>|null  $colours  the four brand colours, or null until all are chosen
     * @param  array<string, mixed>  $inks  hand-set onPrimary / onSecondary / onAccent
     * @param  array{width?: int|null, height?: int|null}|null  $logoDims
     * @param  array<string, mixed>  $storedTokens  the theme tokens the web will be painted with, beside the colours
     * @param  bool  $paintsPaletteInks  true for a draft: the inks PaletteContrast picks are what Step 3 writes, so the web is painted with them
     * @param  array<string, mixed>  $web  `web` minus nothing: preset, locale, pages, theme_layout, preset_source, approved
     * @param  list<string>  $platforms
     * @param  list<int>|null  $storedFeatureIds  a live organisation's stored, available Mobile App Features rows (what GET /features serves; the iOS frame draws them while /menu is killed); null for a draft, whose Android tabs come from its switches
     * @param  bool  $menuKilled  a live organisation only: the /menu kill row is set, so phones build their menu from those same rows (StudioPreview::iosTabs)
     */
    public function __construct(
        public Masjid $org,
        public string $orgType,
        public ?array $colours,
        public array $inks,
        public ?array $logoDims,
        public array $storedTokens,
        public bool $paintsPaletteInks,
        public array $web,
        public array $platforms,
        public ?string $host,
        public string $donationLink,
        public ?array $storedFeatureIds = null,
        public bool $menuKilled = false,
    ) {}

    /** The draft keys StarterFacts reads; nothing else (not `vibe`, R12) leaves the draft. */
    private const FACT_KEYS = [
        'identity' => ['name', 'description', 'email', 'phone', 'address', 'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url', 'donation_link'],
        'content' => ['about', 'mission', 'vision'],
    ];

    /** StudioDraft's four brand colours, in its order. */
    private const BRAND_COLOURS = ['primary_color', 'secondary_color', 'accent_color', 'background_color'];

    /** The live platform set an organisation's publishing row may hold (D15). */
    private const PLATFORMS = ['ios', 'android', 'tvos', 'web'];

    /**
     * What the site draws, by colour, when the organisation has NO theme row at
     * all: the served settings then carry `theme: null` (SettingController), the
     * renderer publishes no variables (tenantThemeVars returns []), and the
     * stylesheet's own :root defaults show: --brand, --is-titles-navbar (the
     * secondary slot), --is-color-card-prayer-time and --is-color-body
     * (burlington-masjid-site, main: app/assets/css/index.css and
     * app/utils/tenantTheme.ts).
     */
    private const SITE_STYLESHEET_COLOURS = [
        'primary_color' => '#01B151',
        'secondary_color' => '#1B1B2E',
        'accent_color' => '#FFBA63',
        'background_color' => '#F3F8FB',
    ];

    /**
     * A draft's preview: exactly W1's derivation (StudioPreviewTest,
     * StudioLayoutPreviewTest and StudioPreviewParityTest pin it).
     *
     * @param  array<string, array<string, mixed>|null>  $answerOverrides  whole sections that replace the
     *                                                                     saved ones for this preview only; null clears one
     */
    public static function fromDraft(StudioDraft $draft, array $answerOverrides = []): self
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

        $org = self::draftOrganisation($orgType, $identity, $effective->section('features'));

        $layout = $effective->section('layout');
        $chosen = is_string($layout['preset'] ?? null) ? $layout['preset'] : null;
        $presetKey = in_array($chosen, LayoutPresets::keysFor($orgType), true) ? $chosen : LayoutPresets::defaultFor($orgType);
        $preset = LayoutPresets::find($presetKey);

        $plan = StarterSite::plan($org, $presetKey, self::draftFacts($effective));

        $inks = $effective->section('brand')['ink_overrides'] ?? [];
        $platforms = $effective->section('platforms')['platforms'] ?? [];

        return new self(
            org: $org,
            orgType: $orgType,
            colours: $effective->brandColours(),
            inks: is_array($inks) ? $inks : [],
            logoDims: $draft->hasLogo() ? ['width' => $draft->logo_width, 'height' => $draft->logo_height] : null,
            storedTokens: ['layout' => $preset['theme_layout']],
            paintsPaletteInks: true,
            web: $plan->toArray() + [
                'theme_layout' => $preset['theme_layout'],
                // 'draft' when this is the draft's own choice; 'default' when the
                // draft has none for this org type and Step 2 starts on the default.
                'preset_source' => $presetKey === $chosen ? 'draft' : 'default',
                // R27: web needs an approved preset, and only the draft's own
                // choice can have been approved.
                'approved' => $presetKey === $chosen && filled($layout['approved_at'] ?? null),
            ],
            platforms: is_array($platforms) ? array_values($platforms) : [],
            host: self::draftHost($identity, $effective->section('domain')),
            donationLink: trim((string) ($identity['donation_link'] ?? '')),
        );
    }

    /**
     * An existing organisation's preview, with candidate changes applied to an
     * in-memory clone only (Studio W2 S9, R14):
     *
     *  - `brand`: the four colours, replacing the theme's for this preview;
     *  - `capabilities`: key => real bool, forced onto the clone's overrides.
     *    Only keys the capability writer may store are accepted
     *    (CapabilityWriter::assertWritable), so the preview can never show a
     *    change Studio could not then save.
     *
     * The web pages are the organisation's own (active or not, in menu order),
     * in the starter plan's shape, painted with the theme's stored tokens and
     * the colours being considered: what the renderer will draw once the
     * colours are saved through the theme screen. A colour the theme lacks is
     * drawn as the renderer draws it now (liveColours), never left grey.
     *
     * @param  array{brand?: array<string, string>, capabilities?: array<string, bool>}  $overrides
     *
     * @throws \Illuminate\Validation\ValidationException for a capability no writer may store
     */
    public static function fromMasjid(Masjid $real, array $overrides = []): self
    {
        $org = clone $real;
        $org->setRelations([]);

        $capabilities = $overrides['capabilities'] ?? [];

        foreach (array_keys($capabilities) as $key) {
            CapabilityWriter::assertWritable((string) $key);
        }

        if ($capabilities !== []) {
            $stored = is_array($org->capability_overrides) ? $org->capability_overrides : [];
            // In memory, on the clone: the real row and its model are untouched.
            $org->forceFill(['capability_overrides' => array_replace($stored, $capabilities)]);
        }

        $theme = $real->themeSettings()->first();
        $stored = [
            'primary_color' => $theme?->primary_color,
            'secondary_color' => $theme?->secondary_color,
            'accent_color' => $theme?->accent_color,
            'background_color' => $theme?->background_color,
        ];
        $colours = self::liveColours(($overrides['brand'] ?? []) + $stored, $theme !== null);

        $tokens = is_array($theme?->tokens) ? $theme->tokens : [];
        $color = is_array($tokens['color'] ?? null) ? $tokens['color'] : [];
        $inks = array_intersect_key($color, array_flip(['onPrimary', 'onSecondary', 'onAccent']));
        $themeLayout = is_array($tokens['layout'] ?? null) ? $tokens['layout'] : null;

        $publishing = $real->appPublishing()->first();
        $platforms = is_array($publishing?->enabled_platforms)
            ? array_values(array_intersect(self::PLATFORMS, $publishing->enabled_platforms))
            : [];

        // The clone, not the saved row: the Web tab follows the Website switch
        // being considered, as every other reader in the preview does.
        if (! in_array('web', $platforms, true) && ! $org->moduleIsOff('website')) {
            // An organisation with a website shows it, whether or not the
            // publishing row (which predates the web) names it.
            $platforms[] = 'web';
        }

        $pages = self::livePages($real);

        return new self(
            org: $org,
            orgType: $real->orgType(),
            colours: $colours,
            inks: $inks,
            logoDims: self::logoDims($real),
            storedTokens: $tokens,
            paintsPaletteInks: false,
            web: [
                'preset' => null,
                'locale' => StarterFacts::DEFAULT_LOCALE,
                'pages' => $pages,
                // Live only: the front page is the one the renderer serves at /,
                // not whichever page is listed first (a draft's key set is unchanged).
                'home_slug' => self::liveHomeSlug($pages),
                'theme_layout' => $themeLayout,
                'preset_source' => 'live',
                'approved' => true,
            ],
            platforms: $platforms,
            host: self::liveHost($real),
            donationLink: trim((string) ($real->donationLink()->value('link') ?? '')),
            storedFeatureIds: self::storedAvailableFeatureIds($real),
            menuKilled: self::readMenuKilled(),
        );
    }

    /**
     * Has an operator taken /menu away from every phone? The kill row's own
     * query (AppMenu::killSwitchRow), read without AppMenu::killed()'s cache: a
     * cached read writes to the cache store, and a preview writes nothing. Fails
     * open like killed(): an unreadable table reads as live.
     */
    private static function readMenuKilled(): bool
    {
        try {
            return (bool) AppMenu::killSwitchRow()?->menu_disabled;
        } catch (Throwable $e) {
            Log::warning('studio preview could not read the app menu kill switch; drawing the menu as live', [
                'exception' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * The feature ids the phones read from GET /features: the organisation's own
     * pivot rows (scoped by masjid_id through the relation) that say available.
     * While /menu is killed they, not the switches, decide the iOS tabs and
     * drawer (StudioPreview::iosTabs). An empty list is meaningful: nothing is
     * switched on, so that fallback is Home alone.
     *
     * @return list<int>
     */
    private static function storedAvailableFeatureIds(Masjid $real): array
    {
        return $real->features()->get()
            ->filter(fn ($feature) => (bool) $feature->pivot->is_available)
            ->map(fn ($feature) => (int) $feature->id)
            ->values()
            ->all();
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
    private static function draftOrganisation(string $orgType, array $identity, array $features): Masjid
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

    private static function draftFacts(StudioDraft $effective): StarterFacts
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
    private static function draftHost(array $identity, array $domain): ?string
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

    /**
     * The four colours as #RRGGBB for display (a 3-digit value expanded, an alpha
     * pair dropped, for the mockups only: nothing here is saved).
     *
     * A colour that is missing or that the theme save would not accept is filled
     * the way the renderer fills it today, so the mockup shows the live site and
     * not a grey stand-in (R25 is the DRAFT's rule: a draft with a colour missing
     * has no palette yet). With a theme row the renderer is served
     * DesignTokens::resolve(), so DesignTokens::DEFAULTS is the one source for
     * the fill; with no row it draws the stylesheet's defaults (above).
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, string>
     */
    private static function liveColours(array $candidate, bool $hasTheme): array
    {
        $colours = [];

        foreach (self::BRAND_COLOURS as $key) {
            $value = $candidate[$key] ?? null;

            $display = is_string($value) ? WcagColor::normalize($value) : null;

            $colours[$key] = $display ?? ($hasTheme
                ? strtoupper(DesignTokens::DEFAULTS[str_replace('_color', '', $key)])
                : self::SITE_STYLESHEET_COLOURS[$key]);
        }

        return $colours;
    }

    /** @return array{width: int, height: int}|null */
    private static function logoDims(Masjid $real): ?array
    {
        $path = $real->logo()->first()?->getPath();
        $size = is_string($path) && is_readable($path) ? @getimagesize($path) : false;

        return is_array($size) ? ['width' => (int) $size[0], 'height' => (int) $size[1]] : null;
    }

    /**
     * The host the live site answers on: a served row, the client's own domain
     * before the managed subdomain (as a draft's host is chosen); null when the
     * organisation has none on record.
     */
    private static function liveHost(Masjid $real): ?string
    {
        $row = MasjidDomain::query()
            ->where('masjid_id', $real->id)
            ->whereIn('status', MasjidDomain::SERVED)
            ->orderByRaw('CASE WHEN kind = ? THEN 0 ELSE 1 END', [MasjidDomain::KIND_CUSTOM])
            ->orderBy('id')
            ->first();

        return $row?->host;
    }

    /**
     * The page the renderer draws at `/`: the ACTIVE page whose slug is `home`
     * (burlington-masjid-site, main: app/pages/index.vue reads
     * getPageBySlug('home'), over the pages GET /pages serves, which are the
     * active ones only). Null when there is none: the site's front page is then
     * blank, whatever else is listed first and whatever is inactive.
     *
     * @param  list<array<string, mixed>>  $pages
     */
    private static function liveHomeSlug(array $pages): ?string
    {
        foreach ($pages as $page) {
            if ($page['slug'] === 'home' && $page['is_active']) {
                return 'home';
            }
        }

        return null;
    }

    /**
     * The organisation's own pages in the starter plan's shape, so the web
     * mockup draws them as it draws a plan. Hand-scoped rows, filtered by
     * masjid_id on both sides of the pivot. Placeholders are the checklist's
     * business (S10), not the mockup's, so a live section carries none.
     *
     * @return list<array<string, mixed>>
     */
    private static function livePages(Masjid $real): array
    {
        $pages = Page::query()
            ->where('masjid_id', $real->id)
            ->with(['sections' => fn ($q) => $q->where('sections.masjid_id', $real->id)])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($pages as $page) {
            $sections = [];

            foreach ($page->sections->sortBy(fn ($s) => [(int) $s->pivot->order, (int) $s->id]) as $section) {
                $raw = json_decode((string) $section->getRawOriginal('content'), true);
                $type = \App\Enums\SectionType::tryFrom((string) $section->getRawOriginal('section_type'));

                $sections[] = [
                    'slot' => "live/{$section->id}",
                    'section_type' => (string) $section->getRawOriginal('section_type'),
                    'title' => (string) $section->title,
                    'is_active' => (bool) $section->is_active,
                    'has_renderer' => $type?->hasRenderer() ?? false,
                    'content' => is_array($raw) ? $raw : [],
                    'placeholders' => [],
                ];
            }

            $out[] = [
                'slug' => (string) $page->slug,
                'title' => (string) $page->title,
                'order' => (int) $page->order,
                'is_active' => (bool) $page->is_active,
                'show_in_menu' => (bool) $page->show_in_menu,
                'show_as_button' => (bool) $page->show_as_button,
                'meta_description' => $page->meta_description,
                'sections' => $sections,
            ];
        }

        return $out;
    }
}
