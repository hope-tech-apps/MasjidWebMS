<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a teacher wrote about one child's Arabic on one day.
 *
 * Tenant-scoped by BelongsToMasjid. The subject is exposed as `membership()`
 * because that is the contract `GroupAudience` requires of anything it is asked
 * to constrain to a guardian's own children — renaming it silently breaks every
 * disclosure check that keeps one family from reading another's.
 *
 * Deliberately shaped like `AttendanceRecord`: same columns, same date cast,
 * same relations, same nullable author. Both answer "one fact about one child on
 * one day", and two shapes for one question is how two screens end up
 * disagreeing about what a day is.
 *
 * NO STATUS COLUMN, and that is the difference from attendance. The owner asked
 * for notes on daily progress, not a grade for it — a teacher writes a sentence,
 * not a verdict. Adding a status later is additive; inventing one now would put
 * a judgement on a child's record that nobody asked for.
 */
class ArabicDailyNote extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'group_membership_id',
        'marked_by_user_id',
        'session_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
        ];
    }

    /**
     * The SUBJECT of this note — the student's own participant membership.
     *
     * Named `membership` for GroupAudience, exactly as AttendanceRecord is.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Nullable, and nulled rather than cascaded when a login is retired: the
     * note is a record about a child and outlives whoever typed it.
     */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
