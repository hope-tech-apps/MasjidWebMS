<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One child's report for one quarter — a progress report or a report card.
 *
 * See the migration for why the two are one table, why publication is what makes
 * a card visible to a family, and why the attendance figures are snapshotted
 * rather than joined.
 */
class ReportCard extends Model
{
    use HasFactory, BelongsToMasjid;

    /** Mid-quarter. A check-in: how is this child going, with time to act. */
    public const TYPE_PROGRESS = 'progress';

    /** End of quarter. The document a family keeps. */
    public const TYPE_REPORT_CARD = 'report_card';

    public const TYPES = [
        self::TYPE_PROGRESS,
        self::TYPE_REPORT_CARD,
    ];

    /** Al-Razi runs four quarters. */
    public const TERMS = [1, 2, 3, 4];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'group_membership_id',
        'type',
        'school_year',
        'term',
        'grade_label',
        'teacher_comment',
        'days_present',
        'days_absent',
        'days_late',
        'created_by_user_id',
    ];

    /**
     * NOTE what is absent: `published_at` and `published_by_user_id`.
     *
     * Publication is the moment a document becomes visible to a family, so it
     * must not be settable as a side effect of saving a draft's comment. It is
     * written by `publish()` alone — the same reasoning that keeps the four
     * `login_*` columns off Contact::$fillable.
     */
    protected function casts(): array
    {
        return [
            'term' => 'integer',
            'days_present' => 'integer',
            'days_absent' => 'integer',
            'days_late' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function marks(): HasMany
    {
        return $this->hasMany(ReportCardMark::class);
    }

    /**
     * The SUBJECT — the child's own participant membership.
     *
     * GroupAudience reaches through relations by name; this one matches
     * AssignmentScore::membership() and AttendanceRecord's for the same reason.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Only a published report is a thing a family may open. */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * A human label for the period — "Quarter 2, 2026-2027".
     *
     * Built here rather than in a template so the teacher's screen, the parent's
     * screen and the eventual PDF cannot word it three different ways.
     */
    public function periodLabel(): string
    {
        return 'Quarter ' . $this->term . ', ' . $this->school_year;
    }

    public function typeLabel(): string
    {
        return $this->type === self::TYPE_PROGRESS ? 'Progress Report' : 'Report Card';
    }
}
