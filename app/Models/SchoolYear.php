<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Models\Concerns\InvalidatesSchoolCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-scoped school dates. The legacy weekday remains the first day's weekday.
 * New configuration is read only by SchoolDateAuthority while the switch is ON;
 * raw serialization hides it. No curriculum week number is derived from dates.
 * Cross-tenant coverage: SchoolCalendarTenantIsolationTest and SchoolCalendarTermsTest.
 */
class SchoolYear extends Model
{
    use BelongsToMasjid;
    use InvalidatesSchoolCalendar;

    protected $fillable = [
        'masjid_id',
        'label',
        'first_day',
        'last_day',
        'meeting_weekdays',
        'term_system',
    ];

    protected $casts = ['meeting_weekdays' => 'array'];
    protected $hidden = ['meeting_weekdays', 'term_system'];

    public function terms(): HasMany
    {
        return $this->hasMany(SchoolTerm::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            // Stored by the `date` cast as 'Y-m-d 00:00:00' on SQLite: compare
            // with whereDate() or toDateString(), never a raw BETWEEN
            // (LessonPlanController::index says why).
            'first_day' => 'date',
            'last_day' => 'date',
        ];
    }

    public function closures(): HasMany
    {
        return $this->hasMany(SchoolClosure::class);
    }

    /** 0 = Sunday … 6 = Saturday: the weekday of the first day. */
    public function meetingWeekday(): int
    {
        return (int) $this->first_day->dayOfWeek;
    }
}
