<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much one piece of work of one type counts toward a class's average.
 *
 * A class has ALL of ClassAssignment::TYPES here or none of them; none means the
 * class is unweighted and every average is the arithmetic it was before weights
 * existed. Relative, never required to sum to 100. See the migration for why, and
 * App\Support\GradeRecord for how they are used.
 */
class ClassGradeWeight extends Model
{
    use BelongsToMasjid;

    /** The most one piece of work can count for, and the most a per-work override may say. */
    public const MAX = 100;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'assignment_type',
        'weight',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * A class's weights as `[type => weight]`, or `[]` when it is unweighted.
     *
     * @return array<string,int>
     */
    public static function forGroup(int $groupId): array
    {
        return static::query()
            ->where('group_id', $groupId)
            ->pluck('weight', 'assignment_type')
            ->map(fn ($w) => (int) $w)
            ->all();
    }
}
