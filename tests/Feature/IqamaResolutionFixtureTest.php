<?php

namespace Tests\Feature;

use App\Http\Resources\Api\V1\IqamaTimeSettingResource;
use App\Models\IqamaTimeSetting;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The backend's share of the canonical iqama fixture.
 *
 * Iqama resolution exists FOUR times across this fleet — here, iOS PrayerTimesManager,
 * MasjidKit PrayerCalculator, and Android IqamaResolver — and has drifted in production
 * twice. tests/fixtures/iqama-resolution.json is the one description of the rule; every
 * implementation asserts against a byte-identical copy of it, so they cannot disagree
 * silently again.
 *
 * The backend's job is narrower than the apps': it does not compute adhan times, it only
 * decides which fixed time applies today (IqamaTimeSettingResource::getCurrentTimeForSalah).
 * So this test drives the date-window selection with each case's date and checks the
 * chosen fixed time, and separately checks the fallback when no window covers the date.
 */
class IqamaResolutionFixtureTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path('tests/fixtures/iqama-resolution.json');

        $this->assertFileExists($path, 'The canonical iqama fixture is missing.');

        $this->fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Build an unsaved IqamaTimeSetting carrying the fixture's ranges, so the Resource
     * can be exercised without touching the database.
     */
    private function settingFrom(array $settings): IqamaTimeSetting
    {
        $model = new IqamaTimeSetting();

        $model->iqama_type = $settings['iqama_type'];

        foreach ($settings['offsets'] as $salah => $minutes) {
            $model->{$salah} = $minutes;
        }

        $ranges = collect($settings['time_ranges'])->map(function (array $row) {
            $range = new \App\Models\IqamaTimeRange();
            $range->salah = $row['salah'];
            $range->start_date = $row['start_date'];
            $range->end_date = $row['end_date'];
            $range->specific_time = $row['specific_time'];

            return $range;
        });

        // The Resource reads $this->timeRanges; seed the loaded relation directly.
        $model->setRelation('timeRanges', $ranges);

        return $model;
    }

    /** "05:25:00" -> "05:25 AM", the format the Resource emits. */
    private function toDisplay(string $time): string
    {
        return Carbon::parse($time)->format('h:i A');
    }

    #[Test]
    public function the_fixture_declares_the_shape_this_test_expects(): void
    {
        $this->assertArrayHasKey('settings', $this->fixture);
        $this->assertArrayHasKey('cases', $this->fixture);
        $this->assertNotEmpty($this->fixture['cases'], 'A fixture with no cases would pass vacuously.');
    }

    #[Test]
    public function fixed_time_ranges_resolve_for_every_fixture_case(): void
    {
        $setting = $this->settingFrom($this->fixture['settings']);

        foreach ($this->fixture['cases'] as $case) {
            // The Resource resolves against "today", so freeze the clock per case.
            Carbon::setTestNow(Carbon::parse($case['date'] . ' 12:00:00'));

            $resolved = (new IqamaTimeSettingResource($setting))
                ->toArray(request())['specific_time_ranges'];

            foreach (['fajr', 'dhuhr', 'asr'] as $salah) {
                $expectedFixed = $this->expectedFixedFor($salah, $case['date']);

                if ($expectedFixed === null) {
                    $this->assertNull(
                        $resolved[$salah],
                        "[{$case['name']}] {$salah}: no range covers {$case['date']}, so the resource must return null and let the offset path apply."
                    );

                    continue;
                }

                $this->assertSame(
                    $this->toDisplay($expectedFixed),
                    $resolved[$salah],
                    "[{$case['name']}] {$salah} on {$case['date']}"
                );
            }
        }

        Carbon::setTestNow();
    }

    /**
     * The boundary that actually broke production: the website served the 07-21..25
     * value on 07-26. Both bounds are inclusive.
     */
    #[Test]
    public function range_boundaries_are_inclusive_on_both_ends(): void
    {
        $setting = $this->settingFrom($this->fixture['settings']);

        $expectations = [
            '2026-07-25' => '05:20 AM', // last day of its range
            '2026-07-26' => '05:25 AM', // first day of the next
            '2026-07-31' => '05:25 AM', // last day of the month
        ];

        foreach ($expectations as $date => $expected) {
            Carbon::setTestNow(Carbon::parse($date . ' 12:00:00'));

            $resolved = (new IqamaTimeSettingResource($setting))
                ->toArray(request())['specific_time_ranges'];

            $this->assertSame($expected, $resolved['fajr'], "fajr on {$date}");
        }

        Carbon::setTestNow();
    }

    #[Test]
    public function a_date_outside_every_range_resolves_to_null_not_the_first_range(): void
    {
        $setting = $this->settingFrom($this->fixture['settings']);

        foreach (['2026-08-05', '2026-05-31'] as $date) {
            Carbon::setTestNow(Carbon::parse($date . ' 12:00:00'));

            $resolved = (new IqamaTimeSettingResource($setting))
                ->toArray(request())['specific_time_ranges'];

            $this->assertNull($resolved['fajr'], "fajr on {$date} should have no fixed time");
            $this->assertNull($resolved['dhuhr'], "dhuhr on {$date} should have no fixed time");
        }

        Carbon::setTestNow();
    }

    /** Maghrib and Isha never have ranges here — they must always fall through. */
    #[Test]
    public function prayers_without_ranges_always_resolve_to_null(): void
    {
        $setting = $this->settingFrom($this->fixture['settings']);

        Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

        $resolved = (new IqamaTimeSettingResource($setting))
            ->toArray(request())['specific_time_ranges'];

        $this->assertNull($resolved['maghrib']);
        $this->assertNull($resolved['isha']);

        Carbon::setTestNow();
    }

    /** Which fixed time the fixture says applies on a date, or null if none does. */
    private function expectedFixedFor(string $salah, string $date): ?string
    {
        foreach ($this->fixture['settings']['time_ranges'] as $range) {
            if ($range['salah'] !== $salah) {
                continue;
            }

            if ($date >= $range['start_date'] && $date <= $range['end_date']) {
                return $range['specific_time'];
            }
        }

        return null;
    }
}
