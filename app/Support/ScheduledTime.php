<?php

namespace App\Support;

use App\Http\Controllers\AdminDashboard\FormStaffCodesController;
use App\Models\Masjid;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Send later": reading and writing a scheduled time (T-002.4).
 *
 * THE CLOCK THE TEACHER TYPES ON IS THE SCHOOL'S, NOT THE BROWSER'S. The screen
 * says "10:00 (America/New_York)" and a teacher travelling, or an office admin in
 * another zone, means the school's ten. So a client sends a wall-clock time with no
 * offset (`2026-10-05T10:00`) and it is read in the school's zone. A time that
 * carries its own offset or `Z` is honoured as stated (an API client that computed
 * an instant), never silently re-read as school time.
 *
 * Stored in the application's own zone (UTC in production), which is what every
 * `now()` comparison in the sweep and in GroupPost::scopePublished() uses. The
 * school's zone is `masjids.timezone`, with an unset `UTC` read as the school
 * default (FormStaffCodesController::timezoneFor), exactly as the register and the
 * points week read it.
 *
 * A wall-clock time that does not exist (the hour skipped when clocks go forward) is
 * moved on by PHP to the first instant that does, and the server answers with the
 * time it actually kept (`local()`), so the screen shows what will happen rather than
 * what was typed. An hour that happens twice is read as its first occurrence.
 */
final class ScheduledTime
{
    /** `2026-10-05T10:00`, `2026-10-05 10:00`, with optional seconds and optional offset. */
    private const SHAPE = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:?\d{2})?$/';

    public static function maxDaysAhead(): int
    {
        return max(1, (int) config('groups.scheduling.max_days_ahead', 30));
    }

    /** The bound school's zone; the application's zone when no school is bound. */
    public static function schoolTimezone(): string
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId !== null ? Masjid::find($masjidId) : null;

        return $masjid !== null
            ? FormStaffCodesController::timezoneFor($masjid)['name']
            : (string) config('app.timezone');
    }

    /**
     * A client's time as an instant in the application's zone, or null when it is
     * not a real date and time (a rolled-over `2026-02-31` is not one).
     */
    public static function parse(mixed $value, ?string $timezone = null): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match(self::SHAPE, trim($value), $m)) {
            return null;
        }

        [, $year, $month, $day, $hour, $minute] = array_map('intval', array_slice($m, 0, 6));
        $second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $offset = $m[7] ?? '';
        $zone = $offset !== '' ? ($offset === 'Z' ? 'UTC' : $offset) : ($timezone ?? self::schoolTimezone());

        try {
            $at = CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, $zone);
        } catch (\Throwable) {
            return null;
        }

        return $at->setTimezone((string) config('app.timezone'));
    }

    /**
     * Why this instant may not be scheduled, or null when it may: it must be in
     * the future and no more than the configured days ahead.
     */
    public static function refusal(CarbonInterface $at): ?string
    {
        $now = CarbonImmutable::now();

        if ($at->lte($now)) {
            return 'Choose a time in the future, or send it now.';
        }

        $days = self::maxDaysAhead();

        if ($at->gt($now->addDays($days))) {
            return "It can be scheduled at most {$days} days ahead.";
        }

        return null;
    }

    /** The time as the school reads its own clock, `Y-m-d\TH:i`, for the edit form. */
    public static function local(?CarbonInterface $at, ?string $timezone = null): ?string
    {
        return $at?->copy()->setTimezone($timezone ?? self::schoolTimezone())->format('Y-m-d\TH:i');
    }
}
