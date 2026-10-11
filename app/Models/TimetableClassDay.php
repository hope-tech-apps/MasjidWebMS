<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School timetable reference data. Isolation: SchoolTimetableTest. */
class TimetableClassDay extends Model
{
    use BelongsToMasjid;

    protected $table = 'timetable_class_days';
    protected $fillable = ['masjid_id', 'school_year_id', 'group_id', 'weekday', 'period_set_id'];
}
