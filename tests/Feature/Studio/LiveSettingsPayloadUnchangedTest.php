<?php

namespace Tests\Feature\Studio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\BuildsLiveShapedOrgs;
use Tests\TestCase;

/**
 * S8 adds `favicon_url`, `touch_icon_url` and `share_image_url` to
 * /api/v1/settings ONLY for an organisation that has that row (R11). Every
 * live organisation has logos and none of the three, so its settings payload
 * must be exactly what it was: same keys, same order, same bytes, including
 * anything the renderer serializes from it.
 *
 * The recording was written by the base S8 branched from (fe390d7d).
 */
class LiveSettingsPayloadUnchangedTest extends TestCase
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

    #[Test]
    public function an_org_with_logos_and_no_derivatives_keeps_exactly_its_previous_key_set(): void
    {
        $masjid = $this->burlingtonShaped();

        $response = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $masjid->id])->assertOk();

        // The premise: the logos are there, so a fallback from them would show.
        $this->assertNotNull($response->json('data.logo_url'));
        $this->assertNotNull($response->json('data.header_logo_url'));
        $this->assertNotNull($response->json('data.footer_logo_url'));

        $this->assertMatchesLiveRecording('settings-with-logos', ['200 /api/v1/settings' => (string) $response->getContent()]);

        foreach (['favicon_url', 'touch_icon_url', 'share_image_url'] as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data'), "{$key} appeared for an organisation without that row");
        }
    }
}
