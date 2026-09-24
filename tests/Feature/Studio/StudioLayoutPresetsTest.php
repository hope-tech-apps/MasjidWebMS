<?php

namespace Tests\Feature\Studio;

use App\Enums\SectionType;
use App\Models\Masjid;
use App\Support\Studio\LayoutPresets;
use App\Support\Studio\StarterFacts;
use App\Support\Studio\StarterPlan;
use App\Support\Studio\StarterSite;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/studio_layouts.php is data a person edits, and what it holds is
 * published on a new client's website. These tests are the rules that keep it
 * honest (docs/manara-studio-w1.md S4; docs/manara-studio.md D4, D8): every
 * template leaf is a fact, a label, a page or form reference, a structural
 * value or empty, and every resolved plan contains only strings that can be
 * traced to one of those.
 *
 * No database: presets and plans are pure, and are computed on unsaved
 * organisations exactly as Studio's preview computes them.
 */
class StudioLayoutPresetsTest extends TestCase
{
    private const LABELS_FIXTURE = __DIR__ . '/../../fixtures/studio-layout-labels.json';

    /** Types no preset may use: each needs facts Studio does not collect, or numbers. */
    private const FORBIDDEN_TYPES = ['stats', 'impact_stats', 'carousel', 'embed', 'offering', 'services_list', 'image', 'text', 'grid_cards'];

    /** The documented item shape of link_list.links[] (SectionType::defaultContent). */
    private const LINK_ITEM_KEYS = ['label', 'url', 'icon', 'style'];

    /** Sentinel facts, distinctive enough that any copy of one is unmistakable. */
    private const MINIMAL = [
        'name' => 'FACT-NAME-7f3a',
        'email' => 'fact-email-7f3a@example.test',
        'phone' => '+1 555 0100 73',
        'address' => 'FACT-ADDRESS-7f3a',
    ];

    private const MAXIMAL = self::MINIMAL + [
        'description' => 'FACT-DESCRIPTION-7f3a',
        'facebook_url' => 'https://facebook.example/fact-7f3a',
        'instagram_url' => 'https://instagram.example/fact-7f3a',
        'youtube_url' => 'https://youtube.example/fact-7f3a',
        'whatsapp_url' => 'https://wa.example/fact-7f3a',
        'about' => 'FACT-ABOUT-7f3a',
        'mission' => 'FACT-MISSION-7f3a',
        'vision' => 'FACT-VISION-7f3a',
        'donation_link' => 'https://give.example/fact-7f3a',
    ];

    /** Organisation prose and the donation link: bound at serve time, never copied. */
    private const NEVER_COPIED = ['about', 'mission', 'vision', 'donation_link'];

    #[Test]
    public function every_org_type_has_exactly_three_presets_and_a_default_that_is_one_of_them(): void
    {
        $this->assertSame(
            ['version', 'labels', 'hints', 'blocks', 'presets', 'defaults'],
            array_keys(config('studio_layouts')),
        );

        $this->assertSame(
            ['masjid' => 'masjid.classic', 'school' => 'school.essentials', 'community' => 'community.essentials'],
            config('studio_layouts.defaults'),
        );

        $expected = [
            'masjid' => ['masjid.essentials', 'masjid.classic', 'masjid.gathering'],
            'school' => ['school.essentials', 'school.prospectus', 'school.community'],
            'community' => ['community.essentials', 'community.services', 'community.gathering'],
        ];

        $offered = [];
        foreach (Masjid::ORG_TYPES as $orgType) {
            $keys = LayoutPresets::keysFor($orgType);

            $this->assertSame($expected[$orgType], $keys, "{$orgType} is not offered exactly its three presets");
            $this->assertContains(LayoutPresets::defaultFor($orgType), $keys, "{$orgType}'s default is not one of its presets");

            $offered = array_merge($offered, $keys);
        }

        // No preset is filed under a vertical that does not exist.
        $this->assertEqualsCanonicalizing($offered, array_keys(config('studio_layouts.presets')));

        // Three visibly different sites, from the two header and two footer
        // variants the renderer already draws.
        $layouts = [
            'masjid.essentials' => ['header' => 'default', 'footer' => 'default'],
            'school.essentials' => ['header' => 'default', 'footer' => 'default'],
            'community.essentials' => ['header' => 'default', 'footer' => 'default'],
            'masjid.classic' => ['header' => 'default', 'footer' => 'columns'],
            'school.prospectus' => ['header' => 'default', 'footer' => 'columns'],
            'community.services' => ['header' => 'default', 'footer' => 'columns'],
            'masjid.gathering' => ['header' => 'overlay', 'footer' => 'columns'],
            'school.community' => ['header' => 'overlay', 'footer' => 'columns'],
            'community.gathering' => ['header' => 'overlay', 'footer' => 'columns'],
        ];

        foreach ($layouts as $key => $themeLayout) {
            $this->assertSame($themeLayout, LayoutPresets::find($key)['theme_layout'], "{$key}'s theme_layout");
        }

        // What Step 2's cards read carries the same keys and default.
        foreach (LayoutPresets::optionsPayload() as $orgType => $cards) {
            $this->assertSame($expected[$orgType], array_column($cards, 'key'));
            $this->assertSame([LayoutPresets::defaultFor($orgType)], array_column(array_filter($cards, fn ($c) => $c['is_default']), 'key'));
        }
    }

    #[Test]
    public function every_block_type_is_a_SectionType_case_the_renderer_draws(): void
    {
        foreach ($this->usedBlocks() as $name => $block) {
            $type = SectionType::tryFrom((string) $block['type']);

            $this->assertNotNull($type, "block {$name}'s type \"{$block['type']}\" is not a SectionType");
            $this->assertTrue($type->hasRenderer(), "block {$name} is a {$type->value}, which the renderer does not draw");
            $this->assertNotContains($type->value, self::FORBIDDEN_TYPES, "block {$name} uses {$type->value}, which needs facts Studio does not collect");
        }
    }

    #[Test]
    public function every_content_key_is_in_defaultContent_or_the_documented_renderer_extras(): void
    {
        foreach ($this->usedBlocks() as $name => $block) {
            $type = SectionType::from((string) $block['type']);
            $defaults = $type->defaultContent();
            $extras = StarterSite::RENDERER_EXTRAS[$type->value] ?? [];

            $this->assertSame(
                [],
                array_values(array_diff(array_keys($block['content']), array_keys($defaults), $extras)),
                "block {$name} stores keys neither defaultContent() nor the renderer knows",
            );

            // Covering every stored key means no editor or binder default
            // ('Photo Gallery', '#2c5f2d', 'Get Started') is filled in for the client.
            $this->assertSame(
                [],
                array_values(array_diff(array_keys($defaults), array_keys($block['content']))),
                "block {$name} leaves defaultContent() keys unset",
            );

            foreach ($block['content'] as $key => $value) {
                if (is_array($value) && ! StarterSite::isLeaf($value) && ! array_is_list($value) && is_array($defaults[$key] ?? null)) {
                    $this->assertSame(array_keys($defaults[$key]), array_keys($value), "block {$name}'s {$key} is not defaultContent()'s shape");
                }
            }

            foreach ((array) ($block['content']['links'] ?? []) as $item) {
                $this->assertSame([], array_values(array_diff(array_keys($item), array_merge(self::LINK_ITEM_KEYS, ['when']))), "block {$name} has a link item key the renderer does not read");
            }
        }
    }

    #[Test]
    public function every_string_leaf_is_a_fact_label_page_ref_form_ref_structural_value_or_empty(): void
    {
        $labels = config('studio_layouts.labels.en');

        foreach (config('studio_layouts.blocks') as $name => $block) {
            $this->assertTrue(StarterSite::isLeaf($block['title']) && isset($block['title']['label']), "block {$name}'s title is not a label");

            $this->walkTemplate($block['content'], '', function (string $path, mixed $node) use ($name, $block, $labels) {
                if (StarterSite::isLeaf($node)) {
                    $kind = array_key_first($node);
                    $value = $node[$kind];

                    match ($kind) {
                        'fact' => $this->assertContains($value, StarterFacts::KEYS, "block {$name} {$path} names an unknown fact"),
                        'label' => $this->assertTrue(str_contains($value, '{slug}') || array_key_exists($value, $labels), "block {$name} {$path} names an unknown label {$value}"),
                        default => $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string) $value, "block {$name} {$path} names no page or form"),
                    };

                    return;
                }

                $this->assertTrue(
                    StarterSite::allows($block['type'], $path, $node),
                    "block {$name} holds " . var_export($node, true) . " at {$path}: invented text, or a value STRUCTURAL does not allow",
                );
            });

            foreach ((array) ($block['content']['links'] ?? []) as $item) {
                $this->assertContains($item['when'], StarterFacts::KEYS, "a {$name} link is gated on an unknown fact");
            }
        }
    }

    #[Test]
    public function resolved_plans_contain_only_provenanced_strings(): void
    {
        $labels = array_values(config('studio_layouts.labels.en'));
        $structural = $this->structuralStrings();

        foreach (['minimal' => self::MINIMAL, 'maximal' => self::MAXIMAL] as $fixture => $input) {
            $facts = StarterFacts::fromArray($input);
            $factValues = [];
            foreach (StarterFacts::KEYS as $key) {
                $factValues[] = $facts->get($key);
            }

            foreach (Masjid::ORG_TYPES as $orgType) {
                foreach (LayoutPresets::keysFor($orgType) as $key) {
                    $plan = StarterSite::plan($this->unsavedOrg($orgType), $key, $facts);
                    $paths = array_map(fn ($slug) => "/{$slug}", array_column(LayoutPresets::pagesOf(LayoutPresets::find($key)), 'slug'));
                    $allowed = array_filter(array_merge($factValues, $labels, $paths, $structural), fn ($s) => $s !== '');

                    foreach ($this->publishedStrings($plan) as $where => $string) {
                        $this->assertContains($string, $allowed, "{$key} ({$fixture}) publishes \"{$string}\" at {$where}, which is no fact, label, page path or structural value");
                    }

                    $json = json_encode($plan->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    foreach (self::NEVER_COPIED as $prose) {
                        if (isset($input[$prose])) {
                            $this->assertStringNotContainsString($input[$prose], $json, "{$key} ({$fixture}) copied the client's {$prose} instead of binding it");
                        }
                    }
                }
            }
        }

        // The sentinels did reach the plan where a fact belongs, so the walk
        // above was not vacuously passing over empty pages.
        $plan = StarterSite::plan($this->unsavedOrg('masjid'), 'masjid.gathering', StarterFacts::fromArray(self::MAXIMAL));
        $this->assertSame('FACT-NAME-7f3a', $plan->section('home/hero')['content']['title']);
        $this->assertSame('FACT-DESCRIPTION-7f3a', $plan->section('home/hero')['content']['subtitle']);
        $this->assertSame('FACT-DESCRIPTION-7f3a', $plan->page('home')['meta_description']);
        $this->assertSame(
            ['tel:+1555010073', 'mailto:fact-email-7f3a@example.test', 'https://facebook.example/fact-7f3a', 'https://instagram.example/fact-7f3a', 'https://youtube.example/fact-7f3a', 'https://wa.example/fact-7f3a'],
            array_column($plan->section('home/connect')['content']['links'], 'url'),
        );
    }

    #[Test]
    public function labels_have_every_key_contain_no_digits_and_match_the_pinned_snapshot(): void
    {
        // R15: English only in W1.
        $this->assertSame(['en'], array_keys(config('studio_layouts.labels')));

        $labels = config('studio_layouts.labels.en');

        foreach ($labels as $key => $value) {
            $this->assertIsString($value);
            $this->assertNotSame('', trim($value), "label {$key} is empty");
            $this->assertDoesNotMatchRegularExpression('/\d/', $value, "label {$key} holds a digit, which is a number, not an interface noun");
        }

        // Every key a preset reaches exists, including each page's own title.
        foreach (config('studio_layouts.presets') as $key => $preset) {
            foreach (LayoutPresets::pagesOf($preset) as $page) {
                $this->assertArrayHasKey("page.{$page['slug']}", $labels, "{$key}'s page {$page['slug']} has no label");

                foreach ($page['blocks'] as $name) {
                    $block = config("studio_layouts.blocks.{$name}");
                    $this->walkTemplate(['title' => $block['title'], 'content' => $block['content']], '', function (string $path, mixed $node) use ($labels, $page, $key, $name) {
                        if (StarterSite::isLeaf($node) && array_key_first($node) === 'label') {
                            $label = str_replace('{slug}', $page['slug'], $node['label']);
                            $this->assertArrayHasKey($label, $labels, "{$key} {$page['slug']}/{$name} names the missing label {$label}");
                        }
                    });
                }
            }
        }

        $snapshot = json_decode((string) file_get_contents(self::LABELS_FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($snapshot, config('studio_layouts.labels'), 'the published label table changed; review the words, then update the fixture in the same commit');
    }

    #[Test]
    public function every_page_ref_and_form_ref_resolves_within_the_preset_and_vertical(): void
    {
        foreach (config('studio_layouts.presets') as $key => $preset) {
            $orgType = LayoutPresets::orgTypeOf($key);
            $slugs = array_column(LayoutPresets::pagesOf($preset), 'slug');
            $forms = array_column((array) config("form_templates.{$orgType}", []), 'slug');

            $this->assertSame(StarterSite::HOME, $slugs[0] ?? null, "{$key} does not open with home");
            $this->assertSame($slugs, array_values(array_unique($slugs)), "{$key} writes a slug twice");

            foreach (LayoutPresets::pagesOf($preset) as $page) {
                foreach ($page['blocks'] as $name) {
                    $this->walkTemplate(config("studio_layouts.blocks.{$name}.content"), '', function (string $path, mixed $node) use ($key, $slugs, $forms, $orgType, $page, $name) {
                        if (! StarterSite::isLeaf($node)) {
                            return;
                        }

                        $kind = array_key_first($node);

                        if (in_array($kind, ['page_path', 'page_id'], true)) {
                            $this->assertContains($node[$kind], $slugs, "{$key} {$page['slug']}/{$name} links to a page the preset does not write");
                        }

                        if ($kind === 'form_template') {
                            $this->assertContains($node[$kind], $forms, "{$key} {$page['slug']}/{$name} places a form a {$orgType} is not seeded with");
                        }
                    });
                }
            }
        }
    }

    #[Test]
    public function every_background_color_is_empty_and_every_image_field_is_null(): void
    {
        foreach (config('studio_layouts.blocks') as $name => $block) {
            $this->walkTemplate($block['content'], '', function (string $path, mixed $node) use ($name) {
                $key = substr($path, (int) strrpos('.' . $path, '.'));

                if ($key === 'background_color') {
                    // Follows the theme (D13), never a colour chosen here.
                    $this->assertSame('', $node, "block {$name} sets {$path}");
                }

                if (str_ends_with($key, 'image_url') || str_ends_with($key, 'photo_url')) {
                    // Imagery is the client's own upload or nothing (D13).
                    $this->assertNull($node, "block {$name} sets {$path}");
                }

                if ($key === 'logo_url') {
                    // The logo comes from /settings and is not copied.
                    $this->assertSame('', $node, "block {$name} sets {$path}");
                }
            });
        }
    }

    #[Test]
    public function every_preset_home_has_an_active_hero_under_the_minimal_required_facts(): void
    {
        $facts = StarterFacts::fromArray(self::MINIMAL);

        foreach (Masjid::ORG_TYPES as $orgType) {
            // As provisioned, and with every module switched off: neither may
            // leave home without an active section, or the renderer spins forever.
            $everythingOff = array_fill_keys(Masjid::MODULE_KEYS, false);

            foreach (['defaults' => $this->unsavedOrg($orgType), 'every module off' => $this->unsavedOrg($orgType, $everythingOff)] as $state => $org) {
                foreach (LayoutPresets::keysFor($orgType) as $key) {
                    $plan = StarterSite::plan($org, $key, $facts);
                    $home = $plan->page(StarterSite::HOME);

                    $this->assertNotNull($home, "{$key} ({$state}) writes no home page");
                    $this->assertTrue($home['is_active'], "{$key} ({$state}) writes home inactive");

                    $hero = $home['sections'][0] ?? null;
                    $this->assertSame('home/hero', $hero['slot'] ?? null, "{$key} ({$state}) home does not open with the hero");
                    $this->assertSame(SectionType::PAGE_TITLE->value, $hero['section_type']);
                    $this->assertTrue($hero['is_active'], "{$key} ({$state}) writes the hero inactive");
                    $this->assertSame('FACT-NAME-7f3a', $hero['content']['title']);
                }
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, array<string, mixed>> every block a preset uses, by name */
    private function usedBlocks(): array
    {
        $used = [];

        foreach (config('studio_layouts.presets') as $preset) {
            foreach (LayoutPresets::pagesOf($preset) as $page) {
                foreach ($page['blocks'] as $name) {
                    $used[$name] = config("studio_layouts.blocks.{$name}");
                }
            }
        }

        $this->assertNotEmpty($used);

        return $used;
    }

    /** Calls $visit on every template leaf and literal, with its dotted path (`links.*.icon`). */
    private function walkTemplate(mixed $node, string $path, callable $visit): void
    {
        if (! is_array($node) || $node === [] || StarterSite::isLeaf($node)) {
            $visit($path, $node);

            return;
        }

        foreach ($node as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    unset($value['when']);
                }
                $this->walkTemplate($value, "{$path}.*", $visit);
            } else {
                $this->walkTemplate($value, $path === '' ? (string) $key : "{$path}.{$key}", $visit);
            }
        }
    }

    /**
     * Every non-empty string a plan would publish: page titles and meta
     * descriptions, section titles and every string in section content.
     *
     * @return \Generator<string, string>
     */
    private function publishedStrings(StarterPlan $plan): \Generator
    {
        foreach ($plan->pages as $page) {
            foreach (['title', 'meta_description'] as $field) {
                if (is_string($page[$field]) && $page[$field] !== '') {
                    yield "{$page['slug']}.{$field}" => $page[$field];
                }
            }

            foreach ($page['sections'] as $section) {
                if ($section['title'] !== '') {
                    yield "{$section['slot']}.title" => $section['title'];
                }

                $strings = [];
                array_walk_recursive($section['content'], function ($value, $key) use (&$strings, $section) {
                    if (is_string($value) && $value !== '') {
                        $strings[] = [$section['slot'] . ".content.{$key}", $value];
                    }
                });

                foreach ($strings as [$where, $value]) {
                    yield $where => $value;
                }
            }
        }
    }

    /** @return list<string> */
    private function structuralStrings(): array
    {
        $out = [];

        foreach (StarterSite::STRUCTURAL as $keys) {
            foreach ($keys as $allowed) {
                if (is_array($allowed)) {
                    $out = array_merge($out, array_filter($allowed, 'is_string'));
                }
            }
        }

        return $out;
    }

    /** @param array<string, bool> $overrides */
    private function unsavedOrg(string $orgType, array $overrides = []): Masjid
    {
        $org = new Masjid(['name' => 'FACT-NAME-7f3a', 'org_type' => $orgType, 'crm_enabled' => true]);
        $org->forceFill(['capability_overrides' => $overrides === [] ? null : $overrides]);

        return $org;
    }
}
