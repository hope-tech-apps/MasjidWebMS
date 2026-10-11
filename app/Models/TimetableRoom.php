<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School timetable reference data. Isolation: SchoolTimetableTest. */
class TimetableRoom extends Model
{
    use BelongsToMasjid;

    protected $table = 'timetable_rooms';
    protected $fillable = ['masjid_id', 'name', 'name_key', 'capacity', 'active'];
    protected $hidden = ['name_key'];
    protected $casts = ['active' => 'boolean', 'capacity' => 'integer'];
}
