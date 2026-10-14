<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned intake record. Isolation: SchoolAdmissionsTest. */
class SchoolReenrolment extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'school_year_id', 'opens_on', 'closes_on', 'deposit_form_id'];
    protected $casts = ['opens_on' => 'date', 'closes_on' => 'date'];
    protected static function booted(): void
    {
        static::created(fn (self $row) => \App\Support\SchoolAdmissionsMode::rememberRecords((int) $row->masjid_id));
    }
}
