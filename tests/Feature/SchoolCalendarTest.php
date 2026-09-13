<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Support\SchoolCalendar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * App\Support\SchoolCalendar — the one authority on a school's days.
 *
 * The fixture is BISS's real shape: Sundays from 2026-10-11, Thanksgiving
 * weekend off, a school on America/New_York. The timezone test is the one that
 * matters most: a UTC "today" would close Sunday's sign-up at 8pm on Saturday.
 */
class SchoolCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();

        $this->school = $this->makeMasjid(['timezone' => 'America/New_York']);

        $year = SchoolYear::create([
            'masjid_id' => $this->school->id, 'label' => '2026–27',
            'first_day' => '2026-10-11', 'last_day' => '2027-05-30',
        ]);

        SchoolClosure::create([
            'masjid_id' => $this->school->id, 'school_year_id' => $year->id,
            'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend',
        ]);
    }

    #[Test]
    public function meeting_days_repeat_every_seven_days_from_the_first_day_to_the_last(): void
    {
        $calendar = SchoolCalendar::for($this->school->id);
        $year = $calendar->years()->first();
        $days = $calendar->meetingDays($year);

        $this->assertSame(0, $year->meetingWeekday(), '2026-10-11 is a Sunday');
        $this->assertCount(34, $days);
        $this->assertSame('2026-10-11', $days[0]);
        $this->assertSame('2026-10-18', $days[1]);
        $this->assertSame('2027-05-30', $days[33]);

        foreach ($days as $day) {
            $this->assertSame(0, SchoolCalendar::day($day)->dayOfWeek, "{$day} is not a Sunday");
        }
    }

    #[Test]
    public function a_closure_is_flagged_with_its_reason_and_other_days_are_described(): void
    {
        $calendar = SchoolCalendar::for($this->school->id);

        $this->assertSame(
            ['has_calendar' => true, 'in_year' => true, 'meeting_day' => true, 'closed' => true, 'reason' => 'Thanksgiving weekend'],
            $calendar->schoolDay('2026-11-22')
        );
        $this->assertSame(
            ['has_calendar' => true, 'in_year' => true, 'meeting_day' => false, 'closed' => false, 'reason' => null],
            $calendar->schoolDay('2026-11-23')
        );
        $this->assertFalse($calendar->schoolDay('2027-07-04')['in_year']);

        $this->assertSame(
            ['has_calendar' => false, 'in_year' => false, 'meeting_day' => false, 'closed' => false, 'reason' => null],
            SchoolCalendar::for($this->makeMasjid()->id)->schoolDay('2026-11-22')
        );
    }

    #[Test]
    public function the_offer_set_is_open_meeting_days_strictly_after_today(): void
    {
        // 11:00 on Sunday 25 October in New York.
        $this->travelTo(Carbon::parse('2026-10-25 15:00:00'));

        $offered = SchoolCalendar::for($this->school->id)->offerableDays();

        $this->assertSame('2026-11-01', $offered[0], 'today is not offered, and neither is any day before it');
        $this->assertNotContains('2026-10-25', $offered);
        $this->assertNotContains('2026-10-18', $offered);
        $this->assertNotContains('2026-11-22', $offered, 'a closed day is never offered');
        $this->assertContains('2026-11-29', $offered);
        // 34 Sundays, less the three up to and including today, less the closed one.
        $this->assertCount(30, $offered);
    }

    #[Test]
    public function the_day_turns_over_on_the_schools_clock_not_utc(): void
    {
        // Sunday 03:30 UTC is still Saturday 23:30 in New York.
        $this->travelTo(Carbon::parse('2026-10-18 03:30:00'));

        $calendar = SchoolCalendar::for($this->school->id);

        $this->assertSame('2026-10-17', $calendar->today());
        $this->assertSame('2026-10-18', $calendar->offerableDays()[0]);

        // A timezone never set reads as the platform fallback, never as UTC.
        $unset = $this->makeMasjid(['timezone' => 'UTC']);
        $this->assertSame('America/New_York', SchoolCalendar::for($unset->id)->timezone());
    }

    #[Test]
    public function upcoming_lists_twelve_meeting_days_from_today_with_closures_flagged(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 15:00:00'));

        $upcoming = SchoolCalendar::for($this->school->id)->upcoming();

        $this->assertCount(12, $upcoming);
        $this->assertSame(['date' => '2026-11-15', 'closed' => false, 'reason' => null], $upcoming[0]);
        $this->assertSame(['date' => '2026-11-22', 'closed' => true, 'reason' => 'Thanksgiving weekend'], $upcoming[1]);
    }

    #[Test]
    public function days_read_the_way_a_person_says_them(): void
    {
        $this->assertSame('Sunday, October 11, 2026', SchoolCalendar::label('2026-10-11'));
        $this->assertSame('not a date', SchoolCalendar::label('not a date'));
        $this->assertSame('Sunday', SchoolCalendar::weekdayName(0));
        $this->assertSame('Saturday', SchoolCalendar::weekdayName(6));
        $this->assertNull(SchoolCalendar::day('2026-02-30'));
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'school',
        ], $overrides));
    }
}
