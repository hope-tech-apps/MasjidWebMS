<?php

namespace App\Support;

use App\Models\SchoolYear;

/**
 * Calendar authority for configured years, shared by office and enabled readers.
 * ISO dates use UTC arithmetic; today uses the school's timezone. OFF ignores dormant data.
 */
final class SchoolDateAuthority
{
    private function __construct(private readonly SchoolCalendar $legacy, private readonly bool $enabled) {}

    public static function for(int $masjidId): self
    {
        $legacy = SchoolCalendar::for($masjidId);
        return self::fromLegacy($legacy, SchoolCalendarRequestMode::afterLegacyRead($masjidId));
    }

    public static function fromLegacy(SchoolCalendar $legacy, bool $enabled): self { return new self($legacy, $enabled); }

    public function years(): \Illuminate\Support\Collection { return $this->legacy->years(); }
    public function timezone(): string { return $this->legacy->timezone(); }
    public function today(): string { return $this->legacy->today(); }

    /** NULL is the legacy single weekday, including years committed after enable. Empty stays empty. */
    public static function weekdays(SchoolYear $year): array { return $year->meeting_weekdays ?? [$year->meetingWeekday()]; }

    /** Every configured date, closures included; OFF expands every seventh day. */
    public function meetingDays(SchoolYear $year): array
    {
        if (! $this->years()->contains(fn ($y) => $y->id === $year->id)) return [];
        if (! $this->enabled) return $this->legacy->meetingDays($year);
        $out = []; $day = SchoolCalendar::day($year->first_day->toDateString());
        $last = $year->last_day->toDateString();
        for ($i = 0; $day !== null && $day->toDateString() <= $last && $i <= SchoolCalendar::MAX_YEAR_DAYS; $i++, $day = $day->addDay()) {
            if (in_array($day->dayOfWeek, self::weekdays($year), true)) $out[] = $day->toDateString();
        }
        return $out;
    }

    public function isMeetingDay(string $day): bool
    {
        if (! $this->enabled) return $this->legacy->isMeetingDay($day);
        $year = $this->legacy->yearContaining($day); $date = SchoolCalendar::day($day);
        return $year !== null && $date !== null && in_array($date->dayOfWeek, self::weekdays($year), true);
    }

    /** Open dates inside the inclusive requested interval, in date order. */
    public function openDaysBetween(string $first, string $last): array
    {
        return array_column(array_values(array_filter($this->labelledDays(), fn ($d) => ! $d['closed'] && $d['date'] >= $first && $d['date'] <= $last)), 'date');
    }

    /** The configured open weekdays of a requested week/range; never a union of unrelated years. */
    public function meetingWeekdaysBetween(string $first, string $last): array
    {
        $days = array_unique(array_map(fn ($d) => SchoolCalendar::day($d)->dayOfWeek, $this->openDaysBetween($first, $last)));
        sort($days); return array_values($days);
    }

    /** All dates with closure wording retained, in date order. */
    public function labelledDays(): array
    {
        return $this->years()->flatMap(function (SchoolYear $year) {
            $closures = $year->closures->keyBy(fn ($c) => $c->closed_on->toDateString());
            return array_map(fn ($d) => ['date' => $d, 'closed' => $closures->has($d), 'reason' => $closures->get($d)?->reason], $this->meetingDays($year));
        })->values()->all();
    }

    /** Twelve successive meeting dates from today, including flagged closures. */
    public function upcoming(int $count = SchoolCalendar::UPCOMING): array
    {
        return array_slice(array_values(array_filter($this->labelledDays(), fn ($d) => $d['date'] >= $this->today())), 0, max(0, $count));
    }
    public function hasCalendar(): bool { return $this->legacy->hasCalendar(); }

    /** Same register hints as legacy; only a dated closure refuses a register. */
    public function schoolDay(string $day): array
    {
        $status = $this->legacy->schoolDay($day);
        $status['meeting_day'] = $this->isMeetingDay($day);
        return $status;
    }

    /** Live form choices exclude today, past days and closures. */
    public function offerableDays(): array
    {
        return array_column(array_values(array_filter($this->labelledDays(), fn ($day) => ! $day['closed'] && $day['date'] > $this->today())), 'date');
    }

}
