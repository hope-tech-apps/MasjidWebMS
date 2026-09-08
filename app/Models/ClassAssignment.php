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

    /**
     * Marked out of a number of POINTS — a spelling quiz out of 10.
     *
     * `points_possible` is the denominator and an average is a percentage.
     */
    public const SCALE_POINTS = 'points';

    /**
     * Marked on the school's four PERFORMANCE LEVELS — 4 Exceeds down to
     * 1 Needs Support. See App\Support\PerformanceLevel, which holds the labels,
     * the descriptions and the reasoning.
     *
     * `points_possible` is forced to PerformanceLevel::MAX for these so the
     * column stays meaningful, but it is NOT a denominator: a levels average is
     * reported as a mean level (2.8) and a distribution, never as 70%. Turning a
     * standards scale back into a percentage is the exact failure the scale
     * exists to prevent.
     */
    public const SCALE_LEVELS = 'levels';

    public const SCALES = [
        self::SCALE_POINTS,
        self::SCALE_LEVELS,
    ];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'created_by_user_id',
        'title',
        'points_possible',
        'scale',
        'assigned_on',
    ];

    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'points_possible' => 'integer',
        ];
    }

    /** Is this marked on the school's four performance levels? */
    public function usesLevels(): bool
    {
        return $this->scale === self::SCALE_LEVELS;
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
