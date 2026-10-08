<?php

namespace App\Support;

use Illuminate\Support\Facades\{DB, Schema};

/** Migration rollback checks the entire feature before issuing any destructive DDL. */
final class SchoolCalendarSchemaGuard
{
    public static function assertUnused(): void
    {
        if ((Schema::hasColumn('report_cards','school_term_id') && DB::table('report_cards')->whereNotNull('school_term_id')->exists())
            || DB::table('school_terms')->exists()
            || DB::table('school_years')->whereNotNull('meeting_weekdays')->orWhereNotNull('term_system')->exists()
            || DB::table('masjids')->whereNotNull('capability_overrides')->get(['capability_overrides'])->contains(
                fn ($row) => (bool) (json_decode($row->capability_overrides, true)['school_calendar_terms'] ?? false)
            )) {
            throw new \RuntimeException('School calendar configuration is in use. Keep the schema and switch compatible schools off before rolling back.');
        }
    }
}
