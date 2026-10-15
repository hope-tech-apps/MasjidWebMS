<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class StudentSignInLog extends Model
{
    use BelongsToMasjid;
    protected $table = 'student_sign_in_logs';
    protected $fillable = ['masjid_id','contact_id','username','ip_address','user_agent','outcome','attempted_at'];
    protected function casts(): array { return ['attempted_at'=>'datetime']; }
    public $timestamps = false;
}
