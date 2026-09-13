<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school year: the dates a school meets between, every 7 days from the first.
 *
 * Tenant-scoped reference data. The meeting weekday is `first_day`'s and is
 * never stored — see the migration. Every calendar question (is this a school
 * day, what is offered on a form, what today is) is answered by
 * App\Support\SchoolCalendar, not here, so the register, the forms and the
 * three reads cannot drift apart. Cross-tenant coverage:
 * tests/Feature/SchoolCalendarTenantIsolationTest.php.
 *
 * `curriculum_weeks.week_no` is still NOT mapped to these dates.
 */
class SchoolYear extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'label',
        'first_day',
        'last_day',
    ];

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
