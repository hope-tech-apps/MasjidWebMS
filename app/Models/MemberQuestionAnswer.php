<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class MemberQuestionAnswer extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id','contact_id','occupation_id','occupation_text','help_text'];
    protected $casts = ['occupation_id'=>'integer','occupation_text'=>'encrypted','help_text'=>'encrypted'];
}
