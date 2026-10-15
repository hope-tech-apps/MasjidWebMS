<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant isolation: TranscriptsTest. */
class TranscriptCourse extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id', 'class_subject_id', 'credit_hundredths', 'level', 'on_transcript'];
    protected function casts(): array { return ['credit_hundredths'=>'integer', 'on_transcript'=>'boolean']; }
}
