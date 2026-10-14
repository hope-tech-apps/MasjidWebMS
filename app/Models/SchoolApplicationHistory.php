<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned intake record. Isolation: SchoolAdmissionsTest. */
class SchoolApplicationHistory extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'form_response_id', 'status', 'note', 'changed_by_user_id', 'emailed'];
    protected $casts = ['emailed' => 'boolean'];
}
