<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School timetable reference data. Isolation: SchoolTimetableTest. */
class TimetableClassRoom extends Model
{
    use BelongsToMasjid;

    protected $table = 'timetable_class_rooms';
    protected $fillable = ['masjid_id', 'group_id', 'room_id'];
}
