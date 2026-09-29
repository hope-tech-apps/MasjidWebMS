<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * One points week: a calendar week on the SCHOOL's clock, as instants.
 *
 * T-003.2 makes a week's end a VIEW boundary. Nothing is deleted, revoked or
 * moved when a week turns over: an award still belongs to the instant it
 * happened, and this class only says which instants make up "this week".
 *
 * ## The week starts on a named day (Sunday, unless a caller says otherwise)
 *
 * `START_DAY` is 0 = Sunday, the convention SchoolCalendar and the school
 * calendar screens already use. It is passed to every constructor rather than
 * read from a global so a school that wants a Monday week is one argument away,
 * and so no test can pass by accident on today's default.
 *
 * ## Boundaries are built in LOCAL time, then converted
 *
 * A week is Sunday 00:00 to the next Sunday 00:00 on the school's wall clock. The
 * two ends are constructed as local midnights and only then turned into UTC, so
 * the week that holds a daylight-saving change is 167 or 169 hours long instead
 * of a fixed 168. Adding 7 * 86400 seconds to the start (the tempting shortcut)
 * ends that week an hour early or late, and a Saturday-night award lands in the
 * wrong report. `PointsWeekTest` pins both changes for America/New_York.
 *
 * ## Instants, never dates
 *
 * `startUtc()` / `endUtc()` feed `BehaviorAward::scopeAwardedWithin`, which compares
 * `awarded_at` as a half-open instant range `[start, end)`. It must NOT use
 * `whereDate`: `DATE(awarded_at)` is the UTC date, so a Friday-evening Eastern
 * award (after 20:00 EDT / 19:00 EST) is already Saturday in the column and would
 * fall into the wrong week (and MySQL could not use the index).
 *
 * A pure value object: no database, no request. The week's identity (for
 * `?week=` and for `behavior_weeks.week_start`) is its start date in local
 * time, 'Y-m-d'.
 */
final class PointsWeek
{
    /** 0 = Sunday, as SchoolCalendar and the school-calendar screens number days. */
    public const START_DAY = 0;

    private function __construct(
        private readonly CarbonImmutable $startLocal,
        private readonly CarbonImmutable $endLocal,
        private readonly string $timezone,
    ) {
    }

    /** The week that holds `$instant` on the school's clock. */
    public static function containing(CarbonInterface $instant, string $timezone, int $startDay = self::START_DAY): self
    {
        $local = CarbonImmutable::instance($instant)->setTimezone(new DateTimeZone($timezone));

        return self::startingOn(
            $local->format('Y-m-d'),
            $timezone,
            $startDay,
        ) ?? throw new \LogicException('A real instant always has a real local date.');
    }

    /**
     * The week that holds the local calendar day `$isoDay` ('Y-m-d'), or null when
     * that is not a real date. Any day of the week will do: `?week=2026-10-07` and
     * `?week=2026-10-04` name the same Sunday-start week.
     */
    public static function startingOn(string $isoDay, string $timezone, int $startDay = self::START_DAY): ?self
    {
        $day = SchoolCalendar::day($isoDay);

        if ($day === null) {
            return null;
        }

        // Calendar arithmetic on UTC midnights carries no DST in it; the wall-clock
        // midnights are made below, in the school's own zone.
        $back = (($day->dayOfWeek - $startDay) % 7 + 7) % 7;
        $first = $day->subDays($back);
        $next = $first->addDays(7);

        $tz = new DateTimeZone($timezone);

        return new self(
            CarbonImmutable::create($first->year, $first->month, $first->day, 0, 0, 0, $tz),
            CarbonImmutable::create($next->year, $next->month, $next->day, 0, 0, 0, $tz),
            $timezone,
        );
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /** Identity: the first local day, 'Y-m-d'. */
    public function startDate(): string
    {
        return $this->startLocal->format('Y-m-d');
    }

    /** The last local day, 'Y-m-d' (the day before the next week starts). */
    public function lastDate(): string
    {
        return $this->endLocal->subDay()->format('Y-m-d');
    }

    /** The first instant of the week, on the school's clock. */
    public function start(): CarbonImmutable
    {
        return $this->startLocal;
    }

    /** The first instant of the NEXT week (exclusive end), on the school's clock. */
    public function end(): CarbonImmutable
    {
        return $this->endLocal;
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->startLocal->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->endLocal->utc();
    }

    public function contains(CarbonInterface $instant): bool
    {
        $at = CarbonImmutable::instance($instant);

        return $at->gte($this->startLocal) && $at->lt($this->endLocal);
    }

    /** The week before, on the same day and zone. */
    public function previous(): self
    {
        return self::startingOn($this->startLocal->subDay()->format('Y-m-d'), $this->timezone, $this->startLocal->dayOfWeek)
            ?? throw new \LogicException('The day before a real week is a real day.');
    }

    /** The week after. */
    public function next(): self
    {
        return self::startingOn($this->endLocal->format('Y-m-d'), $this->timezone, $this->startLocal->dayOfWeek)
            ?? throw new \LogicException('The day after a real week is a real day.');
    }

    /**
     * The instant `$weekday` (0 = Sunday) at `$hhmm` ('H:i') falls on inside THIS
     * week, on the school's clock. A Friday 15:00 report and a Sunday 18:00 report
     * are both "the week's own" instant, which is what the report's cutoff is.
     */
    public function at(int $weekday, string $hhmm): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm));

        $offset = (($weekday - $this->startLocal->dayOfWeek) % 7 + 7) % 7;
        $day = SchoolCalendar::day($this->startDate())?->addDays($offset)
            ?? throw new \LogicException('A week always starts on a real day.');

        return CarbonImmutable::create($day->year, $day->month, $day->day, $hour, $minute, 0, new DateTimeZone($this->timezone));
    }

    /**
     * The payload every client renders the week from. Dates, not instants: the
     * screen says "Oct 4 - Oct 10", and the instants are the server's business.
     *
     * @return array{start:string,end:string,previous:string,next:string,timezone:string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->startDate(),
            'end' => $this->lastDate(),
            'previous' => $this->previous()->startDate(),
            'next' => $this->next()->startDate(),
            'timezone' => $this->timezone,
        ];
    }
}
