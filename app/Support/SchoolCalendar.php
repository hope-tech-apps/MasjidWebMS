<?php

namespace App\Support;

use App\Http\Controllers\AdminDashboard\FormStaffCodesController;
use App\Models\Masjid;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The ONE place a question about a school's calendar is answered.
 *
 * The register (AttendanceController), the admin screen, the teacher and family
 * reads, and the calendar-sourced form field (FormOptionSources) all ask here,
 * so "is there school on the 22nd" cannot get two answers.
 *
 * Three rules every method keeps:
 *
 *  - **Days are 'Y-m-d' strings.** The `date` cast stores 'Y-m-d 00:00:00' on
 *    SQLite, so queries use whereDate() and in-memory checks compare
 *    toDateString() — never a raw BETWEEN (LessonPlanController::index).
 *  - **"Today" is the SCHOOL's today**, in masjids.timezone via
 *    FormStaffCodesController::timezoneFor() (an unset 'UTC' reads as
 *    America/New_York). A UTC today would close Sunday's sign-up at 8pm Eastern
 *    on Saturday.
 *  - **Queries name the organisation explicitly.** The public form paths run
 *    with no tenant bound, where the global scope adds no filter at all. The
 *    scope is still left on, so a caller bound to a DIFFERENT tenant gets an
 *    empty calendar rather than someone else's.
 *
 * An organisation with no school year has no calendar, and everything that
 * reads one behaves exactly as it did before calendars existed (Al-Razi).
 */
final class SchoolCalendar
{
    /** How many meeting days the teacher and family reads list ahead. */
    public const UPCOMING = 12;

    /**
     * 53 weeks. A longer "year" is a typo in the last day, and the bound also
     * caps how many days one year can expand into.
     */
    public const MAX_YEAR_DAYS = 371;

    /** @param  Collection<int,SchoolYear>  $years  ordered by first_day, closures loaded */
    private function __construct(
        private readonly string $timezone,
        private readonly Collection $years,
    ) {
    }

    public static function for(int $masjidId): self
    {
        $masjid = Masjid::find($masjidId);

        $years = SchoolYear::query()
            ->where('masjid_id', $masjidId)
            ->with(['closures' => fn ($q) => $q->orderBy('closed_on')])
            ->orderBy('first_day')
            ->get();

        return new self(
            $masjid ? FormStaffCodesController::timezoneFor($masjid)['name'] : 'UTC',
            $years,
        );
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /** Today as 'Y-m-d' on the school's clock. */
    public function today(): string
    {
        return Date::now()->setTimezone($this->timezone)->toDateString();
    }

    /** @return Collection<int,SchoolYear> */
    public function years(): Collection
    {
        return $this->years;
    }

    public function hasCalendar(): bool
    {
        return $this->years->isNotEmpty();
    }

    /**
     * The weekdays this school meets on (0 = Sunday), one per distinct year
     * weekday, ascending. `[]` with no calendar.
     *
     * @return list<int>
     */
    public function meetingWeekdays(): array
    {
        return $this->years->map(fn (SchoolYear $y) => $y->meetingWeekday())
            ->unique()->sort()->values()->all();
    }

    public function yearContaining(string $day): ?SchoolYear
    {
        return $this->years->first(fn (SchoolYear $y) => $y->first_day->toDateString() <= $day
            && $day <= $y->last_day->toDateString());
    }

    /** @return array<int,string> every meeting day of the year, closed ones included */
    public function meetingDays(SchoolYear $year): array
    {
        return self::meetingDaysBetween($year->first_day->toDateString(), $year->last_day->toDateString());
    }

    /** @return array<int,string> every 7th day from $first to $last, inclusive */
    public static function meetingDaysBetween(string $first, string $last): array
    {
        $day = self::day($first);
        $end = self::day($last);
        $out = [];

        for ($i = 0; $day !== null && $end !== null && $day->lte($end) && $i <= intdiv(self::MAX_YEAR_DAYS, 7); $i++) {
            $out[] = $day->toDateString();
            $day = $day->addDays(7);
        }

        return $out;
    }

    /** @return array<int,array{date:string,closed:bool,reason:?string}> */
    public function days(SchoolYear $year): array
    {
        $closures = $year->closures->keyBy(fn (SchoolClosure $c) => $c->closed_on->toDateString());

        return array_map(fn (string $d): array => [
            'date' => $d,
            'closed' => $closures->has($d),
            'reason' => $closures->get($d)?->reason,
        ], $this->meetingDays($year));
    }

    public function closureOn(string $day): ?SchoolClosure
    {
        return $this->yearContaining($day)?->closures
            ->first(fn (SchoolClosure $c) => $c->closed_on->toDateString() === $day);
    }

    public function isMeetingDay(string $day): bool
    {
        $year = $this->yearContaining($day);
        $date = self::day($day);

        return $year !== null && $date !== null && $date->dayOfWeek === $year->meetingWeekday();
    }

    /**
     * What the register needs to know about one day. Only `closed` is enforced;
     * the rest are hints, because a school without a calendar (or a make-up day
     * off the weekday) must still be able to take a register.
     *
     * @return array{has_calendar:bool,in_year:bool,meeting_day:bool,closed:bool,reason:?string}
     */
    public function schoolDay(string $day): array
    {
        $closure = $this->closureOn($day);

        return [
            'has_calendar' => $this->hasCalendar(),
            'in_year' => $this->yearContaining($day) !== null,
            'meeting_day' => $this->isMeetingDay($day),
            'closed' => $closure !== null,
            'reason' => $closure?->reason,
        ];
    }

    /**
     * THE LABEL SET: every meeting day of every year, open or closed. What an
     * answer already given is read against, so it stays readable after its day
     * passes or closes.
     *
     * @return array<int,array{date:string,closed:bool,reason:?string}>
     */
    public function labelledDays(): array
    {
        return $this->years->flatMap(fn (SchoolYear $y) => $this->days($y))->values()->all();
    }

    /** @return array<int,array{date:string,closed:bool,reason:?string}> the next meeting days from today, today included */
    public function upcoming(int $count = self::UPCOMING): array
    {
        $today = $this->today();

        return collect($this->labelledDays())
            ->filter(fn (array $d) => $d['date'] >= $today)
            ->take($count)->values()->all();
    }

    /**
     * THE OFFER SET: open meeting days STRICTLY after today. What a form offers
     * and what a submission is checked against — both, so the page and the
     * server cannot disagree. Today is not offered: by the time a family
     * submits on a Sunday morning, that Sunday has started.
     *
     * @return array<int,string>
     */
    public function offerableDays(): array
    {
        $today = $this->today();

        return collect($this->labelledDays())
            ->filter(fn (array $d) => ! $d['closed'] && $d['date'] > $today)
            ->pluck('date')->values()->all();
    }

    /**
     * The closure on one day, read straight from the database. With $lock, the
     * containing year's row is locked first — the same lock
     * SchoolCalendarController::storeClosure takes before counting register
     * marks — so a register save and a new closure for the same day serialize
     * instead of racing. Call it inside a transaction when locking.
     */
    public static function closureFor(int $masjidId, string $day, bool $lock = false): ?SchoolClosure
    {
        $next = self::day($day)?->addDay()->toDateString();

        if ($next === null) {
            return null;
        }

        if ($lock) {
            $year = SchoolYear::query()
                ->where('masjid_id', $masjidId)
                ->whereDate('first_day', '<=', $day)
                ->whereDate('last_day', '>=', $day)
                ->orderBy('first_day')
                ->lockForUpdate()
                ->first();

            if ($year === null) {
                return null;
            }
        }

        // By (masjid_id, closed_on), which school_closure_org_day_idx serves. A
        // HALF-OPEN range on the raw column rather than whereDate(): DATE() around
        // the column stops MySQL using the index, and `>= day AND < next day` is
        // still right on SQLite, where the cast stores 'Y-m-d 00:00:00' (the
        // closed BETWEEN is what LessonPlanController::index warns about).
        return SchoolClosure::query()
            ->where('masjid_id', $masjidId)
            ->where('closed_on', '>=', $day)
            ->where('closed_on', '<', $next)
            ->first();
    }

    /**
     * The earliest of an organisation's years sharing a day with [$first, $last],
     * leaving out the year being edited. StoreSchoolYearRequest asks it for the
     * message; SchoolCalendarController asks it again under the organisation's
     * row lock, where the answer cannot change before the write.
     */
    public static function overlappingYear(int $masjidId, string $first, string $last, ?int $ignoreYearId = null): ?SchoolYear
    {
        return SchoolYear::query()
            ->where('masjid_id', $masjidId)
            ->when($ignoreYearId, fn ($q, int $id) => $q->whereKeyNot($id))
            ->whereDate('first_day', '<=', $last)
            ->whereDate('last_day', '>=', $first)
            ->orderBy('first_day')
            ->first();
    }

    public static function overlapMessage(SchoolYear $year): string
    {
        return sprintf(
            'These dates overlap the %s school year (%s to %s).',
            $year->label,
            self::label($year->first_day->toDateString()),
            self::label($year->last_day->toDateString()),
        );
    }

    public static function noRegisterMessage(SchoolClosure $closure): string
    {
        return sprintf(
            'There was no school on %s (%s), so there is no register to take.',
            self::label($closure->closed_on->toDateString()),
            $closure->reason
        );
    }

    /** 'Sunday, October 11, 2026'. Anything that is not a real ISO date comes back as given. */
    public static function label(string $day): string
    {
        return self::day($day)?->format('l, F j, Y') ?? $day;
    }

    /** 0 → 'Sunday'. */
    public static function weekdayName(int $dayOfWeek): string
    {
        // 2026-10-11 is a Sunday.
        return CarbonImmutable::create(2026, 10, 11, 0, 0, 0, 'UTC')->addDays($dayOfWeek % 7)->format('l');
    }

    public static function isIsoDate(mixed $value): bool
    {
        return is_string($value) && self::day($value) !== null;
    }

    /** A strict 'Y-m-d' as midnight UTC — calendar arithmetic with no DST in it — or null. */
    public static function day(string $iso): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC');
    }
}
