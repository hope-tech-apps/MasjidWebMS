<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class SubjectPieceMark extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'subject_piece_id', 'group_membership_id', 'level', 'comment', 'marked_by_user_id'];
    protected $hidden = ['shared_with_family'];

    public function membership(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    protected function casts(): array
    {
        return ['shared_with_family' => 'boolean', 'level' => 'integer', 'subject_piece_id' => 'integer', 'group_membership_id' => 'integer'];
    }
}
