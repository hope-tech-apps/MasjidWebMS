<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Models\Concerns\InvalidatesSchoolCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dated reference data; positions are stable. Isolation: SchoolCalendarTermsTest. */
class SchoolTerm extends Model
{
    use BelongsToMasjid;
    use InvalidatesSchoolCalendar;

    protected $fillable = ['masjid_id', 'school_year_id', 'name', 'starts_on', 'ends_on', 'position'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'position' => 'integer'];

    public function year(): BelongsTo { return $this->belongsTo(SchoolYear::class, 'school_year_id'); }
}
