<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant isolation: TranscriptsTest. */
class TranscriptFinal extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id', 'contact_id', 'class_subject_id', 'school_year_id', 'letter', 'reason', 'overridden_by_user_id', 'overridden_at'];
    protected function casts(): array { return ['overridden_at'=>'datetime']; }
}
