<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class SubjectPiece extends Model
{
    use BelongsToMasjid;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['masjid_id', 'class_subject_id', 'source', 'title', 'detail', 'grade_label', 'week_no', 'quarter', 'standard_code', 'lesson_plan_id', 'created_by_user_id'];
    protected $hidden = ['shared_with_family'];

    protected function casts(): array
    {
        return ['week_no' => 'integer', 'quarter' => 'integer', 'class_subject_id' => 'integer', 'lesson_plan_id' => 'integer'];
    }
}
