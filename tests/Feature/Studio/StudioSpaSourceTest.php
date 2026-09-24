<?php

namespace Tests\Feature\Studio;

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

        foreach ($this->studioFiles() as $relative => $code) {
            foreach (self::TERMINOLOGY_LABELS as $label) {
                $this->assertStringNotContainsString($label, $code, "{$relative} types in the terminology label '{$label}'; it comes from /onboarding/options");
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
                '/\{\s*' . preg_quote($path, '/') . ',\s*name: \'' . preg_quote($name, '/') . '\',\s*meta: \{[^}]*allowedUsers: \[\'SuperAdmin\'\][^}]*pageTitle: \'Manara Studio\'[^}]*\},\s*component: \(\) => import\("@\/' . preg_quote($component, '/') . '"\)/s',
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
        $this->assertMatchesRegularExpression('/v-for="platform in platforms"/', $panel);
        $this->assertStringContainsString('<PlatformContrastList :rows="preview.platform_contrast" />', $panel);
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
