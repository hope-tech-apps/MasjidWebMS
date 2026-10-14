<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Frozen absent classes per guardian/student/date. Isolation: AttendanceAbsenceEmailTest. */
class AttendanceAbsenceMailChild extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'mail_id', 'guardian_contact_id', 'student_contact_id', 'attendance_date', 'child_name', 'classes'];
    protected $casts = ['attendance_date' => 'date', 'classes' => 'array'];
}
