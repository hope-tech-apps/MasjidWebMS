<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Masjid;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * tests/fixtures/mobile-events.json is the payload MasjidKit's EventDecodingTests
 * decode (the iOS repo keeps a byte-identical copy, as it does for
 * iqama-resolution.json). No live or staging organisation had an event in the
 * next week when the TV board started reading /events (2026-09-27), so the
 * fixture is pinned here, against the endpoint's own serialization, instead of
 * being recorded from a live feed: a change to what /events sends fails here
 * before it can break the board's decoder.
 *
 * Ids depend on insertion order, so they are checked for type, not value.
 */
class MobileEventsPayloadContractTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_events_feed_answers_exactly_the_fixture_the_board_decodes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00', 'UTC'));
        $masjid = Masjid::create([
            'name' => 'Events Contract Org', 'email' => 'events@test.local', 'phone' => '+15550002222',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
        ]);
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/mobile-events.json')), true);
        foreach ($fixture['data'] as $event) {
            Event::create(['masjid_id' => $masjid->id] + array_intersect_key($event, array_flip(
                ['title', 'details', 'place', 'start', 'end', 'link']
            )));
        }

        $actual = $this->getJson("/api/mobile/masjids/{$masjid->id}/events")->assertOk()->json();

        $this->assertSame($fixture['status'], $actual['status']);
        $this->assertCount(count($fixture['data']), $actual['data']);
        foreach ($fixture['data'] as $i => $expected) {
            $row = $actual['data'][$i];
            $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($row), "event {$i} keys");
            $this->assertIsInt($row['id']);
            $this->assertSame($masjid->id, $row['masjid_id']);
            unset($expected['id'], $expected['masjid_id'], $row['id'], $row['masjid_id']);
            ksort($expected);
            ksort($row);
            $this->assertSame($expected, $row, "event {$i} values and JSON types");
        }
    }
}
