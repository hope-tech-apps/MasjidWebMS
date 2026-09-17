<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SPA's tab title must come from the organisation at RUNTIME.
 *
 * For weeks every tab on production read "<page> | undefined": the router
 * appended `import.meta.env.VITE_APP_NAME`, and the deployed bundle is built
 * with no `.env` — deliberately, so `VITE_APP_URL` stays empty and API calls
 * stay same-origin on every host. The obvious repair (copy a `.env` in) would
 * have titled every organisation "Laravel" and put a `VITE_APP_URL` one careless
 * line away from the build. So the title is now `core/pageTitle.ts`, fed by the
 * layouts. This reads the files, as the other screen guards do: a Feature test
 * cannot render Vue, so it pins the wiring, not the pixels.
 */
class SpaTitleIsRuntimeTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    #[Test]
    public function no_spa_source_reads_the_build_time_app_name(): void
    {
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/vue-app')));

        foreach ($dir as $file) {
            if (! $file->isFile() || ! preg_match('/\.(ts|vue|js)$/', $file->getFilename())) {
                continue;
            }
            // Code only: a comment explaining the old bug may name it.
            $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', file_get_contents($file->getPathname()));
            $this->assertDoesNotMatchRegularExpression(
                '/import\.meta\.env\.VITE_APP_NAME|\bVITE_APP_NAME\s*[?]?:/',
                $code,
                $file->getPathname().' reads or declares VITE_APP_NAME, which is undefined in every deployed build'
            );
        }
    }

    #[Test]
    public function the_router_titles_pages_through_the_runtime_module(): void
    {
        $this->assertStringContainsString('setPageTitle(to.meta.pageTitle)', $this->read('resources/vue-app/router/router.ts'));
    }

    #[Test]
    public function every_shell_that_knows_its_organisation_names_the_tab_and_clears_it(): void
    {
        foreach ([
            'resources/vue-app/layouts/DashboardLayout.vue',
            'resources/vue-app/layouts/TeacherLayout.vue',
            'resources/vue-app/layouts/FamilyLayout.vue',
            'resources/vue-app/layouts/LunchLayout.vue',
            'resources/vue-app/views/portal/OrgPortal.vue',
        ] as $shell) {
            $source = $this->read($shell);
            $this->assertMatchesRegularExpression('/setOrgTitle\(name\)/', $source, "{$shell} never names the tab");
            $this->assertStringContainsString('onBeforeUnmount(() => setOrgTitle(null))', $source,
                "{$shell} leaves its organisation in the title after the user leaves it");
        }
    }

    #[Test]
    public function every_reader_of_the_api_url_says_what_happens_when_it_is_missing(): void
    {
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/vue-app')));

        foreach ($dir as $file) {
            if (! $file->isFile() || ! preg_match('/\.(ts|vue|js)$/', $file->getFilename())) {
                continue;
            }
            $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', file_get_contents($file->getPathname()));
            preg_match_all('/import\.meta\.env\.VITE_APP_URL(?!\s*\?\?)/', $code, $bare);
            $this->assertCount(0, $bare[0],
                $file->getPathname().' reads VITE_APP_URL without a fallback; it is undefined in every deployed build');
        }
    }
}
