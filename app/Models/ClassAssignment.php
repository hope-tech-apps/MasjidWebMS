<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\SubjectKey;
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
    use \App\Models\Concerns\HasClassSubjectWork;

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

    /**
     * Marked Excellent / Good / Needs work — see App\Support\SimpleMark.
     *
     * Only where a SuperAdmin switched on `simple_marking` for the organisation
     * (App\Support\SchoolSettings::gradingScales decides what a teacher may
     * choose). Stored 3/2/1 with `points_possible` forced to 3, and like a level
     * it is never a denominator: no percentage and no mean is made from it.
     */
    public const SCALE_SIMPLE = 'simple';

    /** Every scale a stored row may carry. What a teacher may CHOOSE is per organisation. */
    public const SCALES = [
        self::SCALE_POINTS,
        self::SCALE_LEVELS,
        self::SCALE_SIMPLE,
    ];

    /*
     * What KIND of work it is (T-001.2), for the class's weights. PHP constants,
     * never a DB enum: a sixth type is a write, not an ALTER TABLE. NULL is
     * "no type", which is every piece of work set before types existed.
     */
    public const TYPE_QUIZ = 'quiz';
    public const TYPE_HOMEWORK = 'homework';
    public const TYPE_TEST = 'test';
    public const TYPE_CLASSWORK = 'classwork';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_TEST,
        self::TYPE_QUIZ,
        self::TYPE_HOMEWORK,
        self::TYPE_CLASSWORK,
        self::TYPE_OTHER,
    ];

    /** The words a screen prints; served with the payload so no client re-spells them. */
    public const TYPE_LABELS = [
        self::TYPE_TEST => 'Test',
        self::TYPE_QUIZ => 'Quiz',
        self::TYPE_HOMEWORK => 'Homework',
        self::TYPE_CLASSWORK => 'Classwork',
        self::TYPE_OTHER => 'Other',
    ];

    protected $fillable = [
        'masjid_id',
        'class_subject_id',
        'group_id',
        'created_by_user_id',
        'title',
        'subject',
        'type',
        'weight',
        'standard_code',
        'curriculum_focus',
        'curriculum_week_no',
        'points_possible',
        'scale',
        'assigned_on',
    ];

    /**
     * `subject_key` is plumbing derived from `subject` on every save, and is not
     * something a screen reads or a client sends.
     */
    protected $hidden = [
        'subject_key',
        'class_subject_id',
        'class_subject_link_checked_at',
    ];

    protected static function booted(): void
    {
        // Here, not in the controller, so every writer (the teacher API, a
        // console session, a seeder) lands on the same key. A subject that is
        // only whitespace is no subject: it becomes NULL and keys as ''.
        static::saving(function (ClassAssignment $work): void {
            $work->subject = SubjectKey::clean($work->subject);
            $work->subject_key = SubjectKey::for($work->subject);
        });
    }

    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'points_possible' => 'integer',
            'weight' => 'integer',
            'curriculum_week_no' => 'integer',
            'class_subject_id' => 'integer',
            'class_subject_link_checked_at' => 'datetime',
        ];
    }

    /** Is this marked on the school's four performance levels? */
    public function usesLevels(): bool
    {
        return $this->scale === self::SCALE_LEVELS;
    }

    /** Is this marked Excellent / Good / Needs work? */
    public function usesSimpleMarks(): bool
    {
        return $this->scale === self::SCALE_SIMPLE;
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
