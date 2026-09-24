<?php

namespace App\Support\Studio;

/**
 * The starter website one preset would write for one client: pages in menu
 * order, each with its sections in page order, their content already resolved
 * from the client's facts, and every placeholder with whether it is open
 * (docs/manara-studio-w1.md S4). StarterSite::plan() builds it without writing
 * anything; Studio's preview shows it, and S8's writer writes exactly it.
 *
 * Page shape: {slug, title, order, is_active, show_in_menu, show_as_button,
 * meta_description, sections}. Section shape: {slot, section_type, title,
 * is_active, has_renderer, content, placeholders: [{field, kind, hint,
 * hint_text, essential, source?, open}], refs: [{field, page | form_template}]}.
 *
 * `refs` are the ids a plan cannot know before the organisation exists (a
 * page's id, a seeded form's id). Their content field holds null here and the
 * writer fills it once the rows exist.
 */
final readonly class StarterPlan
{
    /**
     * @param  list<array<string, mixed>>  $pages
     */
    public function __construct(
        public string $preset,
        public string $locale,
        public array $pages,
    ) {}

    /** @return array{preset: string, locale: string, pages: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'locale' => $this->locale,
            'pages' => $this->pages,
        ];
    }

    /** @return array<string, mixed>|null */
    public function page(string $slug): ?array
    {
        foreach ($this->pages as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null the section in slot "page/block" */
    public function section(string $slot): ?array
    {
        foreach ($this->pages as $page) {
            foreach ($page['sections'] as $section) {
                if ($section['slot'] === $slot) {
                    return $section;
                }
            }
        }

        return null;
    }
}
