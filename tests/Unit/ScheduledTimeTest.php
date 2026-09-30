<?php

namespace Tests\Unit;

use App\Support\ScheduledTime;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a "Send later" time (T-002.4): a time that is not a real date is refused,
 * never rolled onto the next month by PHP's own arithmetic.
 *
 * `CarbonImmutable::create(2026, 2, 31, ...)` is 3 March 2026 and raises nothing, so the
 * calendar check in ScheduledTime::parse() is the only thing that says "no". A date that
 * rolled into the future would otherwise be scheduled for a day nobody typed. The
 * feature tests cannot pin it alone: a rolled `2026-02-31` lands in the past there and
 * is refused for that reason.
 */
class ScheduledTimeTest extends TestCase
{
    private const NY = 'America/New_York';

    #[Test]
    public function a_day_that_does_not_exist_is_not_a_time(): void
    {
        foreach ([
            '2026-02-31T10:00' => 'February 31st',
            '2026-02-29T10:00' => '29 February in a year that is not a leap year',
            '2100-02-29T10:00' => '29 February in a century year that is not a leap year',
            '2026-04-31T10:00' => 'April 31st',
            '2026-09-31T10:00' => 'September 31st',
            '2026-13-01T10:00' => 'the thirteenth month',
            '2026-00-10T10:00' => 'month zero',
            '2026-10-00T10:00' => 'day zero',
            '2026-10-32T10:00' => 'October 32nd',
        ] as $value => $why) {
            $this->assertNull(ScheduledTime::parse($value, self::NY), "{$why} was read as a time");
        }
    }

    #[Test]
    public function a_leap_day_and_the_last_day_of_a_month_are_times(): void
    {
        $leap = ScheduledTime::parse('2028-02-29T10:00', self::NY);
        $this->assertNotNull($leap);
        $this->assertSame('2028-02-29 15:00:00', $leap->utc()->toDateTimeString());

        $this->assertNotNull(ScheduledTime::parse('2026-09-30T10:00', self::NY));
        $this->assertNotNull(ScheduledTime::parse('2026-10-31T23:59', self::NY));
        $this->assertNotNull(ScheduledTime::parse('2000-02-29T10:00', self::NY), '2000 was a leap year');
    }

    #[Test]
    public function an_hour_minute_or_second_out_of_range_is_not_a_time(): void
    {
        foreach (['2026-10-05T24:00', '2026-10-05T10:60', '2026-10-05T10:61', '2026-10-05T10:00:60', '2026-10-05T25:00'] as $value) {
            $this->assertNull(ScheduledTime::parse($value, self::NY), "{$value} was read as a time");
        }

        foreach (['2026-10-05T00:00', '2026-10-05T23:59', '2026-10-05T23:59:59'] as $value) {
            $this->assertNotNull(ScheduledTime::parse($value, self::NY), "{$value} was refused");
        }
    }

    #[Test]
    public function the_refusal_names_the_edges_of_the_window_exactly(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');

        try {
            $now = \Carbon\CarbonImmutable::now();

            $this->assertNotNull(ScheduledTime::refusal($now), 'right now is not the future');
            $this->assertNotNull(ScheduledTime::refusal($now->subSecond()));
            $this->assertNull(ScheduledTime::refusal($now->addSecond()));
            $this->assertNull(ScheduledTime::refusal($now->addDays(30)), 'exactly 30 days ahead is the last allowed instant');
            $this->assertNotNull(ScheduledTime::refusal($now->addDays(30)->addSecond()));
        } finally {
            Carbon::setTestNow();
        }
    }
}
