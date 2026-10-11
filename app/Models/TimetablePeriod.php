<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School timetable reference data. Isolation: SchoolTimetableTest. */
class TimetablePeriod extends Model
{
    use BelongsToMasjid;

    protected $table = 'timetable_periods';
    protected $fillable = ['masjid_id', 'period_set_id', 'name', 'starts_at', 'ends_at', 'kind', 'position'];
}
