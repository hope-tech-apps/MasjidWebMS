<?php

namespace Tests\Feature\Studio;

use App\Models\Form;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use App\Support\CapabilityCatalogue;
use App\Support\Studio\LayoutPresets;
use App\Support\Studio\StarterFacts;
use App\Support\Studio\StarterSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * StarterSite::applyTo, the writer behind `layout_preset`
 * (docs/manara-studio-w1.md S8, D8): it writes exactly the plan, from facts
 * only, never over anything that exists, and all or nothing.
 */
class StarterSiteWriterTest extends TestCase
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
    public function no_preset_writes_no_pages(): void
    {
        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        unset($payload['layout_preset']);

        $id = $this->postJson('/api/admin/onboarding/provision', $payload)->assertCreated()->json('data.masjid_id');

        $this->assertSame(0, Page::where('masjid_id', $id)->count());
        $this->assertSame(0, Section::where('masjid_id', $id)->count());
        $this->assertArrayNotHasKey('layout', Masjid::findOrFail($id)->themeSettings->tokens ?? [], 'no preset, no header or footer variant');
    }

    #[Test]
    public function pages_are_written_in_menu_order_with_the_presets_menu_flags(): void
    {
        $data = $this->provision($this->draftWith($this->studioAnswers())->id)->assertCreated()->json('data');

        $pages = Page::where('masjid_id', $data['masjid_id'])->orderBy('order')->get();
        $preset = array_column(LayoutPresets::pagesOf(LayoutPresets::find('masjid.classic')), null, 'slug');

        $this->assertSame(array_keys($preset), $pages->pluck('slug')->all(), 'every page of the preset, in menu order, for a client who gave every fact');
        $this->assertSame($pages->pluck('slug')->all(), $data['starter_site']['created']);
        $this->assertSame(range(1, count($preset)), $pages->pluck('order')->all());

        foreach ($pages as $page) {
            $this->assertSame($preset[$page->slug]['show_in_menu'], $page->show_in_menu, $page->slug);
            $this->assertSame($preset[$page->slug]['show_as_button'] && $page->is_active, $page->show_as_button, $page->slug);
        }

        // Sections sit on their page in the preset's block order.
        $home = $pages->firstWhere('slug', 'home');
        $this->assertSame(
            ['page_title', 'prayer_times', 'announcements_list', 'about_us', 'donation', 'contact_form'],
            $home->sections()->get()->map(fn (Section $s) => $s->section_type->value)->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], $home->sections()->get()->map(fn (Section $s) => $s->pivot->order)->all());
        $this->assertSame([null], DB::table('page_section')->distinct()->pluck('platforms')->all(), 'placements default to web and mobile');
    }

    #[Test]
    public function a_minimal_draft_publishes_only_fact_backed_sections(): void
    {
        $data = $this->provision($this->draftWith($this->studioAnswers(facts: self::MINIMAL))->id)->assertCreated()->json('data');
        $id = $data['masjid_id'];

        $active = Section::where('masjid_id', $id)->where('is_active', true)->get();
        $this->assertSame(
            ['announcements_list', 'announcements_list', 'contact_form', 'contact_form', 'events', 'gallery', 'page_title', 'page_title', 'page_title', 'page_title', 'page_title', 'page_title', 'page_title', 'prayer_times'],
            $active->map(fn (Section $s) => $s->section_type->value)->sort()->values()->all(),
            'every page\'s banner (the page\'s own label), the name, the contact facts, and the lists whose rows the org will add itself; nothing that needs prose the client did not give',
        );

        // About, mission and donation had no fact behind them: written, but held
        // back, and the pages left with nothing else live are held back too.
        $inactive = Section::where('masjid_id', $id)->where('is_active', false)->get();
        $this->assertEqualsCanonicalizing(['about_us', 'about_us', 'mission_vision', 'donation', 'donation'], $inactive->map(fn (Section $s) => $s->section_type->value)->all());
        $this->assertSame(['about', 'donate'], Page::where('masjid_id', $id)->where('is_active', false)->orderBy('order')->pluck('slug')->all());
        $this->assertSame($data['starter_site']['sections_active'], $active->count());
        $this->assertCount($inactive->count(), $data['starter_site']['sections_inactive']);

        $about = collect($data['starter_site']['sections_inactive'])->firstWhere('slot', 'about/about');
        $this->assertSame([config('studio_layouts.hints.about_text')], $about['hints'], 'the SPA is told why, in the operator\'s words');
    }

    #[Test]
    public function a_module_that_is_off_writes_no_section(): void
    {
        $map = ['gallery' => false, 'events' => false, 'prayer_times' => false] + CapabilityCatalogue::resolve('masjid', []);
        $data = $this->provision($this->draftWith($this->studioAnswers(sections: ['features' => ['capabilities' => $map]]))->id)->assertCreated()->json('data');

        $types = Section::where('masjid_id', $data['masjid_id'])->get()->map(fn (Section $s) => $s->section_type->value)->all();
        $this->assertNotContains('gallery', $types);
        $this->assertNotContains('events', $types);
        $this->assertNotContains('prayer_times', $types);

        $this->assertSame(['home', 'about', 'announcements', 'donate', 'contact'], Page::where('masjid_id', $data['masjid_id'])->orderBy('order')->pluck('slug')->all(), 'a page left with only its banner is not written');
    }

    #[Test]
    public function facts_are_copied_verbatim(): void
    {
        $facts = ['name' => 'Masjid  al-Nūr — Burlington', 'description' => 'Open daily; all are welcome.  '] + self::MAXIMAL;
        $data = $this->provision($this->draftWith($this->studioAnswers(facts: $facts))->id)->assertCreated()->json('data');

        $home = Page::where('masjid_id', $data['masjid_id'])->where('slug', 'home')->firstOrFail();
        $hero = $home->sections()->get()->first();

        $this->assertSame('Masjid  al-Nūr — Burlington', $hero->content['title'], 'the name as typed, inner spacing and all');
        $this->assertSame('Open daily; all are welcome.', $hero->content['subtitle'], 'trimmed, otherwise as typed');
        $this->assertSame('Open daily; all are welcome.', $home->meta_description);

        // Prose is bound at serve time, never copied into a section.
        $stored = json_encode(Section::where('masjid_id', $data['masjid_id'])->pluck('content')->all(), JSON_UNESCAPED_UNICODE);
        foreach (['FACT-ABOUT-7f3a', 'FACT-MISSION-7f3a', 'FACT-VISION-7f3a', 'https://give.example/fact-7f3a', 'VIBE-NEVER-PUBLISHED'] as $never) {
            $this->assertStringNotContainsString($never, $stored);
        }
    }

    #[Test]
    public function placeholders_are_recorded_in_settings_studio(): void
    {
        $data = $this->provision($this->draftWith($this->studioAnswers(facts: self::MINIMAL))->id)->assertCreated()->json('data');

        $about = Page::where('masjid_id', $data['masjid_id'])->where('slug', 'about')->firstOrFail()->sections()->get()->firstWhere('section_type.value', 'about_us');

        $this->assertSame([
            'version' => 1,
            'preset' => 'masjid.classic',
            'slot' => 'about/about',
            'placeholders' => [
                ['field' => 'text', 'kind' => 'bound', 'hint' => 'about_text', 'essential' => true, 'source' => 'masjid_about.about'],
                ['field' => 'image_url', 'kind' => 'image', 'hint' => 'about_image', 'essential' => false],
            ],
        ], $about->settings['studio'], 'what is missing, never whether it is open: that is computed');

        $this->assertSame(['studio'], array_keys($about->settings), 'nothing else is written to settings');
        $this->assertSame(0, Section::where('masjid_id', $data['masjid_id'])->whereNull('settings')->count(), 'every starter section carries the marker');
    }

    #[Test]
    public function the_admissions_form_is_written_inactive_and_points_at_the_seeded_form(): void
    {
        $data = $this->provision($this->draftWith($this->studioAnswers('school'))->id)->assertCreated()->json('data');

        $form = Section::where('masjid_id', $data['masjid_id'])->get()->first(fn (Section $s) => $s->section_type->value === 'form');
        $seeded = Form::where('masjid_id', $data['masjid_id'])->where('slug', 'admissions-interest')->firstOrFail();

        $this->assertFalse($form->is_active, 'placed, never published until someone has read it');
        $this->assertSame($seeded->id, $form->content['form_id']);
        $this->assertContains(config('studio_layouts.hints.admissions_form_review'), collect($data['starter_site']['sections_inactive'])->firstWhere('section_type', 'form')['hints']);
    }

    #[Test]
    public function a_rerun_never_overwrites(): void
    {
        $data = $this->provision($this->draftWith($this->studioAnswers())->id)->assertCreated()->json('data');
        $masjid = Masjid::findOrFail($data['masjid_id']);

        $about = Page::where('masjid_id', $masjid->id)->where('slug', 'about')->firstOrFail();
        $about->update(['title' => 'Who we are']);
        Page::where('masjid_id', $masjid->id)->where('slug', 'gallery')->firstOrFail()->delete();
        $hero = Page::where('masjid_id', $masjid->id)->where('slug', 'home')->firstOrFail()->sections()->get()->first();
        $hero->update(['content' => ['title' => 'Edited by the admin'] + $hero->content]);
        $counts = [Page::withTrashed()->count(), Section::count(), DB::table('page_section')->count()];

        $again = DB::transaction(fn () => StarterSite::applyTo($masjid, 'masjid.classic', StarterFacts::fromMasjid($masjid)));

        $this->assertSame([], $again['created']);
        $this->assertSame(Page::withTrashed()->where('masjid_id', $masjid->id)->orderBy('order')->pluck('slug')->all(), $again['skipped']);
        $this->assertSame($counts, [Page::withTrashed()->count(), Section::count(), DB::table('page_section')->count()], 'nothing written');
        $this->assertSame('Who we are', $about->fresh()->title);
        $this->assertNull(Page::where('masjid_id', $masjid->id)->where('slug', 'gallery')->first(), 'a page the admin deleted stays deleted');
        $this->assertSame('Edited by the admin', $hero->fresh()->content['title']);
    }

    #[Test]
    public function a_failure_rolls_everything_back(): void
    {
        $created = 0;
        Section::creating(function () use (&$created) {
            if (++$created === 5) {
                throw new \RuntimeException('the fifth section failed');
            }
        });

        $draft = $this->draftWith($this->studioAnswers());
        $this->provision($draft->id)->assertStatus(500);

        $this->assertSame(0, Masjid::count());
        $this->assertSame(0, Page::withTrashed()->count());
        $this->assertSame(0, Section::count());
        $this->assertSame(0, DB::table('page_section')->count());

        // And on its own, with no caller transaction around it: the writer's
        // own transaction unwinds the pages it had already written.
        $masjid = $this->org();
        $created = 0;
        try {
            StarterSite::applyTo($masjid, 'masjid.classic', StarterFacts::fromMasjid($masjid));
            $this->fail('the writer did not fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('the fifth section failed', $e->getMessage());
        }
        $this->assertSame(0, Page::withTrashed()->where('masjid_id', $masjid->id)->count());
    }

    #[Test]
    public function a_preset_for_another_vertical_or_without_web_is_refused(): void
    {
        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();

        $this->postJson('/api/admin/onboarding/provision', ['layout_preset' => 'school.essentials'] + $payload)
            ->assertStatus(422)->assertJsonStructure(['data' => ['layout_preset']]);

        $this->postJson('/api/admin/onboarding/provision', ['platforms' => ['ios', 'android']] + $payload)
            ->assertStatus(422)->assertJsonStructure(['data' => ['layout_preset']]);

        $this->assertSame(0, Masjid::count());
        $this->assertSame(0, Page::count());

        $this->expectException(\InvalidArgumentException::class);
        StarterSite::applyTo($this->org(), 'school.essentials', StarterFacts::fromArray(self::MINIMAL));
    }

    #[Test]
    public function the_multipart_form_encoding_works(): void
    {
        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        $payload['capabilities'] = array_map(fn (bool $v) => $v ? '1' : '0', ['events' => false] + CapabilityCatalogue::resolve('masjid', []));

        $id = $this->post('/api/admin/onboarding/provision', $payload, ['Accept' => 'application/json'])->assertCreated()->json('data.masjid_id');

        $this->assertSame(['home', 'about', 'announcements', 'gallery', 'donate', 'contact'], Page::where('masjid_id', $id)->orderBy('order')->pluck('slug')->all());
        $this->assertSame(['header' => 'default', 'footer' => 'columns'], Masjid::findOrFail($id)->themeSettings->tokens['layout']);
    }
}
