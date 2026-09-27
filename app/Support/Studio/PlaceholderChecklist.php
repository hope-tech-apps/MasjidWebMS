<?php

namespace App\Support\Studio;

use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;

/**
 * What a Studio organisation's admin still has to fill in on their own site
 * (Studio W2 S10): every section that carries Studio's marker
 * (`settings.studio`, StarterPlaceholders), with each placeholder and whether
 * it is open NOW.
 *
 * Open is computed here, at read time, by the same StarterPlaceholders::isOpen
 * StarterSite used at plan time, from the section's current content, the
 * organisation's current rows (StarterFacts::fromMasjid) and the section's
 * current state (a review placeholder awaits its review while the section is
 * inactive). At provision the two counts are equal.
 *
 * A live organisation has no marker anywhere (the S8 preflight count was 0),
 * so its checklist is empty and the page builder draws nothing. A marker this
 * cannot read, or a placeholder of a kind it does not know, is left out
 * rather than failing the page builder.
 *
 * Page and Section are hand-scoped, not BelongsToMasjid
 * (TenantScopingCoverageTest), so every query here filters by masjid_id.
 */
final class PlaceholderChecklist
{
    /**
     * @return array{open: int, essential_open: int, pages: list<array{page_id: int, slug: string, title: string, sections: list<array<string, mixed>>}>}
     */
    public static function forMasjid(Masjid $org): array
    {
        $facts = null;
        $hints = (array) config('studio_layouts.hints', []);
        $pages = [];
        $open = [];

        $rows = Page::query()
            ->where('masjid_id', $org->id)
            ->with(['sections' => fn ($q) => $q->where('sections.masjid_id', $org->id)])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        foreach ($rows as $page) {
            $sections = [];

            foreach ($page->sections->sortBy(fn (Section $s) => [(int) $s->pivot->order, (int) $s->id]) as $section) {
                $marker = self::marker($section->settings);

                if ($marker === null) {
                    continue;
                }

                $facts ??= StarterFacts::fromMasjid($org);
                // Raw, as StarterSite planned it: the model's accessor adds
                // derived keys (button_page_url) no placeholder names.
                $raw = json_decode((string) $section->getRawOriginal('content'), true);
                $content = is_array($raw) ? $raw : [];
                $awaitingReview = ! $section->is_active;
                $placeholders = [];

                foreach ($marker['placeholders'] as $index => $placeholder) {
                    if (! self::readable($placeholder)) {
                        continue;
                    }

                    $isOpen = StarterPlaceholders::isOpen($placeholder, $content, $facts, $awaitingReview);
                    $essential = (bool) $placeholder['essential'];
                    $hint = (string) $placeholder['hint'];

                    $placeholders[] = [
                        'field' => (string) $placeholder['field'],
                        'kind' => (string) $placeholder['kind'],
                        'hint' => $hint,
                        'hint_text' => (string) ($hints[$hint] ?? ''),
                        'essential' => $essential,
                        'open' => $isOpen,
                    ];

                    if ($isOpen) {
                        // By section id: a section shown on two pages still
                        // needs filling once.
                        $open["{$section->id}:{$index}"] = $essential;
                    }
                }

                $sections[] = [
                    'section_id' => (int) $section->id,
                    'title' => (string) $section->title,
                    'section_type' => (string) $section->getRawOriginal('section_type'),
                    'active' => (bool) $section->is_active,
                    'placeholders' => $placeholders,
                ];
            }

            if ($sections === []) {
                continue;
            }

            $pages[] = [
                'page_id' => (int) $page->id,
                'slug' => (string) $page->slug,
                'title' => (string) $page->title,
                'sections' => $sections,
            ];
        }

        return [
            'open' => count($open),
            'essential_open' => count(array_filter($open)),
            'pages' => $pages,
        ];
    }

    /**
     * The marker, when it is one this version can read.
     *
     * @return array{placeholders: list<array<string, mixed>>}|null
     */
    private static function marker(mixed $settings): ?array
    {
        $marker = is_array($settings) ? ($settings[StarterPlaceholders::KEY] ?? null) : null;

        if (! is_array($marker)
            || ($marker['version'] ?? null) !== StarterPlaceholders::VERSION
            || ! is_array($marker['placeholders'] ?? null)) {
            return null;
        }

        return ['placeholders' => array_values(array_filter($marker['placeholders'], 'is_array'))];
    }

    /** @param  array<string, mixed>  $placeholder */
    private static function readable(array $placeholder): bool
    {
        if (! in_array($placeholder['kind'] ?? null, StarterPlaceholders::KINDS, true)
            || ! is_string($placeholder['field'] ?? null)
            || ! is_string($placeholder['hint'] ?? null)
            || ! array_key_exists('essential', $placeholder)) {
            return false;
        }

        return $placeholder['kind'] !== 'bound' || is_string($placeholder['source'] ?? null);
    }
}
