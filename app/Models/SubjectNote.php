<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class SubjectNote extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'class_subject_id', 'group_membership_id', 'body', 'author_user_id'];
    protected $hidden = ['shared_with_family'];

    protected function casts(): array
    {
        return ['class_subject_id' => 'integer', 'group_membership_id' => 'integer'];
    }
}
