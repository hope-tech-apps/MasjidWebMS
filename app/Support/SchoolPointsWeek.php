<?php

namespace App\Support;

use App\Http\Controllers\AdminDashboard\FormStaffCodesController;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * The controllers' one way to ask "which points week is this?" for the bound
 * school (T-003.2).
 *
 * Kept apart from PointsWeek so that class stays a pure value object: this one
 * reads the school's clock (masjids.timezone, an unset 'UTC' reading as
 * America/New_York, the same rule the school calendar and the register use) and
 * the request's `?week=`. The staff controller and the family controller both
 * call it, so the two cannot disagree about where a week starts.
 */
final class SchoolPointsWeek
{
    /** The school's IANA zone. `$masjidId` null (unbound, super) falls back like an unset zone. */
    public static function timezone(?int $masjidId): string
    {
        $masjid = $masjidId === null ? null : Masjid::find($masjidId);

        return $masjid
            ? FormStaffCodesController::timezoneFor($masjid)['name']
            : FormStaffCodesController::FALLBACK_TIMEZONE;
    }

    /** The week that holds this moment on the school's clock. */
    public static function current(?int $masjidId): PointsWeek
    {
        return PointsWeek::containing(Date::now(), self::timezone($masjidId));
    }

    /**
     * The week `?week=` names, or null when the parameter is absent or blank.
     *
     * Any day of a week names it (`?week=2026-10-07` is the Sunday-start week that
     * holds the 7th), so a client that steps by adding seven days never has to know
     * where a week starts (or the word `current`, see below). Not a real date is a 422, not a silent fall back to the
     * current week: a parent reading last Friday's report must never be shown this
     * week's without being told. A non-string (`?week[]=`) is refused the same way.
     */
    public static function fromRequest(Request $request, ?int $masjidId): ?PointsWeek
    {
        $raw = $request->query('week');

        if ($raw === null || $raw === '') {
            return null;
        }

        // `current` is the one non-date word: the week in progress on the SCHOOL's
        // clock, for a client (the portal's class screen) that has no way to know
        // what day it is at the school without asking.
        if ($raw === 'current') {
            return self::current($masjidId);
        }

        $week = is_string($raw) ? PointsWeek::startingOn($raw, self::timezone($masjidId)) : null;

        if ($week === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The week must be a date such as 2026-10-04.');
        }

        return $week;
    }

    /**
     * What a client draws the week from: its dates, its neighbours, and whether it
     * is the one in progress (so "next week" can be disabled rather than offered).
     *
     * @return array{start:string,end:string,previous:string,next:string,timezone:string,is_current:bool}
     */
    public static function payload(PointsWeek $week, ?int $masjidId): array
    {
        return $week->toArray() + [
            'is_current' => $week->contains(Date::now()),
        ];
    }
}
