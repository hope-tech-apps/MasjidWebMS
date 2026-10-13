<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class MemberQuestionAnswer extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id','contact_id','field_id','field_text','occupation_id','occupation_text','help_text'];
    protected $casts = ['field_id'=>'integer','field_text'=>'encrypted','occupation_id'=>'integer','occupation_text'=>'encrypted','help_text'=>'encrypted'];
}
