<?php

namespace Tests\Feature;

use App\Models\IqamaTimeSetting;
use App\Models\JumaaSetting;
use App\Models\Masjid;
use App\Models\Prayer;
use App\Models\PrayerCalculationSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The masjid's OWN calculation settings actually drive the cached `prayers`
 * rows.
 *
 * Before this, PrayersController::store() generated every row with a hardcoded
 * MoonsightingCommittee while prayersSettings() served the real setting to the
 * mobile apps. A masjid on any other method therefore saw one set of times in
 * its app (calculated locally from the served settings) and a different set in
 * the server's cached rows — the rows SendDuePrayerNotifications pushes adhan
 * and iqama reminders off. Nobody hit it because every tenant was still on the
 * defaults.
 *
 * Two things have to be true at once, and they pull in opposite directions:
 *
 *   1. A masjid on the defaults must regenerate BYTE-IDENTICALLY. Every row in
 *      the production table was written with MoonsightingCommittee / Shafi /
 *      MiddleOfTheNight and is parsed byte-for-byte by shipped app builds. The
 *      regression tests here pin the literal JSON production stored before the
 *      port existed — not "both code paths agree", which would pass just as
 *      happily if both had drifted together.
 *   2. A masjid on any other setting must produce genuinely different and
 *      CORRECT times, checked against tests/fixtures/adhan-prayer-times.json,
 *      i.e. against adhan-js itself rather than against the PHP port.
 *
 * Parameter-level mapping is covered in tests/Unit/SettingsCalculationParametersTest;
 * this file drives the real HTTP endpoints end to end.
 */
class PrayerCalculationSettingsWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verbatim `prayers_data` of production prayers row id=74 (masjid 1,
     * 2025-12-17), written by the Node script before any of this existed. The
     * historical output, in the bytes a different implementation on a different
     * machine actually wrote.
     */
    private const HISTORICAL_ROW_74 = '{"coordinates":{"latitude":"35.78056000","longitude":"-78.63890000"},"date":"2025-12-17T00:00:00.000Z","calculationParameters":{"madhab":"shafi","highLatitudeRule":"middleofthenight","adjustments":{"fajr":0,"sunrise":0,"dhuhr":0,"asr":0,"maghrib":0,"isha":0},"methodAdjustments":{"fajr":0,"sunrise":0,"dhuhr":5,"asr":0,"maghrib":3,"isha":0},"polarCircleResolution":"Unresolved","rounding":"nearest","shafaq":"general","method":"MoonsightingCommittee","fajrAngle":18,"ishaAngle":18,"ishaInterval":0,"maghribAngle":0},"fajr":"2025-12-17T10:47:00.000Z","sunrise":"2025-12-17T12:19:00.000Z","dhuhr":"2025-12-17T17:16:00.000Z","asr":"2025-12-17T19:45:00.000Z","sunset":"2025-12-17T22:03:00.000Z","maghrib":"2025-12-17T22:06:00.000Z","isha":"2025-12-17T23:34:00.000Z"}';

    private const RALEIGH = ['35.78056000', '-78.63890000'];

    private const REYKJAVIK = ['64.14660000', '-21.94260000'];

    /** @var list<array<string, mixed>>|null */
    private static ?array $goldenVectors = null;

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

        // The controller derives its query window from Carbon::now(), and so
        // does the stale-row invalidation ("yesterday forward"). Pinning it
        // keeps both off the midnight boundary.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array{0: string, 1: string}  $coordinates
     * @param  array<string, string>|null  $calculation  null leaves the masjid with NO settings row.
     */
    private function makeMasjid(array $coordinates = self::RALEIGH, ?array $calculation = null): Masjid
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

        JumaaSetting::create(['masjid_id' => $masjid->id, 'iqama' => '13:30']);

        if ($calculation !== null) {
            PrayerCalculationSetting::create(array_merge(['masjid_id' => $masjid->id], $calculation));
        }

        return $masjid;
    }

    private function fetchPrayers(Masjid $masjid, string $from, string $to): void
    {
        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date={$from}&end_date={$to}")
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    private function storedPayload(Masjid $masjid, string $date): string
    {
        return Prayer::where('masjid_id', $masjid->id)
            ->where('date', $date)
            ->firstOrFail()
            ->getRawOriginal('prayers_data');
    }

    /** @return array<string, mixed> */
    private function storedTimes(Masjid $masjid, string $date): array
    {
        return json_decode($this->storedPayload($masjid, $date), true);
    }

    /**
     * Byte-for-byte, except for the one field a database driver renders.
     *
     * `coordinates` is the masjid's decimal columns ECHOED BACK — adhan stores
     * its constructor arguments verbatim and never reformats them — so how it
     * serializes depends on the driver, not on the calculation. MySQL, which
     * production runs, hands a decimal column back as a string ("35.78056000");
     * the sqlite this suite runs on hands back a float (35.78056). The suffix
     * from `"date"` onward is entirely the port's own output — key order,
     * int-vs-float angles, the UTC ISO-8601 strings with .000Z, the nulls — and
     * that is compared as raw bytes. The coordinates are compared by value.
     *
     * (tests/Feature/MobilePrayersEndpointTest makes the same distinction, and
     * tests/Unit/AdhanPrayerTimesTest compares the whole string because it hands
     * the generator the MySQL string form directly, with no database in play.)
     */
    private function assertPayloadMatches(string $expected, string $actual, string $message = ''): void
    {
        $marker = ',"date":"';

        $this->assertNotFalse(strpos($expected, $marker), 'Expected payload has no date key.');
        $this->assertNotFalse(strpos($actual, $marker), 'Stored payload has no date key.');

        $this->assertSame(
            substr($expected, strpos($expected, $marker)),
            substr($actual, strpos($actual, $marker)),
            $message
        );

        $expectedCoordinates = json_decode($expected, true)['coordinates'];
        $actualCoordinates = json_decode($actual, true)['coordinates'];

        $this->assertSame((float) $expectedCoordinates['latitude'], (float) $actualCoordinates['latitude'], $message);
        $this->assertSame((float) $expectedCoordinates['longitude'], (float) $actualCoordinates['longitude'], $message);
    }

    /**
     * The adhan-js payload for one location/method/madhab/rule/date.
     *
     * @return array<string, mixed>
     */
    private function goldenVector(string $location, string $method, string $madhab, string $rule, string $date): array
    {
        self::$goldenVectors ??= json_decode(
            file_get_contents(base_path('tests/fixtures/adhan-prayer-times.json')),
            true
        );

        foreach (self::$goldenVectors as $vector) {
            if ($vector['location'] === $location
                && $vector['method'] === $method
                && $vector['date'] === $date
                && ($vector['overrides']['madhab'] ?? null) === $madhab
                && ($vector['overrides']['highLatitudeRule'] ?? null) === $rule
            ) {
                return $vector['expected'];
            }
        }

        $this->fail("No golden vector for {$location}/{$method}/{$madhab}/{$rule}/{$date}.");
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]));
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function saveCalculationSettings(Masjid $masjid, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/admin/masjids/{$masjid->id}/prayer-calculation", $payload);
    }

    /** Overwrite a cached row with a marker; if invalidation ran, it is gone. */
    private function plantSentinel(Masjid $masjid, string $date): void
    {
        Prayer::where('masjid_id', $masjid->id)->where('date', $date)->firstOrFail()->update([
            'prayers_data' => ['sentinel' => true],
        ]);
    }

    private function sentinelSurvives(Masjid $masjid, string $date): bool
    {
        $row = Prayer::where('masjid_id', $masjid->id)->where('date', $date)->first();

        return $row !== null && str_contains($row->getRawOriginal('prayers_data'), 'sentinel');
    }

    // ------------------------------------------------- (a) the regression proof

    #[Test]
    public function a_masjid_with_no_calculation_settings_row_reproduces_the_historical_payload_byte_for_byte(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        $this->assertNull($masjid->fresh()->prayerCalculationSettings);

        $this->fetchPrayers($masjid, '2025-12-17', '2025-12-17');

        $this->assertPayloadMatches(self::HISTORICAL_ROW_74, $this->storedPayload($masjid, '2025-12-17'));
    }

    #[Test]
    public function a_masjid_with_an_explicit_all_defaults_row_reproduces_the_same_historical_payload(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, [
            'method' => 'MoonsightingCommittee',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetchPrayers($masjid, '2025-12-17', '2025-12-17');

        $this->assertPayloadMatches(self::HISTORICAL_ROW_74, $this->storedPayload($masjid, '2025-12-17'));
    }

    #[Test]
    public function reading_the_settings_does_not_add_a_query_per_day(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, [
            'method' => 'Karachi',
            'madhab' => 'Hanafi',
            'high_latitude_rule' => 'TwilightAngle',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->fetchPrayers($masjid, '2025-12-15', '2025-12-24');

        $settingsQueries = array_filter(
            DB::getQueryLog(),
            fn (array $entry) => str_contains($entry['query'], 'prayer_calculation_settings')
        );

        DB::disableQueryLog();

        // Ten days generated, one eager load. Anything above 1 means the
        // relation is being lazily resolved inside the loop.
        $this->assertCount(1, $settingsQueries);

        $this->assertSame(10, Prayer::where('masjid_id', $masjid->id)->count());
    }

    // ------------------------- (b) a non-default method, checked against adhan-js

    #[Test]
    public function every_calculation_method_reproduces_the_adhan_javascript_golden_vector(): void
    {
        $date = '2025-12-21';
        $payloads = [];

        foreach (\App\Enums\PrayerCalculationMethod::cases() as $case) {
            $masjid = $this->makeMasjid(self::RALEIGH, [
                'method' => $case->value,
                'madhab' => 'Shafi',
                'high_latitude_rule' => 'MiddleOfTheNight',
            ]);

            $this->fetchPrayers($masjid, $date, $date);

            $expected = $this->goldenVector('raleigh_nc', $case->value, 'shafi', 'middleofthenight', $date);

            $this->assertPayloadMatches(
                json_encode($expected),
                $this->storedPayload($masjid, $date),
                "{$case->value} does not match adhan-js."
            );

            $payloads[$case->value] = $this->storedPayload($masjid, $date);
        }

        // The point of the exercise: these are not all the same rows. If the
        // wiring regressed to a hardcoded method, every payload would collapse
        // onto MoonsightingCommittee's and the assertions above would still be
        // comparing it against its own golden vector for eleven other methods —
        // which is exactly the failure this catches.
        $this->assertCount(12, $payloads);
        $this->assertGreaterThan(
            10,
            count(array_unique($payloads)),
            'The stored payloads barely differ between methods; the setting is probably being ignored.'
        );

        foreach ($payloads as $method => $payload) {
            $this->assertStringContainsString("\"method\":\"{$method}\"", $payload);
        }
    }

    #[Test]
    public function a_non_default_method_genuinely_moves_the_times_away_from_the_default(): void
    {
        $date = '2025-12-21';

        $default = $this->makeMasjid(self::RALEIGH, null);
        $northAmerica = $this->makeMasjid(self::RALEIGH, [
            'method' => 'NorthAmerica',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetchPrayers($default, $date, $date);
        $this->fetchPrayers($northAmerica, $date, $date);

        $defaultTimes = $this->storedTimes($default, $date);
        $isnaTimes = $this->storedTimes($northAmerica, $date);

        // ISNA uses a 15 degree fajr angle against MoonsightingCommittee's
        // seasonally adjusted twilight, so fajr lands LATER; the sun is the same
        // sun, so sunrise does not move.
        $this->assertGreaterThan(
            Carbon::parse($defaultTimes['fajr'])->getTimestamp(),
            Carbon::parse($isnaTimes['fajr'])->getTimestamp(),
            'ISNA fajr should be later than MoonsightingCommittee fajr in December at Raleigh.'
        );
        $this->assertSame($defaultTimes['sunrise'], $isnaTimes['sunrise']);
    }

    // ------------------------------------------- (c) garbage degrades, never 500s

    #[Test]
    public function unrecognised_stored_values_fall_back_to_the_historical_default_instead_of_throwing(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        // Written past Eloquent on purpose. The enum SETTER runs the same
        // BackedEnum::from() as the getter, so a bad value cannot be assigned
        // through the model at all — it arrives by raw SQL, a drifted migration
        // default, or a column written before a case was renamed.
        DB::table('prayer_calculation_settings')->insert([
            'masjid_id' => $masjid->id,
            'method' => 'CouncilOfNowhere',
            'madhab' => 'Maliki',
            'high_latitude_rule' => 'AngleBased',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fetchPrayers($masjid, '2025-12-17', '2025-12-17');

        // Degraded to the historical default, byte for byte — not a 500, and
        // not a plausible-looking neighbouring method.
        $this->assertPayloadMatches(self::HISTORICAL_ROW_74, $this->storedPayload($masjid, '2025-12-17'));
    }

    #[Test]
    public function a_single_unusable_column_does_not_discard_the_other_two(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        DB::table('prayer_calculation_settings')->insert([
            'masjid_id' => $masjid->id,
            'method' => 'Karachi',
            'madhab' => 'Maliki',            // unusable
            'high_latitude_rule' => 'TwilightAngle',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fetchPrayers($masjid, '2025-12-21', '2025-12-21');

        $params = $this->storedTimes($masjid, '2025-12-21')['calculationParameters'];

        $this->assertSame('Karachi', $params['method']);
        $this->assertSame('shafi', $params['madhab']);
        $this->assertSame('twilightangle', $params['highLatitudeRule']);
    }

    // -------------------------------------- (d) direction of the madhab / hi-lat

    #[Test]
    public function hanafi_moves_asr_later_and_leaves_every_other_prayer_alone(): void
    {
        $date = '2025-12-21';

        $shafi = $this->makeMasjid(self::RALEIGH, [
            'method' => 'MuslimWorldLeague', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);
        $hanafi = $this->makeMasjid(self::RALEIGH, [
            'method' => 'MuslimWorldLeague', 'madhab' => 'Hanafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetchPrayers($shafi, $date, $date);
        $this->fetchPrayers($hanafi, $date, $date);

        $shafiTimes = $this->storedTimes($shafi, $date);
        $hanafiTimes = $this->storedTimes($hanafi, $date);

        // DIRECTION, not just difference: Hanafi doubles the shadow length, so
        // Asr is strictly later. A mapping that swapped the two constants would
        // pass a "they differ" assertion and fail this one.
        $this->assertGreaterThan(
            Carbon::parse($shafiTimes['asr'])->getTimestamp(),
            Carbon::parse($hanafiTimes['asr'])->getTimestamp()
        );

        $this->assertSame('shafi', $shafiTimes['calculationParameters']['madhab']);
        $this->assertSame('hanafi', $hanafiTimes['calculationParameters']['madhab']);

        foreach (['fajr', 'sunrise', 'dhuhr', 'sunset', 'maghrib', 'isha'] as $prayer) {
            $this->assertSame($shafiTimes[$prayer], $hanafiTimes[$prayer], "{$prayer} must not move with the madhab.");
        }

        // ...and both agree with adhan-js.
        $this->assertPayloadMatches(
            json_encode($this->goldenVector('raleigh_nc', 'MuslimWorldLeague', 'hanafi', 'middleofthenight', $date)),
            $this->storedPayload($hanafi, $date)
        );
    }

    #[Test]
    public function the_high_latitude_rule_moves_fajr_later_and_isha_earlier_at_reykjavik(): void
    {
        // Reykjavik on the summer solstice: the sun never reaches 18 degrees
        // below the horizon, so fajr and isha are decided ENTIRELY by the
        // high-latitude rule. MuslimWorldLeague and not MoonsightingCommittee on
        // purpose — MoonsightingCommittee substitutes its own seasonal twilight
        // and ignores the rule completely, so it could not detect a swap.
        $date = '2025-06-21';
        $times = [];

        foreach (['MiddleOfTheNight', 'SeventhOfTheNight', 'TwilightAngle'] as $rule) {
            $masjid = $this->makeMasjid(self::REYKJAVIK, [
                'method' => 'MuslimWorldLeague', 'madhab' => 'Shafi', 'high_latitude_rule' => $rule,
            ]);

            $this->fetchPrayers($masjid, $date, $date);

            $times[$rule] = $this->storedTimes($masjid, $date);

            $this->assertPayloadMatches(
                json_encode($this->goldenVector(
                    'reykjavik',
                    'MuslimWorldLeague',
                    'shafi',
                    strtolower($rule),
                    $date
                )),
                $this->storedPayload($masjid, $date),
                "{$rule} does not match adhan-js."
            );
        }

        $fajr = fn (string $rule) => Carbon::parse($times[$rule]['fajr'])->getTimestamp();
        $isha = fn (string $rule) => Carbon::parse($times[$rule]['isha'])->getTimestamp();

        // The night portion each rule reserves: 1/2 (MiddleOfTheNight) >
        // 18/60 (TwilightAngle) > 1/7 (SeventhOfTheNight). A bigger portion
        // pushes the fajr floor EARLIER and the isha ceiling LATER, so the two
        // orderings are strict and opposite. Swap any two constants and one of
        // these six comparisons inverts.
        $this->assertGreaterThan($fajr('TwilightAngle'), $fajr('SeventhOfTheNight'));
        $this->assertGreaterThan($fajr('MiddleOfTheNight'), $fajr('TwilightAngle'));

        $this->assertLessThan($isha('TwilightAngle'), $isha('SeventhOfTheNight'));
        $this->assertLessThan($isha('MiddleOfTheNight'), $isha('TwilightAngle'));

        // Sunrise and sunset owe nothing to the rule.
        $this->assertSame($times['MiddleOfTheNight']['sunrise'], $times['SeventhOfTheNight']['sunrise']);
        $this->assertSame($times['MiddleOfTheNight']['sunset'], $times['TwilightAngle']['sunset']);
    }

    // ------------------------------------------ (e) the stale-row invalidation

    #[Test]
    public function changing_the_method_drops_and_rebuilds_the_cached_rows_with_the_new_method(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, [
            'method' => 'MoonsightingCommittee', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        // Rows the apps and SendDuePrayerNotifications would be reading now...
        $this->fetchPrayers($masjid, '2026-08-10', '2026-08-14');
        // ...and one from the past, which records what was actually announced.
        $this->fetchPrayers($masjid, '2025-12-17', '2025-12-17');

        $this->assertStringContainsString('"method":"MoonsightingCommittee"', $this->storedPayload($masjid, '2026-08-12'));

        $this->actingAsSuperAdmin();
        $this->saveCalculationSettings($masjid, [
            'method' => 'Karachi', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ])->assertOk()->assertJsonPath('status', 'success');

        // The stale rows are not merely deleted — they are back, rebuilt with
        // the method the admin just chose. Deleting alone would silence
        // SendDuePrayerNotifications for a masjid whose devices have gone quiet,
        // which is who that command exists for.
        foreach (['2026-08-10', '2026-08-12', '2026-08-14'] as $date) {
            $this->assertStringContainsString(
                '"method":"Karachi"',
                $this->storedPayload($masjid, $date),
                "{$date} still carries the old method."
            );
        }

        // Rebuilt across the whole window a default mobile request covers:
        // yesterday through +15 days, inclusive.
        $this->assertSame(
            17,
            Prayer::where('masjid_id', $masjid->id)->where('date', '>=', '2026-08-10')->count()
        );
        $this->assertNotNull(Prayer::where('masjid_id', $masjid->id)->where('date', '2026-08-26')->first());

        // History is left alone: that row is a record of what was announced
        // under the old method, and nothing serves it again.
        $this->assertPayloadMatches(self::HISTORICAL_ROW_74, $this->storedPayload($masjid, '2025-12-17'));
    }

    #[Test]
    public function the_invalidation_is_scoped_to_the_masjid_whose_settings_changed(): void
    {
        $changed = $this->makeMasjid(self::RALEIGH, [
            'method' => 'MoonsightingCommittee', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);
        $bystander = $this->makeMasjid(self::RALEIGH, [
            'method' => 'MoonsightingCommittee', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->fetchPrayers($changed, '2026-08-10', '2026-08-14');
        $this->fetchPrayers($bystander, '2026-08-10', '2026-08-14');

        $this->plantSentinel($bystander, '2026-08-12');
        $bystanderRowIds = Prayer::where('masjid_id', $bystander->id)->orderBy('date')->pluck('id')->all();

        $this->actingAsSuperAdmin();
        $this->saveCalculationSettings($changed, [
            'method' => 'Turkey', 'madhab' => 'Hanafi', 'high_latitude_rule' => 'TwilightAngle',
        ])->assertOk();

        $this->assertTrue($this->sentinelSurvives($bystander, '2026-08-12'), 'A neighbouring masjid lost its cached rows.');
        $this->assertSame($bystanderRowIds, Prayer::where('masjid_id', $bystander->id)->orderBy('date')->pluck('id')->all());
        $this->assertSame(5, Prayer::where('masjid_id', $bystander->id)->count());
    }

    #[Test]
    public function saving_the_same_values_again_does_not_churn_the_cached_rows(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, [
            'method' => 'Karachi', 'madhab' => 'Hanafi', 'high_latitude_rule' => 'SeventhOfTheNight',
        ]);

        $this->fetchPrayers($masjid, '2026-08-10', '2026-08-14');
        $this->plantSentinel($masjid, '2026-08-12');

        $this->actingAsSuperAdmin();
        $this->saveCalculationSettings($masjid, [
            'method' => 'Karachi', 'madhab' => 'Hanafi', 'high_latitude_rule' => 'SeventhOfTheNight',
        ])->assertOk();

        // Untouched: an idempotent save must not delete and regenerate every
        // cached row, which on the real dashboard happens every time an admin
        // opens the page and presses Save.
        $this->assertTrue($this->sentinelSurvives($masjid, '2026-08-12'));
        $this->assertSame(5, Prayer::where('masjid_id', $masjid->id)->count());
    }

    #[Test]
    public function first_ever_save_of_the_existing_defaults_does_not_churn_the_cached_rows(): void
    {
        // No settings row at all, so the masjid is ALREADY being calculated as
        // MoonsightingCommittee / Shafi / MiddleOfTheNight. Creating a row that
        // spells exactly that out changes no time by a single second, and must
        // not invalidate anything — otherwise every onboarding save nukes a
        // masjid's cache for nothing.
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        $this->fetchPrayers($masjid, '2026-08-10', '2026-08-14');
        $this->plantSentinel($masjid, '2026-08-12');

        $this->actingAsSuperAdmin();
        $this->saveCalculationSettings($masjid, [
            'method' => 'MoonsightingCommittee', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ])->assertOk();

        $this->assertNotNull($masjid->fresh()->prayerCalculationSettings);
        $this->assertTrue($this->sentinelSurvives($masjid, '2026-08-12'));
        $this->assertSame(5, Prayer::where('masjid_id', $masjid->id)->count());
    }

    #[Test]
    public function a_first_save_that_actually_departs_from_the_defaults_does_invalidate(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        $this->fetchPrayers($masjid, '2026-08-10', '2026-08-14');
        $this->plantSentinel($masjid, '2026-08-12');

        $this->actingAsSuperAdmin();
        $this->saveCalculationSettings($masjid, [
            'method' => 'MoonsightingCommittee', 'madhab' => 'Hanafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ])->assertOk();

        $this->assertFalse($this->sentinelSurvives($masjid, '2026-08-12'));
        $this->assertStringContainsString('"madhab":"hanafi"', $this->storedPayload($masjid, '2026-08-12'));
    }

    #[Test]
    public function the_mobile_settings_cache_is_still_flushed_so_apps_resync(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, [
            'method' => 'MoonsightingCommittee', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers/settings")
            ->assertOk()
            ->assertJsonPath('data.calculation.method', 'MoonsightingCommittee');

        $this->actingAsSuperAdmin();
        $this->saveCalculationSettings($masjid, [
            'method' => 'Singapore', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
        ])->assertOk();

        // Served settings and generated rows are the two halves that used to
        // disagree; after a change they must both be on the new method.
        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers/settings")
            ->assertOk()
            ->assertJsonPath('data.calculation.method', 'Singapore');

        $this->assertStringContainsString('"method":"Singapore"', $this->storedPayload($masjid, '2026-08-12'));
    }

    #[Test]
    public function the_served_settings_default_to_the_same_triple_the_generator_falls_back_to(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers/settings")
            ->assertOk()
            ->assertJsonPath('data.calculation.method', 'MoonsightingCommittee')
            ->assertJsonPath('data.calculation.madhab', 'Shafi')
            ->assertJsonPath('data.calculation.high_latitude_rule', 'MiddleOfTheNight');

        // Same constant on both sides — a client calculating locally and the
        // server filling the rows cannot drift.
        $this->fetchPrayers($masjid, '2025-12-17', '2025-12-17');
        $this->assertPayloadMatches(self::HISTORICAL_ROW_74, $this->storedPayload($masjid, '2025-12-17'));
    }

    // ---------------------------------- invalidation must never leave a masjid with NO times

    /**
     * A failed rebuild must leave the OLD rows in place, not delete them.
     *
     * An earlier revision deleted the cached rows inside the save transaction and
     * rebuilt afterwards best-effort, so anything that killed the rebuild — a
     * fatal, a timeout, a deploy restart — left the masjid with zero rows while
     * still answering 200. For a masjid whose devices have gone quiet, which is
     * exactly who SendDuePrayerNotifications serves, nothing would refill them and
     * the adhan push would go silent permanently. Stale times are wrong by minutes;
     * absent times are wrong by the whole prayer.
     */
    #[Test]
    public function a_failed_rebuild_leaves_the_previous_rows_intact_rather_than_wiping_them(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);
        $this->fetchPrayers($masjid, '2026-08-12', '2026-08-14');

        $before = Prayer::where('masjid_id', $masjid->id)->count();
        $this->assertGreaterThan(0, $before, 'precondition: rows exist');

        // Make the rebuild explode the way a real failure would.
        $this->app->bind(\App\Http\Controllers\Mobile\PrayersController::class, function () {
            return new class(app(\App\Services\PrayerTimes\PrayerTimesGenerator::class)) extends \App\Http\Controllers\Mobile\PrayersController
            {
                public function store($masjid_id, $rangeStart, $rangeEnd)
                {
                    throw new \RuntimeException('rebuild failed');
                }
            };
        });

        $this->actingAsSuperAdmin();
        $response = $this->saveCalculationSettings($masjid, [
            'method' => 'NorthAmerica',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
        ]);

        // The settings row still saves — but the caller is TOLD the rows are stale
        // rather than being handed a bare 200 that implies success everywhere.
        $response->assertOk()->assertJsonPath('prayers_rebuilt', false);

        $this->assertSame(
            $before,
            Prayer::where('masjid_id', $masjid->id)->count(),
            'a failed rebuild deleted cached rows — the masjid now has no prayer times at all'
        );
    }

    /**
     * A successful change rewrites the served window IN PLACE (never absent) and
     * prunes only what the rebuild could not reach.
     */
    #[Test]
    public function a_successful_change_rewrites_the_window_and_prunes_only_beyond_the_rebuild_horizon(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        try {
            $masjid = $this->makeMasjid(self::RALEIGH, null);

            // A client asked for a long range, so rows exist well past the horizon.
            $this->fetchPrayers($masjid, '2026-08-12', '2026-10-30');

            $far = Prayer::where('masjid_id', $masjid->id)->where('date', '2026-10-30')->first();
            $this->assertNotNull($far, 'precondition: a row exists beyond the rebuild horizon');

            $this->actingAsSuperAdmin();
            $this->saveCalculationSettings($masjid, [
                'method' => 'NorthAmerica',
                'madhab' => 'Shafi',
                'high_latitude_rule' => 'MiddleOfTheNight',
            ])->assertOk()->assertJsonPath('prayers_rebuilt', true);

            // Inside the window: rewritten in place, never missing.
            $this->assertStringContainsString(
                '"method":"NorthAmerica"',
                $this->storedPayload($masjid, '2026-08-13'),
                'a row inside the rebuild window kept the old method'
            );

            // Beyond it: removed, because no rebuild covers it and it would
            // otherwise serve the old method forever. It regenerates on request.
            $this->assertNull(
                Prayer::where('masjid_id', $masjid->id)->where('date', '2026-10-30')->first(),
                'a stale row beyond the rebuild horizon survived and would keep serving the old method'
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Yesterday's row IS covered after a method change — by the rebuild, which
     * starts there, not by ordinary mobile traffic.
     *
     * Worth stating because the obvious-looking alternative is wrong: making
     * index() generate from its own lower bound writes a row the endpoint never
     * returns, since `$rangeStartDate` carries a time component that excludes the
     * leading day from the SELECT as well. Generation and retrieval agree; the
     * rebuild is what reaches back a day.
     */
    #[Test]
    public function the_rebuild_reaches_back_to_yesterday_which_the_notification_command_still_scans(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        // now is pinned to 2026-08-11 12:00 by setUp, so yesterday is 2026-08-10.
        $this->fetchPrayers($masjid, '2026-08-10', '2026-08-12');

        $this->actingAsSuperAdmin();
        $this->saveCalculationSettings($masjid, [
            'method' => 'NorthAmerica',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
        ])->assertOk()->assertJsonPath('prayers_rebuilt', true);

        $this->assertStringContainsString(
            '"method":"NorthAmerica"',
            $this->storedPayload($masjid, '2026-08-10'),
            "yesterday's row kept the old method — SendDuePrayerNotifications scans now-1 and would push off it"
        );
    }

    // ---------------------------------- a corrupt row must not take the sync endpoint down

    /**
     * The mobile sync endpoint serialized the settings model directly, so Laravel's
     * enum cast raised a ValueError on a stored value that is not a case — 500-ing
     * the one endpoint an app needs in order to schedule ANY local notification.
     * It now reports the EFFECTIVE settings, read from the raw columns.
     */
    #[Test]
    public function a_corrupt_stored_value_does_not_break_the_mobile_sync_endpoint(): void
    {
        $masjid = $this->makeMasjid(self::RALEIGH, null);

        // Planted with the query builder so the enum cast is bypassed on write —
        // which is the only way such a row can arise in production.
        DB::table('prayer_calculation_settings')->insert([
            'masjid_id' => $masjid->id,
            'method' => 'BOGUS',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers/settings")
            ->assertOk()
            // Reports what the server WILL generate with, not the unusable value.
            ->assertJsonPath('data.calculation.method', 'MoonsightingCommittee');

        // And the rows it generates agree with what it just reported.
        $this->fetchPrayers($masjid, '2025-12-17', '2025-12-17');
        $this->assertPayloadMatches(self::HISTORICAL_ROW_74, $this->storedPayload($masjid, '2025-12-17'));
    }
}
