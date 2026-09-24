<?php

namespace Tests\Feature\Studio;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\BuildsLiveShapedOrgs;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * The public-settings strip (StarterPlaceholders::publicSettings) sits on the
 * page-section serializer every live renderer reads (docs/manara-studio-w1.md
 * S8). It may remove Studio's own `settings.studio` and nothing else.
 */
class StarterSitePublicPayloadTest extends TestCase
{
    use BuildsLiveShapedOrgs;
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    protected function tearDown(): void
    {
        $this->unfreezeLiveShapedWorld();

        parent::tearDown();
    }

    /**
     * Settings NULL, `{}`, `{bind: about_text}`, `{bind: mission_vision_cards}`
     * and a presentation-only object, each on a live-shaped page, compared
     * DECODED with the recording the base wrote: MySQL's JSON type reorders
     * keys (§3.4), so the decoded value is what a renderer actually depends on.
     */
    #[Test]
    public function live_shaped_sections_serialize_identically(): void
    {
        $this->freezeLiveShapedWorld();
        $masjid = $this->burlingtonShaped();
        $headers = ['masjid-id' => (string) $masjid->id];

        $payloads = [
            '200 /api/v1/pages' => (string) $this->getJson('/api/v1/pages', $headers)->assertOk()->getContent(),
            '200 /api/v1/pages/home' => (string) $this->getJson('/api/v1/pages/home', $headers)->assertOk()->getContent(),
        ];

        $settings = collect(json_decode($payloads['200 /api/v1/pages/home'], true)['data']['sections'])->pluck('settings')->all();
        $this->assertSame([null, [], ['bind' => 'about_text'], ['bind' => 'mission_vision_cards'], ['background' => '#ffffff', 'padding' => 'lg']], $settings, 'the fixture carries every live settings shape');

        if (getenv('LIVE_PAYLOAD_RECORD') === '1') {
            $this->assertMatchesLiveRecording('pages-live-settings', $payloads);
        }

        $recorded = json_decode((string) file_get_contents(base_path('tests/fixtures/live-public-payloads/pages-live-settings.json')), true);

        foreach ($recorded as $request => $body) {
            $this->assertSame(json_decode($body, true), json_decode($payloads[$request], true), "{$request} decodes differently from what the base served");
            $this->assertSame($body, $payloads[$request], "{$request} is not byte-identical to what the base served");
        }
    }

    /**
     * A Studio org as its renderer sees it: only active sections, each
     * `settings.studio` marker stripped, and none of the template's markup
     * (`{fact: …}`, `{label: …}`, page and form references, hints).
     */
    #[Test]
    public function the_public_pages_payload_omits_inactive_placeholder_sections_and_never_contains_studio_or_template_markers(): void
    {
        $this->setUpProvisioning();
        $this->actAsSuperAdmin();

        $id = $this->provision($this->draftWith($this->studioAnswers('school', self::MINIMAL))->id)->assertCreated()->json('data.masjid_id');
        $headers = ['masjid-id' => (string) $id];

        // The premise: markers and held-back sections exist to be kept out.
        $this->assertSame(0, Section::where('masjid_id', $id)->whereNull('settings')->count());
        $this->assertGreaterThan(0, Section::where('masjid_id', $id)->where('is_active', false)->count());

        $bodies = [(string) $this->getJson('/api/v1/pages', $headers)->assertOk()->getContent()];
        foreach (Page::where('masjid_id', $id)->where('is_active', true)->pluck('slug') as $slug) {
            $bodies[] = (string) $this->getJson("/api/v1/pages/{$slug}", $headers)->assertOk()->getContent();
        }

        $inactiveIds = Section::where('masjid_id', $id)->where('is_active', false)->pluck('id')->all();
        $hints = array_values(config('studio_layouts.hints'));

        foreach ($bodies as $body) {
            $pages = json_decode($body, true)['data'];

            foreach (isset($pages['sections']) ? [$pages] : $pages as $page) {
                foreach ($page['sections'] as $section) {
                    $this->assertTrue($section['is_active'], "{$page['slug']} serves an inactive section");
                    $this->assertNotContains($section['id'], $inactiveIds);
                    $this->assertNull($section['settings'], 'a starter section\'s settings were only the marker, so nothing is left');
                }
            }

            foreach (['"studio"', '{"fact":', '{"label":', '{"page_path":', '{"page_id":', '{"form_template":', '"placeholders"', '"hint"', '"essential"'] as $marker) {
                $this->assertStringNotContainsString($marker, $body);
            }

            foreach ($hints as $hint) {
                $this->assertStringNotContainsString(json_encode($hint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $body, 'an admin hint reached the public API');
            }
        }
    }
}
