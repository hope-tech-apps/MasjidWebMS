<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class MemberQuestionSetting extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id','kind','active','prompt'];
    protected $casts = ['active'=>'boolean'];
}
