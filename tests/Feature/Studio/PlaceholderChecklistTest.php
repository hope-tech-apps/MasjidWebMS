<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Support\Studio\LayoutPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * GET /api/admin/masjids/{id}/pages/placeholders (Studio W2 S10): a Studio
 * organisation's open starter placeholders, per page and section, computed at
 * read time by the same StarterPlaceholders::isOpen StarterSite used at plan
 * time, behind the page builder's own gates.
 */
class PlaceholderChecklistTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->super = $this->actAsSuperAdmin();
    }

    /** @return array{0: int, 1: array<string, mixed>} the org id and the provision's starter_site report */
    private function studioOrg(string $orgType = 'masjid', array $facts = self::MINIMAL, ?string $preset = null): array
    {
        $answers = $this->studioAnswers($orgType, $facts, $preset === null ? [] : ['layout' => ['preset' => $preset, 'approved_at' => self::APPROVED_AT]]);
        $data = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data');

        return [(int) $data['masjid_id'], $data['starter_site']];
    }

    private function checklist(int $orgId)
    {
        return $this->getJson("/api/admin/masjids/{$orgId}/pages/placeholders");
    }

    private function adminOf(int $orgId): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $orgId, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        return $user->fresh();
    }

    private function setCapability(int $orgId, string $key, bool $on): void
    {
        $org = Masjid::findOrFail($orgId);
        $overrides = is_array($org->capability_overrides) ? $org->capability_overrides : [];
        $overrides[$key] = $on;
        $org->forceFill(['capability_overrides' => $overrides])->save();
    }

    #[Test]
    public function a_studio_orgs_open_placeholders_are_listed_per_page_and_section(): void
    {
        [$id, $report] = $this->studioOrg('masjid', self::MINIMAL);

        $data = $this->checklist($id)->assertOk()->assertJsonPath('status', 'success')->json('data');

        $this->assertSame(['open', 'essential_open', 'pages'], array_keys($data));
        $this->assertGreaterThan(0, $data['open'], 'a minimal draft leaves placeholders to fill');
        $this->assertSame($report['placeholders_open'], $data['open'], 'read time agrees with plan time at provision');
        $this->assertLessThanOrEqual($data['open'], $data['essential_open']);

        $ownPages = Page::where('masjid_id', $id)->pluck('id')->all();
        $open = 0;

        foreach ($data['pages'] as $page) {
            $this->assertSame(['page_id', 'slug', 'title', 'sections'], array_keys($page));
            $this->assertContains($page['page_id'], $ownPages);
            $this->assertNotSame([], $page['sections'], 'a page with no marked section is omitted');

            foreach ($page['sections'] as $section) {
                $this->assertSame(['section_id', 'title', 'section_type', 'active', 'placeholders'], array_keys($section));
                $stored = Section::findOrFail($section['section_id']);
                $this->assertSame($id, (int) $stored->masjid_id);
                $this->assertSame($stored->is_active, $section['active']);

                foreach ($section['placeholders'] as $placeholder) {
                    $this->assertSame(['field', 'kind', 'hint', 'hint_text', 'essential', 'open'], array_keys($placeholder));
                    $this->assertNotSame('', $placeholder['hint_text'], "{$placeholder['hint']} has its sentence");
                    $open += $placeholder['open'] ? 1 : 0;
                }
            }
        }

        $this->assertSame($data['open'], $open);
    }

    #[Test]
    public function a_filled_field_is_no_longer_open(): void
    {
        [$id] = $this->studioOrg('masjid', self::MINIMAL);
        $data = $this->checklist($id)->assertOk()->json('data');

        $target = null;
        foreach ($data['pages'] as $page) {
            foreach ($page['sections'] as $section) {
                foreach ($section['placeholders'] as $placeholder) {
                    if ($target === null && $placeholder['open'] && $placeholder['kind'] === 'text') {
                        $target = [$page['page_id'], $section['section_id'], $placeholder['field']];
                    }
                }
            }
        }
        $this->assertNotNull($target, 'the premise: a minimal site has an open text placeholder');
        [$pageId, $sectionId, $field] = $target;

        // Through the page builder, exactly as SectionFormModal saves: a
        // form POST with _method=PUT and JSON-encoded content and settings.
        $section = Section::findOrFail($sectionId);
        $content = json_decode((string) $section->getRawOriginal('content'), true);
        data_set($content, $field, 'Written by the admin');
        $this->post("/api/admin/masjids/{$id}/pages/{$pageId}/sections/{$sectionId}", [
            '_method' => 'PUT',
            'section_type' => $section->getRawOriginal('section_type'),
            'title' => (string) $section->title,
            'content' => json_encode($content),
            'order' => '1',
            'platforms' => json_encode(['web', 'mobile']),
            'is_active' => $section->is_active ? '1' : '0',
            'settings' => json_encode($section->settings),
        ], ['Accept' => 'application/json'])->assertOk();

        $after = $this->checklist($id)->assertOk()->json('data');

        $this->assertSame($data['open'] - 1, $after['open']);
        $placeholder = collect($after['pages'])->flatMap(fn ($p) => $p['sections'])->firstWhere('section_id', $sectionId)['placeholders'];
        $this->assertFalse(collect($placeholder)->firstWhere('field', $field)['open']);
    }

    #[Test]
    public function a_live_org_without_markers_gets_an_empty_list(): void
    {
        $org = Masjid::create([
            'name' => 'Live Org', 'email' => 'live@test.local', 'phone' => '+15550002222',
            'country_id' => $this->countryId, 'city_id' => $this->cityId, 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'masjid',
        ]);
        $page = Page::create(['masjid_id' => $org->id, 'slug' => 'home', 'title' => 'Home', 'is_active' => true, 'order' => 1]);
        $section = Section::create(['masjid_id' => $org->id, 'section_type' => 'text', 'title' => 'Welcome', 'content' => ['body' => ''], 'is_active' => true, 'settings' => ['bind' => 'masjid_about.about']]);
        $page->sections()->attach($section->id, ['order' => 1]);

        $this->checklist((int) $org->id)
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'data' => ['open' => 0, 'essential_open' => 0, 'pages' => []]]);
    }

    #[Test]
    public function it_carries_the_page_builders_gates(): void
    {
        [$id] = $this->studioOrg('masjid', self::MINIMAL);
        $admin = $this->adminOf($id);

        $this->app['auth']->forgetGuards();
        $this->checklist($id)->assertUnauthorized();

        Sanctum::actingAs($admin);

        // `web_pages` is a grant, off by default: this organisation's own
        // admins may not edit its site, so they are not shown what to fill.
        $this->setCapability($id, 'web_pages', false);
        $this->checklist($id)->assertForbidden();

        $this->setCapability($id, 'web_pages', true);
        $this->checklist($id)->assertOk();
        $this->getJson("/api/admin/masjids/{$id}/pages")->assertOk();

        // `website` is the module: no website, no page builder, no checklist.
        $this->setCapability($id, 'website', false);
        $this->checklist($id)->assertForbidden();
        $this->getJson("/api/admin/masjids/{$id}/pages")->assertForbidden();
    }

    #[Test]
    public function the_route_is_not_captured_by_the_page_show_route(): void
    {
        [$id] = $this->studioOrg('masjid', self::MINIMAL);

        $this->checklist($id)->assertOk()->assertJsonStructure(['data' => ['open', 'essential_open', 'pages']]);

        // And the page show route still answers a real page.
        $page = Page::where('masjid_id', $id)->firstOrFail();
        $this->getJson("/api/admin/masjids/{$id}/pages/{$page->id}")->assertOk()->assertJsonPath('data.id', $page->id);
    }

    #[Test]
    public function read_time_count_equals_plan_time_count_at_provision(): void
    {
        $checked = 0;

        foreach (Masjid::ORG_TYPES as $orgType) {
            foreach (LayoutPresets::keysFor($orgType) as $preset) {
                foreach (['minimal' => self::MINIMAL, 'maximal' => self::MAXIMAL] as $fixture => $facts) {
                    [$id, $report] = $this->studioOrg($orgType, $facts, $preset);

                    $data = $this->checklist($id)->assertOk()->json('data');

                    $this->assertSame($report['placeholders_open'], $data['open'], "{$preset} ({$fixture}): the checklist and the provision disagree");

                    // Every section the provision reported as waiting is waiting here, on an essential placeholder or a review.
                    $inactive = collect($data['pages'])->flatMap(fn ($p) => $p['sections'])->reject(fn ($s) => $s['active']);
                    $this->assertCount(count($report['sections_inactive']), $inactive, "{$preset} ({$fixture})");

                    $checked++;
                }
            }
        }

        $this->assertSame(2 * count(Masjid::ORG_TYPES) * 3, $checked, 'every preset of every vertical, both fixtures');
    }
}
