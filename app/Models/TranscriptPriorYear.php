<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant isolation: TranscriptsTest. */
class TranscriptPriorYear extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id', 'contact_id', 'school_name', 'year_label', 'grade_label', 'starts_on', 'ends_on', 'courses', 'group_resource_id', 'entered_by_user_id'];
    protected function casts(): array { return ['courses'=>'array','starts_on'=>'date','ends_on'=>'date']; }
}
