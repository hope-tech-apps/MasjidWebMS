<?php

namespace App\Models\Concerns;

use App\Support\SchoolCalendarReaders;
use Illuminate\Database\Eloquent\Model;

/** Model events cover all calendar CRUD, including relation creates and switch initialization. */
trait InvalidatesSchoolCalendar
{
    protected static function bootInvalidatesSchoolCalendar(): void
    {
        $invalidate = static function (Model $model): void {
            SchoolCalendarReaders::forget((int) $model->masjid_id);
            $previous = $model->getRawOriginal('masjid_id');
            if ($previous !== null && (int) $previous !== (int) $model->masjid_id) {
                SchoolCalendarReaders::forget((int) $previous);
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
