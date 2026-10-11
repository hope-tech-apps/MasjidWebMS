<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class DutyRotaMail extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id','rota_id','family_id','assignment_id','kind','dedupe_key','status','queued_at','sent_at','failed_at','failure_reason'];
    protected $casts = ['queued_at'=>'datetime','sent_at'=>'datetime','failed_at'=>'datetime'];
    
}
