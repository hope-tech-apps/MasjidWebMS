<?php

namespace App\Support\Studio;

use App\Enums\SectionType;
use App\Models\Masjid;

/**
 * Turns a layout preset and a client's facts into the starter website it would
 * write (docs/manara-studio-w1.md S4; the rules are the layouts recon's §B, §C
 * and §G, carried over).
 *
 * plan() is pure: it reads config and the in-memory organisation and writes
 * nothing, so Studio's preview can call it on an unsaved Masjid and S8's
 * writer can call it on the real one and get the same answer.
 *
 * ACTIVATION RULES
 *
 *   1. A section is omitted when its type's module is off for the organisation
 *      (SectionType::requiresModule, Masjid::moduleIsOff), or when its
 *      `omit_if_empty` field resolves empty.
 *   2. A section is inactive when it is a review block or any essential
 *      placeholder is open; otherwise it is active. Nothing is ever activated
 *      later by the system: publishing is a person's act.
 *   3. A page other than home is omitted when nothing but its page_title
 *      banner is left.
 *   4. A page is active only when a section other than its banner is active;
 *      a `show_as_button` page (Donate) is a button only while it is active.
 *   5. Home is always written and always active, and its hero is always
 *      active, because an empty home page renders an infinite spinner
 *      (renderer app/pages/index.vue:40-42).
 *
 * Links between pages are settled last, because they depend on 3 and 4. A
 * banner keeps its place and loses a button whose page is not live; any other
 * section whose button would open a page that was omitted is omitted, and one
 * whose page is written but inactive is written inactive, with an open
 * `linked_page` placeholder. So no starter section ever links to a page the
 * public site does not serve.
 *
 * THE NO-INVENTION RULE (D4, D8) is enforced here as well as in the tests:
 * any literal a template holds other than '', null or [] must be a value
 * STRUCTURAL allows for that section type and key, or plan() throws.
 */
final class StarterSite
{
    public const HOME = 'home';

    /** The block every page after home opens with (config/studio_layouts.php). */
    public const HEADER_BLOCK = 'header';

    /** The one hint used for a section held back by the page its button opens. */
    public const LINKED_PAGE_HINT = 'linked_page';

    /** The template leaf kinds; a leaf is an array with exactly one of these keys. */
    public const LEAF_KINDS = ['fact', 'label', 'page_path', 'page_id', 'form_template'];

    /**
     * The only literal values a starter section may hold, by section type and
     * content key (`links.*.icon` is a key of each item in `links`). A list is
     * the allowed set; 'int' and 'bool' admit any value of that type. Every
     * value here is a layout enum, a count or a switch: none of them says
     * anything about an organisation.
     */
    public const STRUCTURAL = [
        'page_title' => ['layout' => ['hero', 'hero_compact']],
        'contact_form' => ['show_map' => 'bool'],
        'events' => ['items_per_page' => 'int'],
        'gallery' => ['layout' => ['masonry'], 'items_per_page' => 'int', 'columns' => 'int', 'enable_lightbox' => 'bool'],
        'mission_vision' => ['layout' => ['side_by_side']],
        'link_list' => [
            'layout' => ['inline', 'stack'],
            'links.*.icon' => ['phone', 'email', 'external', 'youtube', 'whatsapp'],
            'links.*.style' => ['primary', 'secondary', 'outline'],
        ],
        'cta' => ['layout' => ['gradient'], 'button_style' => ['primary']],
        'programs' => ['layout' => ['cards', 'list', 'accordion'], 'columns' => 'int'],
        'staff_directory' => ['layout' => ['grid', 'list'], 'columns' => 'int', 'show_contact' => [false]],
        'providers_directory' => ['layout' => ['grid', 'list'], 'columns' => 'int'],
        'services_eligibility' => ['layout' => ['cards', 'list'], 'columns' => 'int'],
    ];

    /**
     * Content keys the renderer reads that SectionType::defaultContent() does
     * not declare, by type. page_title's hero keys are opt-in extras
     * (renderer app/components/section/PageTitle.vue), and cta's `layout`
     * picks its gradient variant (CTA.vue).
     */
    public const RENDERER_EXTRAS = [
        'page_title' => ['layout', 'subtitle', 'description', 'button_text', 'button_link', 'eyebrow', 'title_accent', 'logo_url'],
        'cta' => ['layout'],
    ];

    /**
     * The starter website `$presetKey` would write for `$org`, from `$f`.
     *
     * @throws \InvalidArgumentException when the preset does not exist or
     *                                   belongs to another vertical
     * @throws \LogicException when the config breaks the no-invention rule or
     *                         names a block, label or hint that does not exist
     */
    public static function plan(Masjid $org, string $presetKey, StarterFacts $f): StarterPlan
    {
        $preset = LayoutPresets::find($presetKey);

        if ($preset === null) {
            throw new \InvalidArgumentException("\"{$presetKey}\" is not a layout preset.");
        }

        if (LayoutPresets::orgTypeOf($presetKey) !== $org->orgType()) {
            throw new \InvalidArgumentException("\"{$presetKey}\" is not offered to a {$org->orgType()} organisation.");
        }

        $labels = (array) config("studio_layouts.labels.{$f->locale}", []);
        if ($labels === []) {
            throw new \LogicException("There are no starter labels for the locale \"{$f->locale}\".");
        }

        $pages = [];

        foreach (LayoutPresets::pagesOf($preset) as $index => $page) {
            $sections = [];

            foreach ($page['blocks'] as $name) {
                $section = self::section($org, $name, $page['slug'], $f, $labels);

                if ($section !== null) {
                    $sections[] = $section;
                }
            }

            $pages[] = [
                'slug' => $page['slug'],
                'title' => self::label($labels, "page.{$page['slug']}", $page['slug']),
                'order' => $index + 1,
                'is_active' => false,
                'show_in_menu' => $page['show_in_menu'],
                'show_as_button' => $page['show_as_button'],
                'button_when_active' => $page['show_as_button'],
                'meta_description' => $page['slug'] === self::HOME && $f->description !== '' ? $f->description : null,
                'sections' => $sections,
            ];
        }

        $pages = self::settle($pages, $labels);

        foreach ($pages as &$page) {
            unset($page['button_when_active']);

            foreach ($page['sections'] as &$section) {
                unset($section['links']);
            }
            unset($section);
        }
        unset($page);

        return new StarterPlan($presetKey, $f->locale, $pages);
    }

    /**
     * Whether a literal may appear at `$path` in a section of `$type`. '', null
     * and [] may appear anywhere.
     */
    public static function allows(string $type, string $path, mixed $value): bool
    {
        if ($value === '' || $value === null || $value === []) {
            return true;
        }

        $allowed = self::STRUCTURAL[$type][$path] ?? null;

        return match (true) {
            $allowed === 'int' => is_int($value),
            $allowed === 'bool' => is_bool($value),
            is_array($allowed) => in_array($value, $allowed, true),
            default => false,
        };
    }

    /** Whether an array is a template leaf: exactly one key, and a leaf kind. */
    public static function isLeaf(mixed $node): bool
    {
        return is_array($node) && count($node) === 1 && in_array(array_key_first($node), self::LEAF_KINDS, true);
    }

    /**
     * One block resolved on one page, or null when rule 1 omits it.
     *
     * @param  array<string, string>  $labels
     * @return array<string, mixed>|null
     */
    private static function section(Masjid $org, string $name, string $slug, StarterFacts $f, array $labels): ?array
    {
        $block = config("studio_layouts.blocks.{$name}");

        if (! is_array($block)) {
            throw new \LogicException("The layout block \"{$name}\" does not exist.");
        }

        $type = SectionType::from((string) $block['type']);
        $module = $type->requiresModule();

        if ($module !== null && $org->moduleIsOff($module)) {
            return null;
        }

        $context = ['type' => $type->value, 'slug' => $slug, 'facts' => $f, 'labels' => $labels, 'links' => [], 'refs' => []];
        $content = self::resolve($block['content'] ?? [], '', $context);

        if (isset($block['omit_if_empty']) && self::isEmpty(data_get($content, $block['omit_if_empty']))) {
            return null;
        }

        $review = (bool) ($block['review'] ?? false);
        $placeholders = [];

        foreach (['essential' => true, 'optional' => false] as $group => $essential) {
            foreach ((array) ($block[$group] ?? []) as $placeholder) {
                $placeholders[] = self::placeholder($placeholder, $essential, self::isOpen($placeholder, $content, $f, $review));
            }
        }

        $blocked = array_filter($placeholders, fn (array $p) => $p['essential'] && $p['open']);

        return [
            'slot' => "{$slug}/{$name}",
            'section_type' => $type->value,
            'title' => self::resolveLabel((array) $block['title'], $context),
            'is_active' => ! $review && $blocked === [],
            'has_renderer' => $type->hasRenderer(),
            'content' => $content,
            'placeholders' => $placeholders,
            'refs' => $context['refs'],
            'links' => $context['links'],
        ];
    }

    /**
     * A template node resolved against the facts: leaves become values,
     * `links` items whose `when` fact is empty are dropped, and every other
     * literal must be one STRUCTURAL allows.
     *
     * @param  array<string, mixed>  $context
     */
    private static function resolve(mixed $node, string $path, array &$context): mixed
    {
        if (! is_array($node)) {
            if (! self::allows($context['type'], $path, $node)) {
                throw new \LogicException(sprintf(
                    'The %s template holds %s at "%s", which is neither a fact, a label, a page or form reference, a structural value nor empty.',
                    $context['type'], var_export($node, true), $path,
                ));
            }

            return $node;
        }

        if ($node === []) {
            return [];
        }

        if (self::isLeaf($node)) {
            return self::resolveLeaf($node, $path, $context);
        }

        $out = [];

        if (array_is_list($node)) {
            foreach ($node as $item) {
                if (is_array($item) && array_key_exists('when', $item)) {
                    if ($context['facts']->get((string) $item['when']) === '') {
                        continue;
                    }

                    unset($item['when']);
                }

                $out[] = self::resolve($item, "{$path}.*", $context);
            }

            return $out;
        }

        foreach ($node as $key => $value) {
            $out[$key] = self::resolve($value, $path === '' ? (string) $key : "{$path}.{$key}", $context);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $context */
    private static function resolveLeaf(array $leaf, string $path, array &$context): mixed
    {
        $kind = array_key_first($leaf);
        $value = (string) $leaf[$kind];

        switch ($kind) {
            case 'fact':
                return $context['facts']->get($value);

            case 'label':
                return self::resolveLabel($leaf, $context);

            case 'page_path':
                $context['links'][] = ['field' => $path, 'page' => $value];

                return '/' . $value;

            case 'page_id':
                $context['refs'][] = ['field' => $path, 'page' => $value];

                return null;

            default: // form_template
                $context['refs'][] = ['field' => $path, 'form_template' => $value];

                return null;
        }
    }

    /**
     * @param  array<string, mixed>  $leaf  {label: key}, where `{slug}` is the page's own slug
     * @param  array<string, mixed>  $context
     */
    private static function resolveLabel(array $leaf, array $context): string
    {
        $key = str_replace('{slug}', $context['slug'], (string) ($leaf['label'] ?? ''));

        return self::label($context['labels'], $key);
    }

    /** @param  array<string, string>  $labels */
    private static function label(array $labels, string $key, ?string $fallbackFor = null): string
    {
        if (! array_key_exists($key, $labels)) {
            throw new \LogicException("The starter label \"{$key}\" does not exist" . ($fallbackFor ? " (page \"{$fallbackFor}\")" : '') . '.');
        }

        return (string) $labels[$key];
    }

    /**
     * @param  array<string, mixed>  $placeholder
     * @return array<string, mixed>
     */
    private static function placeholder(array $placeholder, bool $essential, bool $open): array
    {
        $hint = (string) $placeholder['hint'];
        $hints = (array) config('studio_layouts.hints', []);

        if (! array_key_exists($hint, $hints)) {
            throw new \LogicException("The placeholder hint \"{$hint}\" does not exist.");
        }

        $out = [
            'field' => (string) $placeholder['field'],
            'kind' => (string) $placeholder['kind'],
            'hint' => $hint,
            'hint_text' => (string) $hints[$hint],
            'essential' => $essential,
        ];

        if (isset($placeholder['source'])) {
            $out['source'] = (string) $placeholder['source'];
        }

        return $out + ['open' => $open];
    }

    /**
     * Open is computed, never stored: text, image and list placeholders are
     * open while the content at their field is empty; bound ones while the row
     * the binder reads has nothing to show; review ones while the section is
     * unpublished, which at plan time it always is.
     *
     * @param  array<string, mixed>  $placeholder
     * @param  array<string, mixed>  $content
     */
    private static function isOpen(array $placeholder, array $content, StarterFacts $f, bool $review): bool
    {
        return match ((string) $placeholder['kind']) {
            'bound' => ! $f->hasBound((string) $placeholder['source']),
            'review' => true,
            'text', 'image', 'list' => self::isEmpty(data_get($content, (string) $placeholder['field'])),
            default => throw new \LogicException("\"{$placeholder['kind']}\" is not a placeholder kind."),
        };
    }

    /**
     * Rules 3 and 4 and the page links, applied until nothing moves: omitting
     * a section can empty a page, and deactivating a page can hold back a
     * section on another page that links to it.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  array<string, string>  $labels
     * @return list<array<string, mixed>>
     */
    private static function settle(array $pages, array $labels): array
    {
        $hint = self::placeholder(
            ['field' => 'button_link', 'kind' => 'review', 'hint' => self::LINKED_PAGE_HINT],
            true,
            true,
        );

        do {
            $before = serialize($pages);

            // Rule 3.
            $pages = array_values(array_filter($pages, fn (array $page) => $page['slug'] === self::HOME
                || array_filter($page['sections'], fn (array $s) => $s['section_type'] !== SectionType::PAGE_TITLE->value) !== []));

            // Rule 4 (and 5 for home).
            foreach ($pages as &$page) {
                $page['is_active'] = $page['slug'] === self::HOME
                    || array_filter($page['sections'], fn (array $s) => $s['is_active'] && $s['section_type'] !== SectionType::PAGE_TITLE->value) !== [];
                $page['show_as_button'] = $page['button_when_active'] && $page['is_active'];
            }
            unset($page);

            $live = array_column($pages, 'is_active', 'slug');

            foreach ($pages as &$page) {
                $kept = [];

                foreach ($page['sections'] as $section) {
                    foreach ($section['links'] as $i => $link) {
                        $target = $live[$link['page']] ?? null;

                        if ($target === true) {
                            continue;
                        }

                        if ($section['section_type'] === SectionType::PAGE_TITLE->value) {
                            // A banner states facts; it keeps its place and
                            // loses only the button to a page that is not live.
                            data_set($section['content'], $link['field'], '');
                            data_set($section['content'], self::buttonTextFor($link['field']), '');
                            unset($section['links'][$i]);

                            continue;
                        }

                        if ($target === null) {
                            // Its whole purpose was a page this site will not have.
                            continue 2;
                        }

                        if ($section['is_active']) {
                            $section['is_active'] = false;
                            $section['placeholders'][] = $hint;
                        }
                    }

                    $kept[] = $section;
                }

                $page['sections'] = $kept;
            }
            unset($page);
        } while (serialize($pages) !== $before);

        return $pages;
    }

    /** The button label beside a link field: `button_link` => `button_text`. */
    private static function buttonTextFor(string $field): string
    {
        return preg_replace('/button_link$/', 'button_text', $field) ?? $field;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
