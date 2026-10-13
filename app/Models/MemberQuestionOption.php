<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class MemberQuestionOption extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id','question','label','position','active','is_other'];
    protected $casts = ['active'=>'boolean','is_other'=>'boolean','position'=>'integer'];
}
