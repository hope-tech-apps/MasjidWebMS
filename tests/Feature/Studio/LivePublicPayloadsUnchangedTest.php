<?php

namespace Tests\Feature\Studio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\BuildsLiveShapedOrgs;
use Tests\TestCase;

/**
 * S8 changes two paths every live renderer reads (the page-section serializer
 * and /api/v1/settings) and adds media relations to the organisation, and the
 * plan's promise is that no live organisation sees a byte of it
 * (docs/manara-studio-w1.md S8 "Live impact").
 *
 * The recordings under tests/fixtures/live-public-payloads were written by the
 * base S8 branched from (fe390d7d), before any S8 code existed, for three
 * organisations shaped like the live tenants (Concerns\BuildsLiveShapedOrgs).
 * Every public GET S8 could touch is compared byte for byte: settings, the page
 * list, the menu, each page by slug, the by-host lookup, and the app's
 * organisation and features payloads.
 */
class LivePublicPayloadsUnchangedTest extends TestCase
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
    public function every_public_payload_of_a_live_shaped_organisation_is_byte_identical(): void
    {
        $masjid = $this->burlingtonShaped();
        $school = $this->schoolShaped();
        $child = $this->childShaped($masjid);

        $this->assertMatchesLiveRecording('live-orgs', array_merge(
            $this->prefixed('masjid', $this->livePayloads($masjid, ['www.live-masjid.example.test', 'WWW.Live-Masjid.example.test.'])),
            $this->prefixed('school', $this->livePayloads($school, ['live-school.manara.hopetechapps.com'])),
            $this->prefixed('child', $this->livePayloads($child)),
        ));
    }

    /** @param array<string, string> $payloads */
    private function prefixed(string $who, array $payloads): array
    {
        $out = [];

        foreach ($payloads as $request => $body) {
            $out["{$who}: {$request}"] = $body;
        }

        return $out;
    }
}
