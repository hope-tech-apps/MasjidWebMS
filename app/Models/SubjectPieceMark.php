<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class SubjectPieceMark extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'subject_piece_id', 'group_membership_id', 'level', 'comment', 'marked_by_user_id'];
    protected $hidden = ['shared_with_family'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'subject_piece_id' => 'integer', 'group_membership_id' => 'integer'];
    }
}
