<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use App\Support\AppMenu;
use App\Support\Studio\StudioPreview;
use PHPUnit\Framework\Attributes\Test;
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
 *  - the logo is prepared by prepareLogo, never by preparePhoto (which flattens
 *    onto white as JPEG), and the wizard's palette sampler was moved, not copied.
 *
 * Comments are stripped before the code checks, so a docblock may name what
 * the code must not contain.
 */
class StudioSpaSourceTest extends TestCase
{
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

    /** The keys of `export const NAME ... = { ... };` in core/studio/appLabels.ts. */
    private function objectKeys(string $constant): array
    {
        $source = $this->read(self::SPA . '/core/studio/appLabels.ts');

        $this->assertMatchesRegularExpression('/export const ' . $constant . '\b[^=]*=\s*\{(.*?)\n\};/s', $source, "appLabels.ts has no {$constant}");
        preg_match('/export const ' . $constant . '\b[^=]*=\s*\{(.*?)\n\};/s', $source, $body);
        preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:/m', $body[1], $keys);

        return $keys[1];
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

    /** Block, line and HTML comments out; a `//` inside a string such as https:// is kept. */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('~/\*.*?\*/|<!--.*?-->~s', '', $source);

        return preg_replace('~(?<![:"\'`\\\\])//[^\n]*~', '', $source);
    }

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);

        return file_get_contents($path);
    }
}
