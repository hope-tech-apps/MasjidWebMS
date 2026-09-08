<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A piece of work set for a whole class — the thing a score is a score OF.
 *
 * Soft-deleted rather than destroyed: withdrawing an assignment must not erase
 * marks a parent may already have been told about. See the migration docblock
 * for the consequence that follows — no score query may start from
 * `assignment_scores` alone, because these rows can stop resolving underneath it.
 */
class ClassAssignment extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'created_by_user_id',
        'title',
        'points_possible',
        'assigned_on',
    ];

    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'points_possible' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(AssignmentScore::class, 'class_assignment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
