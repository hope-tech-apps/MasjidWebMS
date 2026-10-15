<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** English display labels for student reads; calendar days are school-local, not UTC instants. */
final class StudentDates
{
    public static function day(?string $date, int $schoolId, bool $withYear = false): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date, SchoolCalendarReaders::for($schoolId)->timezone())->format($withYear ? 'D, M j, Y' : 'D, M j');
    }

    public static function instant(?DateTimeInterface $time, int $schoolId): ?string
    {
        return $time === null ? null : CarbonImmutable::instance($time)->setTimezone(SchoolCalendarReaders::for($schoolId)->timezone())->format('M j, Y, g:i A');
    }

    public static function clock(string $date, string $time, int $schoolId): string
    {
        return CarbonImmutable::parse($date.' '.$time, SchoolCalendarReaders::for($schoolId)->timezone())->format('g:i A');
    }
}
