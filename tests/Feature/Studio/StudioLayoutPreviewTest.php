<?php

namespace Tests\Feature\Studio;

use App\Models\Form;
use App\Models\Page;
use App\Models\Section;
use App\Models\ThemeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * What Step 2 showed is what Step 3 writes (docs/manara-studio-w1.md S4, S8,
 * R19). The preview's `web` plan and the provisioned rows come from one
 * derivation (StarterSite::plan over the same facts), so an operator never
 * approves one site and gets another.
 */
class StudioLayoutPreviewTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function preview_equals_what_provision_then_writes(): void
    {
        $cases = [
            ['masjid', self::MAXIMAL, 'masjid.gathering'],
            ['school', self::MINIMAL, 'school.essentials'],
            ['community', self::MAXIMAL, 'community.services'],
        ];

        foreach ($cases as [$orgType, $facts, $preset]) {
            $draft = $this->draftWith($this->studioAnswers($orgType, $facts, ['layout' => ['preset' => $preset, 'approved_at' => self::APPROVED_AT]]));

            $preview = $this->postJson(self::DRAFTS . "/{$draft->id}/preview", [])->assertOk()->json('data.web');
            $this->assertTrue($preview['approved'], 'the premise: this is the approved preset');

            $id = $this->provision($draft->id)->assertCreated()->json('data.masjid_id');

            $this->assertSame($this->expected($preview, $id), $this->written($id), "{$preset}: the rows differ from the preview");
            $this->assertSame($preview['theme_layout'], ThemeSetting::where('masjid_id', $id)->firstOrFail()->tokens['layout']);
        }
    }

    /** The preview's pages with the ids it could not know filled in. */
    private function expected(array $web, int $masjidId): array
    {
        $pageIds = Page::where('masjid_id', $masjidId)->pluck('id', 'slug')->all();
        $pages = [];

        foreach ($web['pages'] as $page) {
            $sections = [];

            foreach ($page['sections'] as $section) {
                $content = $section['content'];

                foreach ($section['refs'] as $ref) {
                    data_set($content, $ref['field'], isset($ref['page'])
                        ? $pageIds[$ref['page']]
                        : Form::where('masjid_id', $masjidId)->where('slug', $ref['form_template'])->value('id'));
                }

                $sections[] = [
                    'section_type' => $section['section_type'],
                    'title' => $section['title'],
                    'is_active' => $section['is_active'],
                    'content' => $content,
                    'placeholders' => array_map(
                        fn (array $p) => array_diff_key($p, ['open' => true, 'hint_text' => true]),
                        $section['placeholders'],
                    ),
                ];
            }

            $pages[] = [
                'slug' => $page['slug'],
                'title' => $page['title'],
                'order' => $page['order'],
                'is_active' => $page['is_active'],
                'show_in_menu' => $page['show_in_menu'],
                'show_as_button' => $page['show_as_button'],
                'meta_description' => $page['meta_description'],
                'sections' => $sections,
            ];
        }

        return $pages;
    }

    private function written(int $masjidId): array
    {
        return Page::where('masjid_id', $masjidId)->orderBy('order')->get()->map(fn (Page $page) => [
            'slug' => $page->slug,
            'title' => $page->title,
            'order' => $page->order,
            'is_active' => $page->is_active,
            'show_in_menu' => $page->show_in_menu,
            'show_as_button' => $page->show_as_button,
            'meta_description' => $page->meta_description,
            'sections' => $page->sections()->get()->map(fn (Section $s) => [
                'section_type' => $s->section_type->value,
                'title' => $s->title,
                'is_active' => $s->is_active,
                'content' => json_decode($s->getRawOriginal('content'), true),
                'placeholders' => array_map(
                    fn (array $p) => array_intersect_key($p, array_flip(['field', 'kind', 'hint', 'essential', 'source'])),
                    $s->settings['studio']['placeholders'],
                ),
            ])->all(),
        ])->all();
    }
}
