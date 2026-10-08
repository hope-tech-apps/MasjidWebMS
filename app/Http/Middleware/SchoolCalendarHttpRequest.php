<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Owns the calendar memo for the HTTP kernel's request, including long-lived workers. */
final class SchoolCalendarHttpRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        $keys = ['school_calendar_terms_decisions', 'school_calendar_terms_rows', 'school_calendar_terms_http', \App\Support\SchoolCalendarReaders::ATTRIBUTE];
        foreach ($keys as $key) $request->attributes->remove($key);
        $request->attributes->set('school_calendar_terms_http', true);
        try {
            return $next($request);
        } finally {
            foreach ($keys as $key) $request->attributes->remove($key);
        }
    }
}
