<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School timetable reference data. Isolation: SchoolTimetableTest. */
class TimetableMeetingTeacher extends Model
{
    use BelongsToMasjid;

    protected $table = 'timetable_meeting_teachers';
    protected $fillable = ['masjid_id', 'meeting_id', 'user_id'];
}
