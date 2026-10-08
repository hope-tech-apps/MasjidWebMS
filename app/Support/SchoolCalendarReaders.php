<?php

namespace App\Support;

/** ON readers share one eager calendar per HTTP request; workers never retain it. */
final class SchoolCalendarReaders
{
    public const ATTRIBUTE = 'school_calendar_terms_authorities';

    /** Model writes and explicit bulk writers discard every tenant binding for this school. */
    public static function forget(int $id): void
    {
        if (! app()->bound('request') || request()->attributes->get('school_calendar_terms_http') !== true) return;
        $calendars = request()->attributes->get(self::ATTRIBUTE, []);
        unset($calendars[$id]);
        request()->attributes->set(self::ATTRIBUTE, $calendars);
    }

    public static function for(int $id): SchoolDateAuthority
    {
        $http = app()->bound('request') && request()->attributes->get('school_calendar_terms_http') === true;
        $scope = (string) (app(TenantContext::class)->get() ?? 'unbound');
        $calendars = $http ? request()->attributes->get(self::ATTRIBUTE, []) : [];
        if (isset($calendars[$id][$scope])) return $calendars[$id][$scope];
        $authority = SchoolDateAuthority::for($id);
        $authority->years()->loadMissing('terms');
        if ($http) {
            $calendars[$id][$scope] = $authority;
            request()->attributes->set(self::ATTRIBUTE, $calendars);
        }
        return $authority;
    }
}
