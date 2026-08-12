<?php

namespace Tests\Feature;

use App\Models\IqamaTimeSetting;
use App\Models\JumaaSetting;
use App\Models\Masjid;
use App\Models\Prayer;
use App\Models\PrayerCalculationSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A prayer that does not occur must not acquire an iqama time.
 *
 * ## The bug
 *
 * `PrayersController::store()` built `iqama_times_data` with
 * `Carbon::parse($item['fajr'])->addMinutes($offset)`. Inside the polar circles
 * the sun may never reach a given altitude, and the generator says so with a
 * `null` — deliberately, byte-for-byte as adhan-js did (PrayerTimesGenerator::iso,
 * and the INVALID TIMES note on App\Services\PrayerTimes\PrayerTimes).
 *
 * `Carbon::parse(null)` neither throws nor returns null. It returns THE CURRENT
 * INSTANT. So a prayer that does not occur was stored with an iqama time of
 * "whenever the cache happened to fill, plus the offset": a perfectly ordinary
 * looking `H:i:s` string, sitting next to a `null` adhan time, indistinguishable
 * from a real one — and different every time the row was regenerated.
 *
 * ## Why it is not a polar curiosity
 *
 * WHICH prayers are null is not a fixed set. It depends on latitude, on the
 * date, and on the masjid's CALCULATION METHOD, which is now per-tenant: at the
 * same place on the same day MoonsightingCommittee substitutes its own seasonal
 * twilight where MuslimWorldLeague leaves fajr and isha unresolved. So the same
 * masjid can start and stop fabricating times purely because an admin changed a
 * dropdown. That is asserted below rather than described.
 *
 * ## `SendDuePrayerNotifications` was already correct, and stays correct
 *
 * It never reads `iqama_times_data` — that column loses its date, which is
 * unsafe for a late-night iqama — and computes iqama as adhan + offset from
 * `prayers_data`, skipping any prayer where `isset()` is false. `isset()` is
 * false for null, so an unresolved prayer was never pushed. The fabricated
 * value only ever reached clients that read the stored column. This change
 * makes the column agree with what that command was already doing; the last
 * test here pins that the guard still holds.
 */
class PolarPrayerIqamaTest extends TestCase
{
    use RefreshDatabase;

    /** Longyearbyen, Svalbard (78.22 N) — inside the Arctic circle. */
    private const LONGYEARBYEN = ['78.22320000', '15.64690000'];

    /** Raleigh, NC — the live masjid's coordinates. */
    private const RALEIGH = ['35.78056000', '-78.63890000'];

    private const OFFSETS = ['fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 10, 'isha' => 15];

    private const PRAYERS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

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

        // Pinned so "now" is a KNOWN value: the fabricated iqama the old code
        // produced was now + offset, and the assertions below name it exactly.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  array{0: string, 1: string}  $coordinates
     * @param  array<string, string>|null  $calculation
     */
    private function makeMasjid(array $coordinates, ?array $calculation = null): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Test Masjid '.uniqid(),
            'email' => 'masjid-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => $coordinates[0],
            'longitude' => $coordinates[1],
            'timezone' => 'UTC',
        ]);

        IqamaTimeSetting::create(array_merge(['masjid_id' => $masjid->id], self::OFFSETS));
        JumaaSetting::create(['masjid_id' => $masjid->id, 'iqama' => '13:30']);

        if ($calculation !== null) {
            PrayerCalculationSetting::create(array_merge(['masjid_id' => $masjid->id], $calculation));
        }

        return $masjid;
    }

    private function fetch(Masjid $masjid, string $date): void
    {
        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date={$date}&end_date={$date}")
            ->assertOk();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [prayers, iqama] */
    private function storedRow(Masjid $masjid, string $date): array
    {
        $row = Prayer::where('masjid_id', $masjid->id)->where('date', $date)->firstOrFail();

        return [
            json_decode($row->getRawOriginal('prayers_data'), true),
            json_decode($row->getRawOriginal('iqama_times_data'), true),
        ];
    }

    /** What the old code would have written for a null prayer: now + offset. */
    private function fabricated(string $prayer): string
    {
        return Carbon::now()->addMinutes(self::OFFSETS[$prayer])->format('H:i:s');
    }

    // ------------------------------------------------------------ the bug

    #[Test]
    public function a_prayer_that_does_not_occur_yields_a_null_iqama(): void
    {
        // Polar night: the sun never rises, so sunrise/sunset — and everything
        // derived from them — are unresolved.
        $masjid = $this->makeMasjid(self::LONGYEARBYEN, [
            'method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetch($masjid, '2025-12-21');

        [$prayers, $iqama] = $this->storedRow($masjid, '2025-12-21');

        $nulls = array_filter(self::PRAYERS, fn (string $p) => $prayers[$p] === null);

        $this->assertNotEmpty(
            $nulls,
            'The fixture no longer produces an unresolved prayer, so it proves nothing.'
        );

        foreach ($nulls as $prayer) {
            $this->assertNull(
                $iqama[$prayer],
                "{$prayer} does not occur here, so its iqama must be null."
            );

            // Name the old behaviour explicitly: it was not garbage, it was a
            // plausible clock time derived from the moment the cache filled.
            $this->assertNotSame($this->fabricated($prayer), $iqama[$prayer]);
        }
    }

    #[Test]
    public function the_prayers_that_do_occur_still_get_a_real_iqama(): void
    {
        $masjid = $this->makeMasjid(self::LONGYEARBYEN, [
            'method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetch($masjid, '2025-12-21');

        [$prayers, $iqama] = $this->storedRow($masjid, '2025-12-21');

        $resolved = array_filter(self::PRAYERS, fn (string $p) => $prayers[$p] !== null);

        // "Null everything at a polar latitude" would pass the test above and
        // fail this one.
        $this->assertNotEmpty($resolved);

        foreach ($resolved as $prayer) {
            $this->assertSame(
                Carbon::parse($prayers[$prayer])->addMinutes(self::OFFSETS[$prayer])->format('H:i:s'),
                $iqama[$prayer],
                "{$prayer} occurs, so its iqama is still adhan + offset."
            );
        }
    }

    #[Test]
    public function the_five_keys_are_still_present_and_in_order(): void
    {
        $masjid = $this->makeMasjid(self::LONGYEARBYEN);

        $this->fetch($masjid, '2025-12-21');

        [, $iqama] = $this->storedRow($masjid, '2025-12-21');

        // The column holds JSON text that shipped app builds parse. A null
        // VALUE is the change; a missing KEY would be a different, worse one.
        $this->assertSame(self::PRAYERS, array_keys($iqama));
    }

    #[Test]
    public function which_prayers_are_fabricated_used_to_depend_on_the_calculation_method(): void
    {
        // The same place, the same day, two methods. MoonsightingCommittee
        // substitutes its own seasonal twilight and resolves prayers that
        // MuslimWorldLeague leaves unresolved — so an admin changing a dropdown
        // was enough to start or stop the fabrication.
        $mwl = $this->makeMasjid(self::LONGYEARBYEN, [
            'method' => 'MuslimWorldLeague', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);
        $moonsighting = $this->makeMasjid(self::LONGYEARBYEN, [
            'method' => 'MoonsightingCommittee', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetch($mwl, '2025-12-21');
        $this->fetch($moonsighting, '2025-12-21');

        [$mwlPrayers, $mwlIqama] = $this->storedRow($mwl, '2025-12-21');
        [$msPrayers, $msIqama] = $this->storedRow($moonsighting, '2025-12-21');

        $this->assertNotSame(
            array_map(fn ($p) => $mwlPrayers[$p] === null, self::PRAYERS),
            array_map(fn ($p) => $msPrayers[$p] === null, self::PRAYERS),
            'The two methods resolve the same set of prayers here; pick another date or method.'
        );

        // Whatever the split, the rule is uniform on both sides.
        foreach ([[$mwlPrayers, $mwlIqama], [$msPrayers, $msIqama]] as [$prayers, $iqama]) {
            foreach (self::PRAYERS as $prayer) {
                $this->assertSame($prayers[$prayer] === null, $iqama[$prayer] === null);
            }
        }
    }

    #[Test]
    public function regenerating_the_row_no_longer_moves_the_iqama_times(): void
    {
        $masjid = $this->makeMasjid(self::LONGYEARBYEN, [
            'method' => 'MuslimWorldLeague', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetch($masjid, '2025-12-21');
        [, $first] = $this->storedRow($masjid, '2025-12-21');

        // store() UPDATES a matching row in place, so a later request rewrites
        // it. The fabricated value was `now`-derived, so it changed on every
        // rewrite — a stored time that drifts with no input change.
        Carbon::setTestNow(Carbon::parse('2026-08-11 18:45:00', 'UTC'));
        $this->fetch($masjid, '2025-12-21');
        [, $second] = $this->storedRow($masjid, '2025-12-21');

        $this->assertSame($first, $second);
    }

    // ---------------------------------------------------------- no regression

    #[Test]
    public function a_temperate_masjid_writes_exactly_what_it_always_did(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH);

        $this->fetch($masjid, '2025-12-17');

        [, $iqama] = $this->storedRow($masjid, '2025-12-17');

        // The literal values production row 74 carries, so the refactor is
        // pinned against the historical output rather than against itself.
        $this->assertSame([
            'fajr' => '11:07:00',
            'dhuhr' => '17:26:00',
            'asr' => '19:55:00',
            'maghrib' => '22:16:00',
            'isha' => '23:49:00',
        ], $iqama);
    }

    // -------------------------------- the notification backstop is unaffected

    #[Test]
    public function the_push_backstop_still_skips_an_unresolved_prayer(): void
    {
        $masjid = $this->makeMasjid(self::LONGYEARBYEN, [
            'method' => 'MuslimWorldLeague', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetch($masjid, '2025-12-21');

        $row = Prayer::where('masjid_id', $masjid->id)->where('date', '2025-12-21')->firstOrFail();

        // SendDuePrayerNotifications reads `prayers_data` through this accessor
        // and guards each prayer with isset(). Confirming the guard is still
        // right after this change: isset() is false for null, so an unresolved
        // prayer produces neither an adhan nor an iqama push — the command
        // never touched iqama_times_data and still does not need to.
        $data = $row->prayers_data;

        $unresolved = array_filter(self::PRAYERS, fn (string $p) => $data->{$p} === null);
        $this->assertNotEmpty($unresolved);

        foreach ($unresolved as $prayer) {
            $this->assertFalse(isset($data->{$prayer}));
        }

        foreach (array_diff(self::PRAYERS, $unresolved) as $prayer) {
            $this->assertTrue(isset($data->{$prayer}));
        }
    }
}
