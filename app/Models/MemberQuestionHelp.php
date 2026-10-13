<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class MemberQuestionHelp extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id','answer_id','option_id'];
    protected $casts = ['answer_id'=>'integer','option_id'=>'integer'];
}
