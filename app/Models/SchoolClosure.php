<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meeting day with no school, and the reason the office gave.
 *
 * Tenant-scoped. Always inside its year and on the year's weekday. Cross-tenant
 * coverage: tests/Feature/SchoolCalendarTenantIsolationTest.php.
 */
class SchoolClosure extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'school_year_id',
        'closed_on',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'closed_on' => 'date',
        ];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class, 'school_year_id');
    }
}
