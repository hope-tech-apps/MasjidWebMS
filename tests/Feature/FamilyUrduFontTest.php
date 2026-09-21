<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The parent portal's Nastaliq face is for Urdu and nothing else.
 *
 * resources/vue-app/views/family/urduFont.css self-hosts Noto Nastaliq Urdu
 * and names it only under `:lang(ur)` (the portal set to Urdu) and
 * `[data-tx-lang="ur"]` (a teacher's words showing translated into Urdu).
 * That scoping is what the owner asked for twice over: Pashto, Dari and Arabic
 * are written in Naskh and must not be drawn in an Urdu face, and a parent
 * reading any other language must not download ~160 KB for a font they never
 * see — a browser fetches a @font-face only when a rendered element names it.
 *
 * There is no SPA test runner, so this reads the files. The stylesheet's
 * layout is part of the contract: plain `selector, selector { declarations }`
 * blocks, no nesting.
 */
class FamilyUrduFontTest extends TestCase
{
    private const CSS = 'resources/vue-app/views/family/urduFont.css';

    private function css(): string
    {
        $path = base_path(self::CSS);

        $this->assertFileExists($path);

        // Comments out: they mention the font freely and are not rules.
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
    }

    /** @return array<int,array{selectors:array<int,string>,body:string}> */
    private function rules(): array
    {
        $css = (string) preg_replace('/@import[^;]*;/', '', $this->css());

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

        $rules = [];

        foreach ($m as [, $selectors, $body]) {
            $rules[] = [
                'selectors' => array_map('trim', explode(',', trim($selectors))),
                'body' => $body,
            ];
        }

        $this->assertNotEmpty($rules, 'urduFont.css read back with no rules — has its layout changed?');

        return $rules;
    }

    #[Test]
    public function every_rule_that_names_nastaliq_is_scoped_to_urdu(): void
    {
        $naming = 0;

        foreach ($this->rules() as $rule) {
            if (! str_contains($rule['body'], 'Nastaliq')) {
                continue;
            }

            $naming++;

            foreach ($rule['selectors'] as $selector) {
                $this->assertMatchesRegularExpression(
                    '/^(:lang\(ur\)|\[data-tx-lang="ur"\])(\s|$)/',
                    $selector,
                    "'{$selector}' names Nastaliq without being scoped to Urdu",
                );
            }
        }

        // Both routes in: the Urdu portal, and a translation into Urdu.
        $this->assertGreaterThanOrEqual(2, $naming);
    }

    #[Test]
    public function no_other_script_is_ever_given_the_urdu_face(): void
    {
        foreach ($this->rules() as $rule) {
            if (! str_contains($rule['body'], 'Nastaliq')) {
                continue;
            }

            foreach ($rule['selectors'] as $selector) {
                $this->assertDoesNotMatchRegularExpression('/:lang\((ar|ps|fa|es|en)/', $selector);
                $this->assertDoesNotMatchRegularExpression('/data-tx-lang="(?!ur")/', $selector);
            }
        }

        // And Arabic under an Urdu page — the letter chips, which carry
        // lang="ar" — is explicitly put back to the ordinary face.
        $css = $this->css();
        $this->assertMatchesRegularExpression('/:lang\(ur\)\s+:lang\(ar\)[^{]*\{[^}]*font-family:\s*"Poppins"/', $css);
        $this->assertMatchesRegularExpression('/\[data-tx-lang="ur"\]\s+:lang\(ar\)[^{]*\{[^}]*font-family:\s*"Poppins"/', $css);
    }

    #[Test]
    public function the_font_is_self_hosted_and_only_the_arabic_script_regular_subset(): void
    {
        preg_match_all('/@import\s+"([^"]+)"/', $this->css(), $m);

        // One file: the Arabic-script subset at 400. Not index.css (every
        // subset), not a second weight — each is another ~155 KB.
        $this->assertSame(['@fontsource/noto-nastaliq-urdu/arabic-400.css'], $m[1]);

        // From node_modules, bundled by Vite onto this app's own origin — never
        // a font CDN, which the CSP would refuse on some hosts and which would
        // hand each Urdu-reading parent's IP to a third party.
        $this->assertStringNotContainsString('http', $this->css());
        $this->assertStringNotContainsString('@font-face', $this->css());

        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $this->assertArrayHasKey('@fontsource/noto-nastaliq-urdu', $package['dependencies'] ?? []);

        $lock = (string) file_get_contents(base_path('package-lock.json'));
        $this->assertStringContainsString('"node_modules/@fontsource/noto-nastaliq-urdu"', $lock);
    }

    #[Test]
    public function the_stylesheet_is_loaded_by_the_family_realm_and_nowhere_else(): void
    {
        $layout = (string) file_get_contents(base_path('resources/vue-app/layouts/FamilyLayout.vue'));
        $this->assertStringContainsString("import '@/views/family/urduFont.css';", $layout);

        $importers = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources')));

        foreach ($files as $file) {
            if (! $file->isFile() || ! preg_match('/\.(vue|ts|js|css|scss|php)$/', $file->getFilename())) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Imports only — comments elsewhere name the file freely.
            if (preg_match('/(?:^|\n)\s*(?:import\s+[\'"][^\'"]*urduFont\.css|@import\s+[\'"][^\'"]*noto-nastaliq-urdu)/', $source)) {
                $importers[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        sort($importers);

        $this->assertSame(
            ['resources/vue-app/layouts/FamilyLayout.vue', 'resources/vue-app/views/family/urduFont.css'],
            $importers,
        );
    }

    #[Test]
    public function the_screens_that_show_translations_tell_the_stylesheet_which_language(): void
    {
        foreach (['FamilyClass', 'FamilyHome'] as $view) {
            $source = (string) file_get_contents(base_path("resources/vue-app/views/family/{$view}.vue"));

            $this->assertStringContainsString(':data-tx-lang="translatedInto ?? undefined"', $source, $view);
            $this->assertStringContainsString('showingLang: translatedInto', $source, $view);
        }
    }
}
