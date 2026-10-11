<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** References are kept until the office removes all dated meetings, including history. */
final class TimetableDeletion
{
    /** Account hints travel on the user row already loaded, including on unbound routes. */
    public static function refuseAccount(\App\Models\User $account): void
    {
        if (! TimetableRetention::instance()->account($account)) return;
        self::refuse('user_id', $account->id);
    }

    public static function refuseRoom(int $id, int $school): void
    {
        if (! TimetableRetention::instance()->school($school)) return;
        $count = DB::table('timetable_meetings')->where('masjid_id', $school)
            ->where(fn ($q) => $q->where('room_id', $id)->orWhere(fn ($q) => $q->whereNull('room_id')
                ->whereIn('group_id', DB::table('timetable_class_rooms')->where('masjid_id', $school)->where('room_id', $id)->select('group_id'))))->count();
        if ($count) throw ValidationException::withMessages(['room'=>["This location has {$count} timetable meetings. Keep it to preserve the timetable."]]);
        // A usual location with no meetings may be cleared; the location's deletion is explicit.
        DB::table('timetable_class_rooms')->where('masjid_id', $school)->where('room_id', $id)->update(['room_id'=>null]);
    }

    public static function refuse(string $column, int $id, ?int $school = null): void
    {
        if ($school !== null && ! TimetableRetention::instance()->school($school)) return;
        $q=DB::table('timetable_meetings');
        if ($school!==null) $q->where('masjid_id',$school);
        if ($column==='user_id') $q->whereIn('id',DB::table('timetable_meeting_teachers')->where('user_id',$id)->select('meeting_id'));
        else $q->where($column,$id);
        $count=$q->count();
        if ($count) throw ValidationException::withMessages(['timetable'=>["This record has {$count} timetable meeting".($count===1?'':'s').'. Keep this record to preserve the timetable history.']]);
    }
}
