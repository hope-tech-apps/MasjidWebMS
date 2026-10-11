<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class DutyRota extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id','school_year_id','name','capacity','turns_owed','source_form_id','source_field','reminder_lead','reminder_line','active'];
    protected $casts = ['active'=>'boolean','capacity'=>'integer','turns_owed'=>'integer','reminder_lead'=>'integer'];
    
}
