<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned intake record. Isolation: SchoolAdmissionsTest. */
class SchoolReenrolmentDeposit extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'reenrolment_id', 'student_contact_id', 'form_response_id'];
    protected $casts = [];
}
