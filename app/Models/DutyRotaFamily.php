<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class DutyRotaFamily extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id','rota_id','identity_hash','name','email','phone','submitted_at','registration_count','withdrawn','unplaced_picks','notices','token_hash','issuance_nonce','token_expires_at','link_sent_at'];
    protected $casts = ['submitted_at'=>'datetime','registration_count'=>'integer','withdrawn'=>'boolean','unplaced_picks'=>'array','notices'=>'array','token_expires_at'=>'datetime','link_sent_at'=>'datetime'];
    protected $hidden = ['identity_hash','token_hash','issuance_nonce','token_expires_at'];
}
