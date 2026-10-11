<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School timetable reference data. Isolation: SchoolTimetableTest. */
class TimetablePeriodSet extends Model
{
    use BelongsToMasjid;

    protected $table = 'timetable_period_sets';
    protected $fillable = ['masjid_id', 'school_year_id', 'name'];
}
