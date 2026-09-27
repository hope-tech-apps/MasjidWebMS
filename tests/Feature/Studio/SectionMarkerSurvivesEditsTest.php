<?php

namespace Tests\Feature\Studio;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * Studio's marker (`settings.studio`) is what the page builder's checklist
 * reads (W2 S10). A save that dropped `settings` would erase it silently, so
 * every write path PageSectionsView uses is driven here in the client's own
 * encoding: SectionFormModal's form POST with _method=PUT (a save, and an
 * active toggle, which is the same save), and the reorder's form-encoded PUT
 * of `order` alone (existing-org recon U3, R8).
 */
class SectionMarkerSurvivesEditsTest extends TestCase
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
    public function saving_toggling_and_reordering_a_section_keep_its_studio_marker(): void
    {
        $id = (int) $this->provision($this->draftWith($this->studioAnswers('masjid', self::MINIMAL))->id)
            ->assertCreated()->json('data.masjid_id');
        $page = Page::where('masjid_id', $id)->where('slug', 'home')->firstOrFail();
        $section = $page->sections()->orderBy('page_section.order')->get()
            ->first(fn (Section $s) => isset($s->settings['studio']));
        $this->assertNotNull($section, 'the premise: a starter section with a marker');
        $marker = $section->settings['studio'];
        $url = "/api/admin/masjids/{$id}/pages/{$page->id}/sections/{$section->id}";

        // The admin section payload hands the marker to the modal, which sends it back.
        $shown = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame($marker, $shown['settings']['studio']);

        $save = fn (bool $active, string $title) => $this->post($url, [
            '_method' => 'PUT',
            'section_type' => $shown['section_type'],
            'title' => $title,
            'content' => json_encode($shown['content']),
            'order' => (string) $shown['order'],
            'platforms' => json_encode(['web', 'mobile']),
            'is_active' => $active ? '1' : '0',
            'settings' => json_encode($shown['settings']),
        ], ['Accept' => 'application/json'])->assertOk();

        $save((bool) $shown['is_active'], 'Edited title');
        $this->assertSame($marker, Section::findOrFail($section->id)->settings['studio'], 'after a save');

        $save(! $shown['is_active'], 'Edited title');
        $this->assertSame($marker, Section::findOrFail($section->id)->settings['studio'], 'after toggling active');

        // PageSectionsView's reorder: one form-encoded PUT of `order` per section.
        $this->put($url, ['order' => '5'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($marker, Section::findOrFail($section->id)->settings['studio'], 'after a reorder');
    }
}
