<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class StudentHandIn extends Model
{
    use BelongsToMasjid;
    protected $table = 'student_hand_ins';
    protected $fillable = ['group_id','masjid_id','class_assignment_id','group_membership_id','student_contact_id','version','kind','link','disk','path','original_name','mime_type','size_bytes','late','submitted_at'];
    protected function casts(): array { return ['late'=>'boolean','submitted_at'=>'datetime','version'=>'integer','size_bytes'=>'integer']; }
    public $timestamps = false;
    protected $hidden = ['disk','path'];
}
