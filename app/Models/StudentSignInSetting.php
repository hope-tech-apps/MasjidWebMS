<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class StudentSignInSetting extends Model
{
    use BelongsToMasjid;
    protected $table = 'student_sign_in_settings';
    protected $fillable = ['masjid_id','from_grade'];
    protected function casts(): array { return []; }
}
