<?php

namespace Tests\Unit;

use App\Services\PrayerTimes\CalculationMethod;
use App\Services\PrayerTimes\CalculationParameters;
use App\Services\PrayerTimes\PrayerTimesGenerator;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP port of `adhan` against the JavaScript library it replaces.
 *
 * `App\Services\PrayerTimes` exists because the mobile prayers endpoint used to
 * shell out to `node resources/js/fetchPrayerTimes.js`, and Node is not
 * installed in production. A port is only worth anything if it agrees with the
 * original to the second, so this suite pins it two ways:
 *
 *  1. tests/fixtures/adhan-prayer-times.json — 612 payloads produced by
 *     adhan-js v4.4.4 itself across 14 locations (equator, both poles, both
 *     sides of the International Date Line), 18 dates (both solstices, an
 *     equinox, a leap day) and 13 parameter combinations that between them
 *     reach every branch of the calculator. Regenerate it with adhan-js, never
 *     with this port, or it stops being evidence.
 *  2. Published civil sunrise/sunset times for real cities, which owe nothing
 *     to adhan in either language.
 *
 * Nothing here touches the database or spawns a process.
 */
class AdhanPrayerTimesTest extends TestCase
{
    private PrayerTimesGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new PrayerTimesGenerator();
    }

    #[Test]
    public function it_reproduces_every_adhan_javascript_golden_vector_exactly(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__.'/../fixtures/adhan-prayer-times.json'), true);

        $this->assertGreaterThan(500, count($vectors), 'The golden vector fixture looks truncated.');

        foreach ($vectors as $index => $vector) {
            [$year, $month, $day] = array_map('intval', explode('-', $vector['date']));

            $actual = $this->generator->forDate(
                $vector['latitude'],
                $vector['longitude'],
                $year,
                $month,
                $day,
                $this->params($vector['method'], $vector['overrides'])
            );

            $label = sprintf(
                'vector #%d: %s %s %s %s',
                $index,
                $vector['location'],
                $vector['date'],
                $vector['method'],
                json_encode($vector['overrides'])
            );

            // Compared as encoded JSON, not as arrays: the stored column is the
            // JSON text, so key ORDER and number formatting are part of what
            // must match, and assertSame on arrays would not catch either.
            $this->assertSame(
                json_encode($vector['expected']),
                json_encode($actual),
                $label
            );
        }
    }

    #[Test]
    public function it_matches_the_payload_production_stored_before_the_outage(): void
    {
        // Verbatim `prayers_data` of prayers row id=74 (masjid 1, 2025-12-17),
        // written by the Node script back when Node still ran. This is the
        // strongest available proof that the port did not change the stored
        // shape: it is bytes a different implementation on a different machine
        // actually wrote, not an expectation authored alongside the port.
        $storedInProduction = '{"coordinates":{"latitude":"35.78056000","longitude":"-78.63890000"},"date":"2025-12-17T00:00:00.000Z","calculationParameters":{"madhab":"shafi","highLatitudeRule":"middleofthenight","adjustments":{"fajr":0,"sunrise":0,"dhuhr":0,"asr":0,"maghrib":0,"isha":0},"methodAdjustments":{"fajr":0,"sunrise":0,"dhuhr":5,"asr":0,"maghrib":3,"isha":0},"polarCircleResolution":"Unresolved","rounding":"nearest","shafaq":"general","method":"MoonsightingCommittee","fajrAngle":18,"ishaAngle":18,"ishaInterval":0,"maghribAngle":0},"fajr":"2025-12-17T10:47:00.000Z","sunrise":"2025-12-17T12:19:00.000Z","dhuhr":"2025-12-17T17:16:00.000Z","asr":"2025-12-17T19:45:00.000Z","sunset":"2025-12-17T22:03:00.000Z","maghrib":"2025-12-17T22:06:00.000Z","isha":"2025-12-17T23:34:00.000Z"}';

        $generated = $this->generator->forDate('35.78056000', '-78.63890000', 2025, 12, 17);

        $this->assertSame($storedInProduction, json_encode($generated));
    }

    /**
     * Sunrise and sunset are ordinary almanac facts, published everywhere and
     * derived from nobody's prayer-time library. adhan defines them at a solar
     * altitude of -50 arcminutes, the same convention almanacs use, so these
     * agree to the minute. If the port ever grows a sign error, a
     * julian-day-off-by-one, or a timezone leak, this is where it shows.
     */
    #[Test]
    public function it_agrees_with_published_sunrise_and_sunset_for_real_cities(): void
    {
        $cases = [
            // [label, lat, lon, Y, M, D, expected sunrise UTC, expected sunset UTC]
            // Raleigh, North Carolina — 17 Dec 2025, 07:19 / 17:03 EST (UTC-5).
            ['Raleigh', '35.78056000', '-78.63890000', 2025, 12, 17, '2025-12-17T12:19:00.000Z', '2025-12-17T22:03:00.000Z'],
            // Makkah — 21 Jun 2025, 05:39 / 19:06 AST (UTC+3).
            ['Makkah', '21.42250000', '39.82620000', 2025, 6, 21, '2025-06-21T02:39:00.000Z', '2025-06-21T16:06:00.000Z'],
            // London — 21 Jun 2025, 04:43 / 21:22 BST (UTC+1).
            ['London', '51.50740000', '-0.12780000', 2025, 6, 21, '2025-06-21T03:43:00.000Z', '2025-06-21T20:22:00.000Z'],
            // Jakarta — 21 Jun 2025, 06:02 / 17:47 WIB (UTC+7). Sunrise falls on
            // the PREVIOUS UTC day, which is the case that breaks any code
            // assuming a prayer day fits inside one UTC day.
            ['Jakarta', '-6.20880000', '106.84560000', 2025, 6, 21, '2025-06-20T23:02:00.000Z', '2025-06-21T10:47:00.000Z'],
            // Auckland — 21 Jun 2025, 07:34 / 17:12 NZST (UTC+12), southern
            // hemisphere and the far side of the date line.
            ['Auckland', '-36.84850000', '174.76330000', 2025, 6, 21, '2025-06-20T19:34:00.000Z', '2025-06-21T05:12:00.000Z'],
        ];

        foreach ($cases as [$label, $lat, $lon, $year, $month, $day, $sunrise, $sunset]) {
            $times = $this->generator->forDate($lat, $lon, $year, $month, $day);

            $this->assertSame($sunrise, $times['sunrise'], "{$label} sunrise");
            $this->assertSame($sunset, $times['sunset'], "{$label} sunset");
        }
    }

    #[Test]
    public function it_returns_null_for_prayers_that_do_not_occur_inside_the_polar_circle(): void
    {
        // Tromsø in midsummer: the sun never sets, so there is no sunrise,
        // sunset, maghrib, fajr or isha to report. adhan-js emits null for
        // these (JSON.stringify of an Invalid Date) and the port must too —
        // fabricating a time here would push a notification at a moment that
        // does not exist.
        $times = $this->generator->forDate('69.64920000', '18.95530000', 2025, 6, 21);

        $this->assertNull($times['fajr']);
        $this->assertNull($times['sunrise']);
        $this->assertNull($times['sunset']);
        $this->assertNull($times['maghrib']);
        $this->assertNull($times['isha']);

        // Solar transit still happens, so dhuhr and asr remain real times.
        $this->assertSame('2025-06-21T10:51:00.000Z', $times['dhuhr']);
        $this->assertSame('2025-06-21T15:58:00.000Z', $times['asr']);
    }

    #[Test]
    public function a_range_is_inclusive_of_both_ends_and_ordered_by_day(): void
    {
        $days = $this->generator->forRange(
            '35.78056000',
            '-78.63890000',
            Carbon::parse('2025-12-15 13:47:11', 'UTC'),
            Carbon::parse('2025-12-17 04:02:59', 'UTC')
        );

        // Three days, not two: the JS `while (loopDate <= to)` loop this
        // replaces included the end date, and the caller relies on it.
        $this->assertCount(3, $days);
        $this->assertSame('2025-12-15T00:00:00.000Z', $days[0]['date']);
        $this->assertSame('2025-12-16T00:00:00.000Z', $days[1]['date']);
        $this->assertSame('2025-12-17T00:00:00.000Z', $days[2]['date']);

        // The clock time of the range bounds must not leak into the payload —
        // the `date` key is a label for the civil day, always midnight UTC.
        $this->assertSame('2025-12-17T10:47:00.000Z', $days[2]['fajr']);
    }

    #[Test]
    public function a_range_that_ends_before_it_starts_produces_nothing(): void
    {
        $days = $this->generator->forRange(
            '35.78056000',
            '-78.63890000',
            Carbon::parse('2025-12-17', 'UTC'),
            Carbon::parse('2025-12-15', 'UTC')
        );

        $this->assertSame([], $days);
    }

    #[Test]
    public function a_range_spanning_a_month_and_year_boundary_stays_on_consecutive_days(): void
    {
        $days = $this->generator->forRange(
            '35.78056000',
            '-78.63890000',
            Carbon::parse('2025-12-30', 'UTC'),
            Carbon::parse('2026-01-02', 'UTC')
        );

        $this->assertSame(
            [
                '2025-12-30T00:00:00.000Z',
                '2025-12-31T00:00:00.000Z',
                '2026-01-01T00:00:00.000Z',
                '2026-01-02T00:00:00.000Z',
            ],
            array_column($days, 'date')
        );
    }

    #[Test]
    public function the_calculation_never_reads_the_process_timezone(): void
    {
        // The masjid's timezone is deliberately not an input: adhan derives
        // absolute UTC instants from a civil date and a coordinate pair, and
        // the stored rows have always been UTC. Proving the process timezone is
        // inert here is what rules out the failure mode where every prayer time
        // is silently shifted by an offset and still looks plausible.
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Kiritimati'); // UTC+14
            $east = $this->generator->forDate('35.78056000', '-78.63890000', 2025, 12, 17);

            date_default_timezone_set('Pacific/Midway'); // UTC-11
            $west = $this->generator->forDate('35.78056000', '-78.63890000', 2025, 12, 17);
        } finally {
            date_default_timezone_set($original);
        }

        $this->assertSame(json_encode($east), json_encode($west));
        $this->assertSame('2025-12-17T10:47:00.000Z', $east['fajr']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function params(string $method, array $overrides): CalculationParameters
    {
        $params = match ($method) {
            'MoonsightingCommittee' => CalculationMethod::moonsightingCommittee(),
            'MuslimWorldLeague' => CalculationMethod::muslimWorldLeague(),
            'UmmAlQura' => CalculationMethod::ummAlQura(),
            'Tehran' => CalculationMethod::tehran(),
        };

        foreach ($overrides as $key => $value) {
            if ($key === 'adjustments') {
                $params->adjustments = array_merge($params->adjustments, $value);
            } else {
                $params->{$key} = $value;
            }
        }

        return $params;
    }
}
