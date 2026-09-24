<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * Every key the plan's answers table names (docs/manara-studio-w1.md S2) is
 * written with a value no default could produce, then read back through a
 * fresh GET. A field that is accepted and not stored — the write-only field
 * that passes every other test — fails here by name.
 */
class StudioDraftRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    /** One value for every key of every section, written out from the plan's table. */
    private function everyAnswer(): array
    {
        return [
            'identity' => [
                'org_type' => 'community',
                'name' => 'Riverside Muslim Community',
                'email' => 'hello@riverside.test',
                'phone' => '+1 905 555 0142',
                'address' => '12 Mill Road, Riverside',
                'country_id' => 38,
                'city_id' => 1204,
                'latitude' => 43.2557,
                'longitude' => -79.8711,
                'timezone' => 'America/Toronto',
                'user_id' => 77,
                'admin' => ['name' => 'Aisha Rahman', 'email' => 'aisha@riverside.test', 'phone' => '+1 905 555 0143'],
                'slug' => 'riverside',
                'description' => 'A community centre by the river.',
                'vibe' => 'Warm, family-first, lots of youth programming.',
                'donation_link' => 'https://give.riverside.test/now',
                'donation_title' => 'Support the centre',
                'donation_message' => 'Every gift keeps the lights on.',
                'facebook_url' => 'https://facebook.com/riverside',
                'youtube_url' => 'https://youtube.com/@riverside',
                'instagram_url' => 'https://instagram.com/riverside',
                'whatsapp_url' => 'https://wa.me/19055550142',
                'whatsapp_number' => '+19055550142',
            ],
            'prayer' => [
                'method' => 'NorthAmerica',
                'madhab' => 'Hanafi',
                'high_latitude_rule' => 'SeventhOfTheNight',
                'iqama_type' => 'minutes_after_adhan',
                'iqama' => ['fajr' => 21, 'dhuhr' => 11, 'asr' => 12, 'maghrib' => 6, 'isha' => 13],
                'jumaa_iqama' => '13:35',
                'iqama_given' => false,
            ],
            'brand' => [
                'primary_color' => '#2B66C2',
                'secondary_color' => '#1B1B2E',
                'accent_color' => '#FFBA63',
                'background_color' => '#F3F8FB',
                'extracted' => ['#2B66C2', '#FFBA63', '#0F0F0F'],
                'ink_overrides' => ['onPrimary' => '#FFFFFF', 'onSecondary' => '#F5F5F5', 'onAccent' => '#111827'],
            ],
            'content' => [
                'about' => 'Founded by families on the east side.',
                'mission' => 'To serve.',
                'vision' => 'A centre for everyone.',
            ],
            'features' => [
                'capabilities' => ['events' => true, 'giving' => false, 'crm' => false],
            ],
            'layout' => [
                'preset' => 'community_classic',
                'approved_at' => '2026-09-24T12:30:00+00:00',
            ],
            'platforms' => [
                'platforms' => ['ios', 'android', 'tvos', 'web'],
                'apps' => ['ios' => ['account_mode' => 'byo'], 'android' => ['account_mode' => 'managed'], 'web' => ['account_mode' => 'managed']],
            ],
            'domain' => [
                'custom' => ['host' => 'www.riverside.test', 'zone_apex' => 'riverside.test'],
            ],
        ];
    }

    #[Test]
    public function every_section_key_written_is_read_back(): void
    {
        $answers = $this->everyAnswer();
        $this->assertSame(StudioDraft::ANSWER_SECTIONS, array_keys($answers), 'the payload covers every section');

        $id = $this->newDraft()['id'];
        $this->patchDraft($id, 0, $answers)->assertOk();

        $read = $this->getJson(self::DRAFTS . "/{$id}")->assertOk()->json('data.answers');

        // Leaf by leaf, with types: MySQL's JSON column may reorder keys, but it
        // must never drop one or turn 21 into "21".
        $expected = Arr::dot($answers);
        $actual = Arr::dot($read);
        ksort($expected);
        ksort($actual);

        $this->assertSame([], array_keys(array_diff_key($expected, $actual)), 'written but never read back');
        $this->assertSame($expected, $actual);

        // The list columns are copies of identity, and the palette is derived
        // from the brand section that came back.
        $this->getJson(self::DRAFTS . "/{$id}")
            ->assertJsonPath('data.name', 'Riverside Muslim Community')
            ->assertJsonPath('data.org_type', 'community')
            ->assertJsonPath('data.palette.pairs.1.key', 'on_primary')
            ->assertJsonPath('data.palette.pairs.1.ink_source', 'manual');
    }
}
