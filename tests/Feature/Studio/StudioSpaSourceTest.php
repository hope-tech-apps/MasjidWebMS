<?php

namespace Tests\Feature\Studio;

use App\Http\Requests\Admin\Studio\StoreStudioDraftLogoRequest;
use App\Models\StudioDraft;
use App\Support\AppMenu;
use App\Support\Studio\StudioPreview;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * The Studio SPA's wiring, read from its source (docs/manara-studio-w1.md S5).
 *
 * A Feature test cannot render Vue, so, like the other screen guards
 * (SpaTitleIsRuntimeTest), this pins what the files say:
 *
 *  - Studio types in no terminology label and no worship key. The words and
 *    the keys come from the server, or Studio becomes one more copy of the
 *    feature list (docs/manara-studio.md, landmine 3);
 *  - the native labels the mockups print are keyed exactly as AppMenu keys the
 *    menu and tabs, so a registry key with no label fails here instead of
 *    rendering blank;
 *  - no store credential can reach the autosave: no Studio file names one (R7);
 *  - both routes and the sidebar entry exist, SuperAdmin-only;
 *  - the device frames draw exactly the tabs the server's preview serves, in
 *    the app's own hard-coded colours (held equal to StudioPreview's), at
 *    each device's size, and the stage that scales them measures as it mounts
 *    rather than waiting for a callback a hidden pane may never fire;
 *  - the logo is prepared by prepareLogo, never by preparePhoto (which flattens
 *    onto white as JPEG), and the wizard's palette sampler was moved, not copied.
 *
 * Comments are stripped before the code checks, so a docblock may name what
 * the code must not contain.
 */
class StudioSpaSourceTest extends TestCase
{
    use ReadsStudioSource;

    private const SPA = 'resources/vue-app';

    /** Every file that is Studio's, relative to the SPA root. */
    private const STUDIO_PATHS = [
        'views/dashboard/super/studio',
        'components/super/studio',
        'core/studio',
        'stores/super/studioDraftStore.ts',
        'core/types/data/Studio.ts',
        'core/helpers/prepareLogo.ts',
        'core/helpers/extractPalette.ts',
    ];

    /** The distinctive terminology labels OnboardingVerticalPickerTest guards in the wizard. */
    private const TERMINOLOGY_LABELS = ['Congregants', 'Halaqat', 'Families', 'Classrooms', 'Imams', 'Faculty'];

    #[Test]
    public function studio_types_in_no_terminology_label_and_no_worship_key(): void
    {
        $worshipKeys = AppMenu::DEFAULT_REGISTRY['sections']['worship'];
        $this->assertNotEmpty($worshipKeys, 'the registry has no worship section to guard');

        // Each organisation type's own label too ("where a new Masjid starts"):
        // the step reads it from /onboarding/options.
        $verticalLabels = array_values(array_filter(array_map(fn ($vertical) => is_array($vertical) ? ($vertical['label'] ?? null) : null, config('verticals'))));
        $this->assertContains('Masjid', $verticalLabels);

        foreach ($this->studioFiles() as $relative => $code) {
            foreach (self::TERMINOLOGY_LABELS as $label) {
                $this->assertStringNotContainsString($label, $code, "{$relative} types in the terminology label '{$label}'; it comes from /onboarding/options");
            }

            foreach ($verticalLabels as $label) {
                $this->assertFalse($this->saysWords($code, $label), "{$relative} types in the organisation type label '{$label}'; it comes from /onboarding/options");
            }

            foreach ($worshipKeys as $key) {
                $this->assertDoesNotMatchRegularExpression(
                    '/([\'"`])' . preg_quote($key, '/') . '\1/',
                    $code,
                    "{$relative} types in the worship key '{$key}'; keys come from the server"
                );
            }
        }
    }

    #[Test]
    public function the_ios_labels_are_keyed_exactly_as_the_menu_registry(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(AppMenu::DEFAULT_REGISTRY['items']),
            $this->objectKeys('IOS_MENU_TITLES'),
            'IOS_MENU_TITLES must have one label for every AppMenu item, and no other'
        );

        $this->assertEqualsCanonicalizing(
            AppMenu::DEFAULT_REGISTRY['tabs'],
            $this->objectKeys('IOS_TAB_TITLES'),
            'IOS_TAB_TITLES must have one label for every AppMenu tab, and no other'
        );
    }

    #[Test]
    public function the_android_labels_are_keyed_exactly_as_the_preview_serves_its_tabs(): void
    {
        $this->assertEqualsCanonicalizing(
            array_merge(['home'], array_values(StudioPreview::ANDROID_TABS)),
            $this->objectKeys('ANDROID_TAB_TITLES'),
        );
    }

    #[Test]
    public function no_studio_file_names_a_store_credential(): void
    {
        $this->assertNotEmpty(StudioDraft::SECRET_KEYS);

        foreach ($this->studioFiles() as $relative => $code) {
            foreach (StudioDraft::SECRET_KEYS as $secret) {
                $this->assertStringNotContainsString($secret, $code, "{$relative} names the store credential '{$secret}'; it must never reach a draft (R7)");
            }
        }
    }

    #[Test]
    public function the_autosave_is_one_json_patch_built_by_autosave_body(): void
    {
        $store = $this->studioFiles()['stores/super/studioDraftStore.ts'] ?? null;
        $this->assertNotNull($store, 'the Studio store is missing');

        $this->assertSame(1, substr_count($store, 'ApiService.patch('), 'the store should PATCH in exactly one place');
        $this->assertMatchesRegularExpression('/const body = autosaveBody\(/', $store);
        $this->assertMatchesRegularExpression('/ApiService\.patch\(`\/api\/admin\/studio\/drafts\/\$\{id\}`, body\)/', $store);
        $this->assertStringNotContainsString('new FormData', preg_replace('/async function uploadLogo.*?\n    \}\n/s', '', $store), 'only the logo upload may send FormData');
    }

    #[Test]
    public function both_studio_routes_exist_for_super_admins_only(): void
    {
        $routes = $this->read(self::SPA . '/router/routes/superDashboardRoutes.ts');

        foreach ([
            'studio.drafts' => ["path: 'studio'", 'views/dashboard/super/studio/StudioDraftsView.vue'],
            'studio.draft' => ["path: 'studio/drafts/:draft_id(\\\\d+)'", 'views/dashboard/super/studio/StudioView.vue'],
        ] as $name => [$path, $component]) {
            $this->assertMatchesRegularExpression(
                '/\{\s*' . preg_quote($path, '/') . ',\s*name: \'' . preg_quote($name, '/') . '\',\s*meta: \{[^}]*allowedUsers: \[\'SuperAdmin\'\][^}]*pageTitle: \'Manara Studio\'[^}]*dashboardType: \'super\'[^}]*\},\s*component: \(\) => import\("@\/' . preg_quote($component, '/') . '"\)/s',
                $routes,
                "the route {$name} is missing, or is not SuperAdmin-only"
            );
            $this->assertFileExists(base_path(self::SPA . '/' . $component));
        }

        // The wizard's route stays until S12.
        $this->assertStringContainsString("name: 'masjid.onboarding'", $routes);

        $menu = $this->read(self::SPA . '/core/constants/dashboardAsideMenuItems.ts');
        $super = substr($menu, strpos($menu, 'export const SUPER_DASHBOARD_ASIDE_MENU'));
        $this->assertMatchesRegularExpression(
            '/title: "Manara Studio",.*?to: \'\/dashboard\/super\/studio\',\s*allowed_types: \[\'SuperAdmin\'\]/s',
            $super,
            'the sidebar has no SuperAdmin-only "Manara Studio" entry'
        );
    }

    #[Test]
    public function the_logo_is_prepared_by_prepare_logo_and_the_palette_sampler_was_moved(): void
    {
        foreach ($this->studioFiles() as $relative => $code) {
            $this->assertStringNotContainsString('preparePhoto', $code, "{$relative} uses preparePhoto, which flattens a logo onto white as JPEG");
        }

        $store = $this->studioFiles()['stores/super/studioDraftStore.ts'];
        $this->assertStringContainsString("import { LogoPreparationError, prepareLogo } from \"@/core/helpers/prepareLogo\"", $store);
        $this->assertMatchesRegularExpression('/const prepared = await prepareLogo\(file\);\s*const form = new FormData\(\);\s*form\.append\(\'logo\', prepared\);/', $store);

        $wizard = $this->read(self::SPA . '/views/dashboard/super/OnboardingWizardView.vue');
        $this->assertStringContainsString("import { extractDominantColors } from '@/core/helpers/extractPalette';", $wizard);
        $this->assertStringNotContainsString('function extractDominantColors(', $wizard);
        $this->assertStringNotContainsString('function rgbToHex(', $wizard);
    }

    #[Test]
    public function the_app_frames_draw_exactly_the_tabs_the_preview_serves(): void
    {
        $files = $this->studioFiles();
        $tabKeys = array_unique(array_merge(AppMenu::DEFAULT_REGISTRY['tabs'], ['home'], array_values(StudioPreview::ANDROID_TABS)));

        foreach (['IosFrame' => 'preview.app.ios.tabs', 'AndroidFrame' => 'preview.app.android.tabs'] as $frame => $source) {
            $code = $files["components/super/studio/preview/{$frame}.vue"] ?? null;
            $this->assertNotNull($code, "{$frame}.vue is missing");

            $this->assertStringContainsString($source, $code, "{$frame} must draw its tab bar from {$source}");
            $this->assertDoesNotMatchRegularExpression('/\[\s*[\'"`]/', $code, "{$frame} holds a list of strings; its tabs come from the preview");

            foreach ($tabKeys as $key) {
                $this->assertFalse($this->quotes($code, $key), "{$frame} types in the tab key '{$key}'; the preview serves the tabs");
            }
        }

        // The frames draw the server's preview and nothing else: none of them calls the API.
        foreach ($files as $relative => $code) {
            if (str_starts_with($relative, 'components/super/studio/preview/')) {
                $this->assertStringNotContainsString('ApiService', $code, "{$relative} calls the API; the frames read only the store's preview");
            }
        }
    }

    #[Test]
    public function the_colours_the_apps_hard_code_are_the_servers(): void
    {
        foreach ([
            'IOS_HOME_HEADER_INK' => StudioPreview::IOS_HOME_HEADER_INK,
            'ANDROID_SELECTED_TAB' => StudioPreview::ANDROID_SELECTED_TAB,
            'TVOS_BACKGROUND' => StudioPreview::TVOS_BACKGROUND,
            'TVOS_HEADER_INK' => StudioPreview::TVOS_HEADER_INK,
        ] as $constant => $server) {
            $this->assertSame(strtoupper($server), strtoupper($this->stringConstant($constant)), "{$constant} must equal StudioPreview's value");
        }

        $files = $this->studioFiles();
        $this->assertStringContainsString('ANDROID_SELECTED_TAB', $files['components/super/studio/preview/AndroidFrame.vue']);
        $this->assertStringContainsString('IOS_HOME_HEADER_INK', $files['components/super/studio/preview/IosFrame.vue']);
        $this->assertStringContainsString('TVOS_BACKGROUND', $files['components/super/studio/preview/TvFrame.vue']);
    }

    #[Test]
    public function each_frame_is_drawn_at_its_devices_size_and_the_tv_board_says_what_waits_for_w2(): void
    {
        $files = $this->studioFiles();

        $web = $files['components/super/studio/preview/WebFrame.vue'];
        $this->assertMatchesRegularExpression('/desktop: \{ width: 1280, height: 800 \}/', $web);
        $this->assertMatchesRegularExpression('/mobile: \{ width: 390, height: 844 \}/', $web);
        $this->assertStringContainsString('section.has_renderer', $web, 'a section the renderer cannot draw must be marked');
        $this->assertStringContainsString('logoMissing', $web, 'a missing logo must be marked');

        foreach (['IosFrame' => [393, 852], 'AndroidFrame' => [412, 915], 'TvFrame' => [1920, 1080]] as $frame => [$width, $height]) {
            $this->assertMatchesRegularExpression(
                '/<DeviceStage :width="' . $width . '" :height="' . $height . '"/',
                $files["components/super/studio/preview/{$frame}.vue"],
                "{$frame} is not drawn at {$width}×{$height}"
            );
        }

        $this->assertStringContainsString('Events calendar arrives with the tvOS template (W2)', $files['components/super/studio/preview/TvFrame.vue']);
    }

    #[Test]
    public function the_device_stage_measures_as_it_mounts_before_it_observes(): void
    {
        $stage = $this->studioFiles()['components/super/studio/preview/DeviceStage.vue'];

        // rAF and ResizeObserver may not fire in a hidden pane, so the first
        // measurement cannot wait for either.
        $this->assertStringNotContainsString('requestAnimationFrame', $stage);
        $this->assertMatchesRegularExpression(
            '/onMounted\(\(\) => \{\s*measure\(\);.*?new ResizeObserver\(/s',
            $stage,
            'DeviceStage must measure synchronously in onMounted, then observe'
        );
    }

    #[Test]
    public function the_preview_panel_has_one_tab_per_platform_the_preview_serves_and_its_caption(): void
    {
        $panel = $this->studioFiles()['components/super/studio/preview/StudioPreviewPanel.vue'];

        $this->assertStringContainsString("'The apps look the same for every organisation; only colours, logo, name, tabs and menu change.'", $panel);
        $this->assertStringContainsString('preview.value?.platforms', $panel);
        $this->assertMatchesRegularExpression('/v-for="\(platform, index\) in platforms"/', $panel);
        $this->assertStringContainsString('<PlatformContrastList :rows="preview.platform_contrast" />', $panel);
    }

    #[Test]
    public function studio_opens_its_drafts_by_route_name(): void
    {
        $files = $this->studioFiles();
        $list = $files['views/dashboard/super/studio/StudioDraftsView.vue'];

        // Resume/Open on each row, and the draft "New client" creates.
        $this->assertStringContainsString(":to=\"{ name: 'studio.draft', params: { draft_id: row.id } }\"", $list);
        $this->assertStringContainsString("router.push({ name: 'studio.draft', params: { draft_id: outcome.data.id } })", $list);
        $this->assertStringContainsString(":to=\"{ name: 'studio.drafts' }\"", $files['views/dashboard/super/studio/StudioView.vue']);

        // No Studio file types a path into Studio, which no test could check against the router.
        foreach ($files as $relative => $code) {
            $this->assertStringNotContainsString('/dashboard/super/studio', $code, "{$relative} types a Studio path; navigate by route name");
        }
    }

    #[Test]
    public function the_sticky_preview_stops_below_the_fixed_header_and_fits_the_window(): void
    {
        $view = $this->read(self::SPA . '/views/dashboard/super/studio/StudioView.vue');
        $this->assertMatchesRegularExpression(
            '/\.studio-preview-column \{\s*position: sticky;\s*top: calc\(var\(--dash-header-height, 4rem\) \+ 1rem\);\s*max-height: calc\(100vh - var\(--dash-header-height, 4rem\) - 2rem\);\s*overflow-y: auto;\s*\}/',
            $view,
            'the preview column must stick below the fixed header and scroll within the window'
        );

        $layout = $this->read(self::SPA . '/layouts/DashboardLayout.vue');
        $this->assertStringContainsString("document.documentElement.style.setProperty('--dash-header-height', mainTopMargin + 'rem')", $layout, 'the layout must publish the header height it measures');
    }

    #[Test]
    public function each_step_moves_focus_to_its_own_heading(): void
    {
        $files = $this->studioFiles();

        $view = $files['views/dashboard/super/studio/StudioView.vue'];
        $this->assertMatchesRegularExpression(
            '/async function go\(step: StudioStepKey\) \{\s*if \(!canOpen\(step\)\) return;\s*store\.setStep\(step\);.*?await nextTick\(\);\s*const heading = stepHeadingId\(step\);\s*if \(heading\) document\.getElementById\(heading\)\?\.focus\(/s',
            $view,
            'opening a step must move focus to its heading once it renders'
        );

        $steps = $files['core/studio/steps.ts'];
        foreach ([
            'features' => ['studio-features-title', 'components/super/studio/steps/StudioFeatureStep.vue'],
            'layout' => ['studio-layout-title', 'components/super/studio/steps/StudioLayoutStep.vue'],
        ] as $key => [$id, $component]) {
            $this->assertStringContainsString("key: '{$key}', title: ", $steps);
            $this->assertMatchesRegularExpression("/key: '{$key}',[^}]*headingId: '{$id}'/", $steps);
            $this->assertMatchesRegularExpression('/<h5 id="' . $id . '"[^>]*tabindex="-1"/', $files[$component], "{$component}'s heading cannot take focus");
        }

        // Foundation's is the Identity panel's, named by StudioPanel from its title.
        $this->assertMatchesRegularExpression("/key: 'foundation',[^}]*headingId: 'studio-panel-identity'/", $steps);
        $this->assertStringContainsString('<StudioPanel title="Identity">', $files['components/super/studio/foundation/IdentityPanel.vue']);
        $panel = $files['components/super/studio/foundation/StudioPanel.vue'];
        $this->assertStringContainsString('<h5 :id="headingId" class="studio-panel-title" tabindex="-1">', $panel);
        $this->assertStringContainsString("const headingId = `studio-panel-\${props.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;", $panel);
    }

    #[Test]
    public function the_preview_tabs_follow_the_aria_tabs_pattern(): void
    {
        $panel = $this->studioFiles()['components/super/studio/preview/StudioPreviewPanel.vue'];

        $this->assertStringContainsString(':aria-controls="platform === activePlatform ? `studio-preview-pane-${platform}` : undefined"', $panel, 'only the tab whose panel is rendered may name it');
        $this->assertStringContainsString(':tabindex="platform === activePlatform ? 0 : -1"', $panel, 'only the chosen tab is a Tab stop');
        $this->assertStringContainsString('@keydown="onTabKey($event, index)"', $panel);
        $this->assertStringContainsString('const target = tabIndexForKey(event.key, index, platforms.value.length);', $panel);
        $this->assertSame(1, substr_count($panel, 'role="tabpanel"'));
    }

    #[Test]
    public function the_mockup_menu_lists_only_the_pages_the_site_serves(): void
    {
        $web = $this->studioFiles()['components/super/studio/preview/WebFrame.vue'];

        $this->assertStringContainsString('const menuPages = computed(() => siteMenuPages(props.pages));', $web);
        $this->assertStringContainsString('const buttonPages = computed(() => siteButtonPages(props.pages));', $web);
        $this->assertSame(0, preg_match('/props\.pages\.filter\(/', $web), 'the menu is chosen by core/studio/sitePages.ts, which drops inactive pages');
    }

    #[Test]
    public function the_logo_limits_prepare_logo_keeps_are_the_servers(): void
    {
        $prepare = $this->read(self::SPA . '/core/helpers/prepareLogo.ts');
        $rules = (new StoreStudioDraftLogoRequest())->rules()['logo'];
        $dimensions = collect($rules)->first(fn ($rule) => is_string($rule) && str_starts_with($rule, 'dimensions:'));
        $this->assertNotNull($dimensions, 'the logo request has no dimensions rule');
        parse_str(str_replace(',', '&', substr($dimensions, strlen('dimensions:'))), $limits);

        $this->assertSame((int) config('studio.logo.min_px'), (int) $limits['min_width']);
        $this->assertSame((int) $limits['max_width'], (int) $limits['max_height']);
        $this->assertStringContainsString('export const LOGO_MIN_EDGE = ' . (int) $limits['min_width'] . ';', $prepare);
        $this->assertStringContainsString('export const LOGO_LARGEST_EDGE = ' . (int) $limits['max_width'] . ';', $prepare);
    }

    #[Test]
    public function the_domain_check_is_typed_once_with_the_cases_s7_answers(): void
    {
        // S7's controller answers zone_in_account / zone_not_in_account and a
        // zone_status with a token; one type, where S7 keeps it, says so.
        $studio = $this->studioFiles()['core/types/data/Studio.ts'];
        $this->assertStringContainsString('export type StudioDomainCheck = MasjidDomainCheck;', $studio);
        $this->assertStringContainsString('export type StudioDomainCheckRequest = MasjidDomainRequest;', $studio);

        $domain = $this->withoutComments($this->read(self::SPA . '/core/types/data/MasjidDomain.ts'));
        $this->assertStringContainsString("case: 'managed_subdomain' | 'zone_in_account' | 'zone_not_in_account' | 'unknown';", $domain);
        $this->assertStringContainsString('zone_status?: string | null;', $domain);
    }

    #[Test]
    public function the_store_saves_through_the_autosave_rules(): void
    {
        $store = $this->studioFiles()['stores/super/studioDraftStore.ts'];

        $this->assertStringContainsString('const autosave = createAutosave<StudioDraft>({', $store);
        $this->assertStringContainsString('isConflict: (error) => statusOf(error) === 409,', $store, 'a 409 must reach the autosave as a conflict, which it never retries');
        $this->assertMatchesRegularExpression('/function setStep\(step: StudioStepKey\) \{\s*currentStep\.value = step;\s*autosave\.queueStep\(step\);\s*\}/', $store);
        $this->assertMatchesRegularExpression('/function reset\(\) \{\s*generation\+\+;\s*armed\.value = false;\s*autosave\.reset\(\);/', $store);
    }

    /** The keys of `export const NAME ... = { ... };` in core/studio/appLabels.ts. */
    private function objectKeys(string $constant): array
    {
        $source = $this->read(self::SPA . '/core/studio/appLabels.ts');

        $this->assertMatchesRegularExpression('/export const ' . $constant . '\b[^=]*=\s*\{(.*?)\n\};/s', $source, "appLabels.ts has no {$constant}");
        preg_match('/export const ' . $constant . '\b[^=]*=\s*\{(.*?)\n\};/s', $source, $body);
        preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:/m', $body[1], $keys);

        return $keys[1];
    }

    /** The value of `export const NAME = '…';` in core/studio/appLabels.ts. */
    private function stringConstant(string $constant): string
    {
        $source = $this->read(self::SPA . '/core/studio/appLabels.ts');
        $this->assertMatchesRegularExpression('/export const ' . $constant . ' = \'([^\']*)\';/', $source, "appLabels.ts has no {$constant}");
        preg_match('/export const ' . $constant . ' = \'([^\']*)\';/', $source, $match);

        return $match[1];
    }

    /** @return array<string, string> relative path => code with comments removed */
    private function studioFiles(): array
    {
        $files = [];

        foreach (self::STUDIO_PATHS as $path) {
            $absolute = base_path(self::SPA . '/' . $path);
            $this->assertFileExists($absolute, "Studio path {$path} is missing");

            $paths = is_dir($absolute)
                ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)))
                : [new \SplFileInfo($absolute)];

            foreach ($paths as $file) {
                if (! preg_match('/\.(ts|vue)$/', $file->getFilename())) {
                    continue;
                }

                $relative = ltrim(str_replace(base_path(self::SPA), '', $file->getPathname()), '/');
                $files[$relative] = $this->withoutComments(file_get_contents($file->getPathname()));
            }
        }

        $this->assertNotEmpty($files);
        ksort($files);

        return $files;
    }
}
