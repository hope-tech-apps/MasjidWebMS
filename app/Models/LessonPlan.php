<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a class is going to cover on one day. One per class per day.
 *
 * DELIBERATELY NO `membership()` RELATION. GroupAudience reaches for that name
 * to decide who may read a record ABOUT A CHILD; a lesson plan is about a room,
 * and giving this model the relation would make "a plan about a child"
 * expressible where today it is refusable. If a future slice needs per-child
 * planning, that is a different table, not this one with a nullable column.
 */
class LessonPlan extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'author_user_id',
        'session_date',
        'title',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
