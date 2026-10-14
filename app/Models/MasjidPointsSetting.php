<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/**
 * A school's own answer to "when does the weekly points report go out?" (T-003.3), and (T-003.4)
 * to "how do its points turn into Manara Bucks?" (App\Support\ClassStoreSettings).
 * The office's absence-email clock and the scheduler's last claimed date also live here.
 *
 * At most one row per school (masjid_id is unique); no row, or a null column, means
 * the relevant reader's default. A SuperAdmin writes points/report settings; the office writes
 * only the absence-email time through OfficeAttendanceController. The scheduler alone stamps
 * attendance_absence_last_date before selecting the day's notices.
 * Nothing on it is about a person.
 *
 * Tenant-scoped (BelongsToMasjid); cross-tenant test in
 * tests/Feature/WeeklyPointsReportTest.php.
 */
class MasjidPointsSetting extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'report_weekday',
        'report_time',
        'attendance_absence_send_time',
        // Manara Bucks (T-003.4): how points turn into bucks. Read only through
        // App\Support\ClassStoreSettings, written only by a SuperAdmin.
        'points_per_buck',
        'paper_bucks_enabled',
        'bucks_from',
        'bucks_swept_at',
    ];

    protected function casts(): array
    {
        return [
            'report_weekday' => 'integer',
            'attendance_absence_last_date' => 'date',
            'points_per_buck' => 'integer',
            'paper_bucks_enabled' => 'boolean',
            'bucks_from' => 'date',
            'bucks_swept_at' => 'datetime',
        ];
    }
}
