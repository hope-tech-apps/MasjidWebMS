<?php

namespace Tests\Feature\Studio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\BuildsLiveShapedOrgs;
use Tests\TestCase;

/**
 * The public-settings strip (StarterPlaceholders::publicSettings) sits on the
 * page-section serializer every live renderer reads (docs/manara-studio-w1.md
 * S8). It may remove Studio's own `settings.studio` and nothing else.
 */
class StarterSitePublicPayloadTest extends TestCase
{
    use BuildsLiveShapedOrgs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->freezeLiveShapedWorld();
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
}
