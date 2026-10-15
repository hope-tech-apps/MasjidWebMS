<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class StudentWorkSetting extends Model
{
    use BelongsToMasjid;
    protected $table = 'student_work_settings';
    protected $fillable = ['masjid_id','class_assignment_id','students_hand_in','due_on'];
    protected function casts(): array { return ['students_hand_in'=>'boolean','due_on'=>'date']; }
}
