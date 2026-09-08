<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One child's mark on one piece of work.
 *
 * Not-yet-scored is the ABSENCE of a row, never a status and never a 0 — see the
 * migration docblock. No soft deletes: a cell is corrected in place.
 */
class AssignmentScore extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * The three things a cell can say.
     *
     * PHP constants, not a DB enum, so a fourth is a write rather than an
     * ALTER TABLE on a live table.
     */
    public const STATUS_SCORED = 'scored';
    public const STATUS_MISSING = 'missing';
    public const STATUS_EXCUSED = 'excused';

    public const STATUSES = [
        self::STATUS_SCORED,
        self::STATUS_MISSING,
        self::STATUS_EXCUSED,
    ];

    /**
     * THE DENOMINATOR, defined once.
     *
     * `missing` counts — zero in the numerator, full weight in the denominator,
     * because work not handed in is work not done. `excused` counts in NEITHER:
     * the office accepted the absence, and dividing by it would punish a family
     * that did the right thing. One definition here, the way
     * AttendanceRecord::PRESENT_STATUSES gives "late is attendance" one
     * definition, so a summary on one screen can never disagree with a summary
     * on another.
     */
    public const COUNTS_TOWARD_AVERAGE = [
        self::STATUS_SCORED,
        self::STATUS_MISSING,
    ];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'class_assignment_id',
        'group_membership_id',
        'scored_by_user_id',
        'status',
        'points_earned',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'points_earned' => 'decimal:2',
        ];
    }

    /**
     * The SUBJECT — the student's own participant membership.
     *
     * GroupAudience reaches through this relation BY NAME; renaming it silently
     * breaks every disclosure check that constrains marks to a caller's own
     * children.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClassAssignment::class, 'class_assignment_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function scoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scored_by_user_id');
    }

    /** Does this row contribute to an average at all? */
    public function countsTowardAverage(): bool
    {
        return in_array($this->status, self::COUNTS_TOWARD_AVERAGE, true);
    }
}
