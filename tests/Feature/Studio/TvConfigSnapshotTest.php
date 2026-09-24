<?php

namespace Tests\Feature\Studio;

use App\Http\Controllers\Mobile\TvConfigController;
use App\Models\DonationLink;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Studio's tvOS preview frame reads TvConfigController's constants instead of
 * retyping them (docs/manara-studio-w1.md S4), so they changed from private to
 * public. That is a visibility change only: every installed tvOS board decodes
 * this body with a strict Codable struct, and a byte that moved would be a live
 * change the slice promised not to make.
 *
 * The fixture was recorded from the controller as it stood at 9a412074, before
 * the visibility change, and is re-recorded only with TV_CONFIG_SNAPSHOT_RECORD=1,
 * a run that always fails so it can never pass for a check.
 */
class TvConfigSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__ . '/../../fixtures/tv-config-snapshot.json';

    /** The constants StudioPreview reads, by name. */
    private const READ_BY_STUDIO = ['CAROUSEL_INTERVAL_SECONDS', 'DONATE_CAPTION', 'ANNOUNCEMENT_SELECTION', 'THEME'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Cache::flush();
    }

    #[Test]
    public function the_tv_config_body_is_identical_to_the_committed_snapshot(): void
    {
        $bodies = [];

        foreach ($this->cases() as $name => $org) {
            $bodies[$name] = $this->getJson("/api/mobile/masjids/{$org->id}/tv-config")->assertOk()->getContent();
        }

        if (getenv('TV_CONFIG_SNAPSHOT_RECORD') === '1') {
            file_put_contents(self::FIXTURE, json_encode(['bodies' => $bodies], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

            $this->fail('Recorded ' . self::FIXTURE . '. A recording run never passes: run again without TV_CONFIG_SNAPSHOT_RECORD.');
        }

        $snapshot = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);

        // Raw bytes, not decoded JSON: key order and number encoding are what a
        // strict decoder on an installed board actually reads.
        $this->assertSame($snapshot['bodies'], $bodies);
    }

    #[Test]
    public function the_constants_studio_reads_are_public(): void
    {
        foreach (self::READ_BY_STUDIO as $name) {
            $constant = new \ReflectionClassConstant(TvConfigController::class, $name);

            $this->assertTrue($constant->isPublic(), "TvConfigController::{$name} is not public, so the preview would have to retype it");
        }
    }

    /**
     * One organisation for each branch the body has: a masjid with a donation
     * link (prayer panel and QR on), a school with none (both off), and a
     * community whose link is only whitespace (trimmed to no QR).
     *
     * @return array<string, Masjid>
     */
    private function cases(): array
    {
        $masjid = $this->org('masjid');
        DonationLink::create(['masjid_id' => $masjid->id, 'link' => 'https://example.org/give', 'title' => 'Give', 'message' => 'Give today']);

        $community = $this->org('community');
        DonationLink::create(['masjid_id' => $community->id, 'link' => '   ']);

        return [
            'masjid_with_donation_link' => $masjid,
            'school_without_donation_link' => $this->org('school'),
            'community_with_blank_donation_link' => $community,
        ];
    }

    private function org(string $orgType): Masjid
    {
        return Masjid::create([
            'name' => "TV Snapshot {$orgType}",
            'org_type' => $orgType,
            'email' => "tv-{$orgType}@test.local",
            'phone' => '+1555' . random_int(1000000, 9999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);
    }
}
