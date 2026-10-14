<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Guardian/day claim and outcome. Isolation: AttendanceAbsenceEmailTest. */
class AttendanceAbsenceMail extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'guardian_contact_id', 'guardian_email', 'attendance_date', 'status', 'queued_at', 'attempted_at', 'sent_at', 'failed_at', 'exception_class'];
    protected $casts = ['attendance_date' => 'date', 'queued_at' => 'datetime', 'attempted_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];

    public function children(): HasMany { return $this->hasMany(AttendanceAbsenceMailChild::class, 'mail_id'); }
}
