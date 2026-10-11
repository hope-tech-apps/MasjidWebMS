<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class DutyRotaAssignment extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id','rota_id','family_id','duty_on','source','released_at','release_reason','reminder_queued_at','reminder_sent_at'];
    protected $casts = ['released_at'=>'datetime','reminder_queued_at'=>'datetime','reminder_sent_at'=>'datetime'];
    
}
