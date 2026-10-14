<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned intake record. Isolation: SchoolAdmissionsTest. */
class SchoolReenrolmentAnswer extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'reenrolment_id', 'student_contact_id', 'answer', 'next_grade', 'answered_by_contact_id', 'answered_by_user_id', 'recorded_by_office', 'answered_at'];
    protected $casts = ['recorded_by_office' => 'boolean', 'answered_at' => 'datetime'];
}
