<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School timetable reference data. Isolation: SchoolTimetableTest. */
class TimetableMeeting extends Model
{
    use BelongsToMasjid;

    protected $table = 'timetable_meetings';
    protected $fillable = ['masjid_id', 'school_year_id', 'group_id', 'weekday', 'period_id', 'kind', 'class_subject_id', 'activity_name', 'room_id', 'effective_from', 'effective_until'];
}
