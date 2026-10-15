<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned intake record. Isolation: SchoolAdmissionsTest. */
class SchoolAdmissionMail extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'kind', 'reminder_batch', 'reenrolment_id', 'application_history_id', 'guardian_contact_id', 'requested_by_user_id', 'guardian_email', 'children', 'subject', 'body', 'status', 'queued_at', 'attempted_at', 'sent_at', 'failed_at', 'exception_class'];
    protected $casts = ['children' => 'array', 'queued_at' => 'datetime', 'attempted_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
}
