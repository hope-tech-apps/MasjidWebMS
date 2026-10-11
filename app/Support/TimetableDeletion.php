<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** References are kept until the office removes all dated meetings, including history. */
final class TimetableDeletion
{
    /** School requests preserve the dark contract; enabled/global account writes hold every school's references. */
    public static function refuseAccount(int $id): void
    {
        $school = app(TenantContext::class)->get();
        if ($school !== null && ! ClassSubjectMode::timetableEnabled($school)) return;
        self::refuse('user_id', $id);
    }

    public static function refuse(string $column, int $id, ?int $school = null): void
    {
        if ($school !== null && ! ClassSubjectMode::timetableEnabled($school)) return;
        $q=DB::table('timetable_meetings');
        if ($school!==null) $q->where('masjid_id',$school);
        if ($column==='user_id') $q->whereIn('id',DB::table('timetable_meeting_teachers')->where('user_id',$id)->select('meeting_id'));
        else $q->where($column,$id);
        $count=$q->count();
        if ($count) throw ValidationException::withMessages(['timetable'=>["This record has {$count} timetable meeting".($count===1?'':'s').'. Keep this record to preserve the timetable history.']]);
    }
}
