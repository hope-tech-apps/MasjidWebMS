<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/**
 * A school's own answer to "when does the weekly points report go out?" (T-003.3).
 *
 * At most one row per school (masjid_id is unique); no row, or a null column, means
 * the default (App\Support\PointsReportSchedule). Only a SuperAdmin writes it, through
 * PointsReportScheduleController, and only App\Support\PointsReportSchedule reads it,
 * so the two halves of the rule (a weekday, a time) are validated in one place.
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
    ];

    protected function casts(): array
    {
        return [
            'report_weekday' => 'integer',
        ];
    }
}
