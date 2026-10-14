<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Last issued grade per student contact, class subject and dated term. Written only at publication. */
class TermGrade extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id','contact_id','class_subject_id','school_term_id','report_card_id','subject','final_letter','percentage','comment','published_at'];
    protected function casts(): array
    {
        return ['percentage'=>'float', 'published_at'=>'datetime'];
    }
}
