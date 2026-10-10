<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class SubjectNote extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'class_subject_id', 'group_membership_id', 'body', 'author_user_id'];
    protected $hidden = ['shared_with_family'];

    public function membership(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    protected function casts(): array
    {
        return ['shared_with_family' => 'boolean', 'class_subject_id' => 'integer', 'group_membership_id' => 'integer'];
    }

    /**
     * What a teacher's screen loaded, as one string: the words and whether the family can read them.
     * An edit made against an older one is refused, so a stale tab cannot share a note again after
     * someone un-shared it (the row's own timestamp is too coarse: two saves can fall in one second).
     */
    public static function versionOf(?string $body, mixed $shared): string
    {
        return hash('sha256', (string) $body."\n".((bool) $shared ? '1' : '0'));
    }
}
