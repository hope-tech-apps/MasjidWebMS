<?php

namespace App\Support\Studio;

use App\Models\Masjid;

/**
 * Reads config/studio_layouts.php: which starter websites each vertical is
 * offered at Studio's Step 2, and which one it starts on
 * (docs/manara-studio-w1.md S4).
 *
 * A preset's vertical is the part of its key before the dot
 * (`school.prospectus`), so a preset can never be filed under one org type and
 * offered to another. Everything here is a read of the config: the pages a
 * preset would write for a real client are StarterSite::plan()'s answer, which
 * also applies the client's switches and facts.
 */
final class LayoutPresets
{
    /**
     * Every preset offered to one org type, keyed by preset key, in config
     * order, each with its `key` added. An unknown org type is offered none.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forOrgType(string $orgType): array
    {
        $out = [];

        foreach (self::presets() as $key => $preset) {
            if (self::orgTypeOf($key) === $orgType) {
                $out[$key] = ['key' => $key] + $preset;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null the preset with its `key` added, or null when there is none */
    public static function find(string $key): ?array
    {
        $preset = self::presets()[$key] ?? null;

        return is_array($preset) ? ['key' => $key] + $preset : null;
    }

    /** @return list<string> the preset keys one org type may choose, in config order */
    public static function keysFor(string $orgType): array
    {
        return array_keys(self::forOrgType($orgType));
    }

    /**
     * The preset a draft of this org type starts on. An org type with no
     * default of its own reads as a masjid's, the way Masjid::orgType() degrades.
     */
    public static function defaultFor(string $orgType): string
    {
        $defaults = (array) config('studio_layouts.defaults', []);

        return (string) ($defaults[$orgType] ?? $defaults[Masjid::ORG_TYPE_MASJID] ?? '');
    }

    /** The vertical a preset key belongs to: the part before the first dot. */
    public static function orgTypeOf(string $key): string
    {
        return strstr($key, '.', true) ?: '';
    }

    /**
     * What Step 2's cards show, per org type: each preset's name, summary and
     * header and footer variants, and the pages and sections it can write
     * before any client's switches or facts are applied. Page titles are the
     * `en` labels (R15). The shape is config-derived only, so the SPA never
     * retypes a preset key, a label or a section type.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function optionsPayload(): array
    {
        $labels = (array) config('studio_layouts.labels.' . StarterFacts::DEFAULT_LOCALE, []);
        $blocks = (array) config('studio_layouts.blocks', []);
        $out = [];

        foreach (Masjid::ORG_TYPES as $orgType) {
            $default = self::defaultFor($orgType);
            $out[$orgType] = [];

            foreach (self::forOrgType($orgType) as $key => $preset) {
                $pages = [];

                foreach (self::pagesOf($preset) as $page) {
                    $sections = [];

                    foreach ($page['blocks'] as $name) {
                        $content = $blocks[$name]['content'] ?? [];

                        $sections[] = [
                            'slot' => "{$page['slug']}/{$name}",
                            'type' => $blocks[$name]['type'] ?? null,
                            'layout' => is_string($content['layout'] ?? null) ? $content['layout'] : null,
                        ];
                    }

                    $pages[] = [
                        'slug' => $page['slug'],
                        'title' => $labels["page.{$page['slug']}"] ?? $page['slug'],
                        'show_in_menu' => $page['show_in_menu'],
                        'show_as_button' => $page['show_as_button'],
                        'sections' => $sections,
                    ];
                }

                $out[$orgType][] = [
                    'key' => $key,
                    'label' => (string) ($preset['label'] ?? $key),
                    'summary' => (string) ($preset['summary'] ?? ''),
                    'is_default' => $key === $default,
                    'theme_layout' => $preset['theme_layout'] ?? null,
                    'pages' => $pages,
                ];
            }
        }

        return $out;
    }

    /**
     * A preset's pages as they are written: in menu order, each after home
     * opening with the `header` block, and with the menu flags filled in.
     *
     * @param  array<string, mixed>  $preset
     * @return list<array{slug: string, blocks: list<string>, show_in_menu: bool, show_as_button: bool}>
     */
    public static function pagesOf(array $preset): array
    {
        $pages = [];

        foreach ((array) ($preset['pages'] ?? []) as $page) {
            $slug = (string) $page['slug'];
            $blocks = array_values((array) ($page['blocks'] ?? []));

            $pages[] = [
                'slug' => $slug,
                'blocks' => $slug === StarterSite::HOME ? $blocks : array_merge([StarterSite::HEADER_BLOCK], $blocks),
                'show_in_menu' => (bool) ($page['show_in_menu'] ?? true),
                'show_as_button' => (bool) ($page['show_as_button'] ?? false),
            ];
        }

        return $pages;
    }

    /** @return array<string, array<string, mixed>> */
    private static function presets(): array
    {
        return (array) config('studio_layouts.presets', []);
    }
}
