<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One register cell: this student, on this day, marked this way.
 *
 * Tenant-scoped by BelongsToMasjid. The subject is exposed as `membership()`
 * because that is the contract GroupAudience requires of anything it is asked to
 * disclose — see the create_attendance_records_table docblock.
 */
class AttendanceRecord extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * The four marks a register can carry.
     *
     * PHP constants, not a DB enum, for the reason recorded in the migration:
     * a fifth mark must be a write, never an ALTER TABLE on a live table. The
     * allowed set is validated at the request boundary.
     *
     * PRESENT and ABSENT are the register. LATE is still present — it is counted
     * as attendance, and exists so a teacher can correct a mark rather than
     * choosing between a wrong `absent` and a dishonest `present`. EXCUSED is an
     * absence the office has accepted; it is NOT present, and is separated so a
     * school can report "absences" without punishing a family that called ahead.
     */
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_LATE = 'late';
    public const STATUS_EXCUSED = 'excused';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
        self::STATUS_EXCUSED,
    ];

    /**
     * The marks that count as the child having been in the room.
     *
     * One definition, so a summary, a report and a parent-facing count can never
     * disagree about whether `late` is attendance. It is.
     */
    public const PRESENT_STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LATE,
    ];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'group_membership_id',
        'marked_by_user_id',
        'session_date',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
        ];
    }

    /**
     * The SUBJECT of this record — the student's own participant membership.
     *
     * GroupAudience reaches through this relation by name; renaming it silently
     * breaks every disclosure check that constrains a register to the caller's
     * own children.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }

    public function wasPresent(): bool
    {
        return in_array($this->status, self::PRESENT_STATUSES, true);
    }
}
