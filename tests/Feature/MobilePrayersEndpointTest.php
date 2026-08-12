<?php

namespace Tests\Feature;

use App\Models\IqamaTimeSetting;
use App\Models\JumaaSetting;
use App\Models\Masjid;
use App\Models\Prayer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/mobile/masjids/{id}/prayers — the endpoint that 500'd in production
 * from roughly December 2025.
 *
 * It filled its cache by shelling out to `node resources/js/fetchPrayerTimes.js`.
 * Node is not installed on the droplet, so shell_exec() returned null and the
 * array_map() over the decoded result threw. The calculation now happens in
 * process (App\Services\PrayerTimes), and this suite covers the endpoint end to
 * end: that it answers at all, that what it writes is the shape the apps
 * already read, and that the existing caching behaviour is unchanged.
 *
 * The accuracy of the times themselves is pinned separately, against the
 * JavaScript library and against published almanac data, in
 * tests/Unit/AdhanPrayerTimesTest.
 */
class MobilePrayersEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The controller derives its query window from Carbon::now(); pinning
        // it keeps the window off the midnight boundary, where a DATE column
        // compared against a datetime bound would drop a day.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Raleigh, NC — the coordinates the live masjid row carried when the cache last filled. */
    private function makeMasjid(): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Test Masjid '.uniqid(),
            'email' => 'masjid-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => '35.78056000',
            'longitude' => '-78.63890000',
            'timezone' => 'America/New_York',
        ]);

        IqamaTimeSetting::create([
            'masjid_id' => $masjid->id,
            'fajr' => 20,
            'dhuhr' => 10,
            'asr' => 10,
            'maghrib' => 10,
            'isha' => 15,
        ]);

        JumaaSetting::create([
            'masjid_id' => $masjid->id,
            'iqama' => '13:30',
        ]);

        return $masjid;
    }

    #[Test]
    public function it_returns_prayer_times_instead_of_a_server_error(): void
    {
        $masjid = $this->makeMasjid();

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-15&end_date=2025-12-17")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_stores_the_prayer_payload_in_the_shape_the_apps_already_read(): void
    {
        $masjid = $this->makeMasjid();

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-17&end_date=2025-12-17")
            ->assertOk();

        $row = Prayer::where('masjid_id', $masjid->id)->where('date', '2025-12-17')->firstOrFail();
        $stored = json_decode($row->getRawOriginal('prayers_data'), true);

        // Key order is part of the contract: the column holds JSON text and
        // every row written before the outage carries this order.
        $this->assertSame(
            ['coordinates', 'date', 'calculationParameters', 'fajr', 'sunrise', 'dhuhr', 'asr', 'sunset', 'maghrib', 'isha'],
            array_keys($stored)
        );

        // The times themselves — identical to what the Node script wrote for
        // this masjid on this date (prayers row id=74 in production).
        $this->assertSame('2025-12-17T00:00:00.000Z', $stored['date']);
        $this->assertSame('2025-12-17T10:47:00.000Z', $stored['fajr']);
        $this->assertSame('2025-12-17T12:19:00.000Z', $stored['sunrise']);
        $this->assertSame('2025-12-17T17:16:00.000Z', $stored['dhuhr']);
        $this->assertSame('2025-12-17T19:45:00.000Z', $stored['asr']);
        $this->assertSame('2025-12-17T22:03:00.000Z', $stored['sunset']);
        $this->assertSame('2025-12-17T22:06:00.000Z', $stored['maghrib']);
        $this->assertSame('2025-12-17T23:34:00.000Z', $stored['isha']);

        $this->assertSame('MoonsightingCommittee', $stored['calculationParameters']['method']);
        $this->assertSame('shafi', $stored['calculationParameters']['madhab']);
        $this->assertSame(18, $stored['calculationParameters']['fajrAngle']);
        $this->assertSame(5, $stored['calculationParameters']['methodAdjustments']['dhuhr']);

        // The coordinates are echoed from the masjid row. Their JSON type is
        // whatever the database driver hands back for a decimal column (a
        // string on MySQL, which is what production stores), so assert the
        // value rather than the type.
        $this->assertSame(35.78056, (float) $stored['coordinates']['latitude']);
        $this->assertSame(-78.6389, (float) $stored['coordinates']['longitude']);

        // Iqama offsets are applied to the UTC adhan instants and stored as
        // UTC wall-clock — 10:47 + 20 minutes, exactly as production row 74.
        $iqama = json_decode($row->getRawOriginal('iqama_times_data'), true);
        $this->assertSame('11:07:00', $iqama['fajr']);
        $this->assertSame('17:26:00', $iqama['dhuhr']);
        $this->assertSame('19:55:00', $iqama['asr']);
        $this->assertSame('22:16:00', $iqama['maghrib']);
        $this->assertSame('23:49:00', $iqama['isha']);
    }

    #[Test]
    public function the_masjid_timezone_does_not_shift_the_stored_times(): void
    {
        // Two masjids at the same coordinates with different timezone columns
        // must produce identical rows: the stored values are absolute UTC
        // instants, and SendDuePrayerNotifications reads them as such. A
        // timezone leak here would look plausible and be wrong by hours.
        $eastern = $this->makeMasjid();
        $utc = $this->makeMasjid();
        $utc->update(['timezone' => 'UTC']);

        foreach ([$eastern, $utc] as $masjid) {
            $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-17&end_date=2025-12-17")
                ->assertOk();
        }

        $easternRow = Prayer::where('masjid_id', $eastern->id)->where('date', '2025-12-17')->firstOrFail();
        $utcRow = Prayer::where('masjid_id', $utc->id)->where('date', '2025-12-17')->firstOrFail();

        $this->assertSame(
            $easternRow->getRawOriginal('prayers_data'),
            $utcRow->getRawOriginal('prayers_data')
        );
    }

    #[Test]
    public function jumaa_settings_are_attached_to_fridays_only(): void
    {
        $masjid = $this->makeMasjid();

        // 2025-12-19 is a Friday; 2025-12-18 is not.
        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-18&end_date=2025-12-19")
            ->assertOk();

        $thursday = Prayer::where('masjid_id', $masjid->id)->where('date', '2025-12-18')->firstOrFail();
        $friday = Prayer::where('masjid_id', $masjid->id)->where('date', '2025-12-19')->firstOrFail();

        $this->assertNull($thursday->getRawOriginal('jumaa_data'));
        $this->assertSame('13:30', json_decode($friday->getRawOriginal('jumaa_data'), true)['iqama']);
    }

    #[Test]
    public function a_second_request_for_the_same_range_does_not_duplicate_rows(): void
    {
        $masjid = $this->makeMasjid();
        $url = "/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-15&end_date=2025-12-17";

        $this->getJson($url)->assertOk();
        $this->assertSame(3, Prayer::where('masjid_id', $masjid->id)->count());

        $ids = Prayer::where('masjid_id', $masjid->id)->orderBy('date')->pluck('id')->all();

        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data');

        // Caching behaviour is unchanged: a cached day is refreshed in place,
        // never re-inserted. The unique (masjid_id, date) index would reject a
        // duplicate anyway, so a regression here surfaces as a 500.
        $this->assertSame(3, Prayer::where('masjid_id', $masjid->id)->count());
        $this->assertSame($ids, Prayer::where('masjid_id', $masjid->id)->orderBy('date')->pluck('id')->all());
    }

    #[Test]
    public function an_existing_cached_row_is_refreshed_rather_than_left_stale(): void
    {
        $masjid = $this->makeMasjid();

        // A row cached with deliberately wrong times, as if the coordinates had
        // since been corrected. The pre-existing behaviour is to overwrite it.
        $stale = Prayer::create([
            'masjid_id' => $masjid->id,
            'prayers_data' => ['fajr' => '2000-01-01T00:00:00.000Z'],
            'iqama_times_data' => ['fajr' => '00:00:00'],
            'jumaa_data' => null,
            'date' => '2025-12-17',
        ]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-17&end_date=2025-12-17")
            ->assertOk();

        $refreshed = Prayer::findOrFail($stale->id);

        $this->assertSame('2025-12-17T10:47:00.000Z', json_decode($refreshed->getRawOriginal('prayers_data'), true)['fajr']);
        $this->assertSame(1, Prayer::where('masjid_id', $masjid->id)->count());
    }

    #[Test]
    public function days_outside_the_requested_range_are_left_alone(): void
    {
        $masjid = $this->makeMasjid();

        $untouched = Prayer::create([
            'masjid_id' => $masjid->id,
            'prayers_data' => ['fajr' => '2000-01-01T00:00:00.000Z'],
            'iqama_times_data' => ['fajr' => '00:00:00'],
            'jumaa_data' => null,
            'date' => '2026-03-01',
        ]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-15&end_date=2025-12-17")
            ->assertOk();

        $this->assertSame(
            '2000-01-01T00:00:00.000Z',
            json_decode(Prayer::findOrFail($untouched->id)->getRawOriginal('prayers_data'), true)['fajr']
        );
    }

    #[Test]
    public function an_inverted_date_range_is_still_rejected(): void
    {
        $masjid = $this->makeMasjid();

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2025-12-17&end_date=2025-12-15")
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Invalid date range.');

        $this->assertSame(0, Prayer::where('masjid_id', $masjid->id)->count());
    }

    #[Test]
    public function the_endpoint_no_longer_depends_on_an_external_process(): void
    {
        // The outage was a missing `node` binary, not a bad calculation. This
        // asserts the dependency itself is gone rather than merely unused, so
        // nobody reintroduces the subprocess while the tests stay green.
        //
        // Tokenized rather than grepped: the controller's own comments name
        // shell_exec to explain why it was removed, and a substring search
        // would trip over the explanation.
        $tokens = token_get_all(file_get_contents(app_path('Http/Controllers/Mobile/PrayersController.php')));

        $identifiers = [];
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                $identifiers[] = $token[1];
            }
            // The backtick operator is shell execution with no function name.
            $this->assertNotSame('`', is_array($token) ? $token[1] : $token);
        }

        foreach (['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen'] as $processFunction) {
            $this->assertNotContains(
                $processFunction,
                $identifiers,
                "PrayersController must not spawn a process ({$processFunction})."
            );
        }

        $this->assertFileDoesNotExist(base_path('resources/js/fetchPrayerTimes.js'));
    }
}
