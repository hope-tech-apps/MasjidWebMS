<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned intake record. Isolation: SchoolAdmissionsTest. */
class SchoolAdmissionSetting extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'application_form_ids', 'accepted_text', 'wait_listed_text', 'declined_text'];
    protected $casts = ['application_form_ids' => 'array'];
}
