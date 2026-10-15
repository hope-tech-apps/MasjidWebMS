<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant isolation: TranscriptsTest. */
class TranscriptShareRequest extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id', 'contact_id', 'receiving_masjid_id', 'status', 'sending_school', 'student_name', 'date_of_birth', 'requested_by_user_id', 'requested_at', 'consent_method', 'consent_evidence', 'consented_by_contact_id', 'consented_by_user_id', 'consented_at', 'resolved_by_user_id', 'resolved_at', 'withdrawn_by_contact_id', 'issued_transcript_id'];
    protected function casts(): array { return ['date_of_birth'=>'date:Y-m-d','requested_at'=>'datetime','consented_at'=>'datetime','resolved_at'=>'datetime']; }
}
