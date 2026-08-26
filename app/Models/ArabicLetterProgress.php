<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\Arabic\ArabicCurriculum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's standing on one Arabic drill.
 *
 * The student is their `group_memberships` row, not their contact — the same
 * edge `hifz_entries` and `behavior_awards` name their subject by, so the
 * disclosure rule stays decidable from the row and `GroupAudience` needs no new
 * concept to serve it to a guardian.
 *
 * No soft deletes: a drill is never destroyed, only moved between statuses. The
 * row IS the tracker cell.
 */
class ArabicLetterProgress extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $table = 'arabic_letter_progress';

    protected $fillable = [
        'masjid_id',
        'group_id',
        'group_membership_id',
        'marked_by_user_id',
        'drill_id',
        'status',
        'mastered_at',
    ];

    protected function casts(): array
    {
        return ['mastered_at' => 'datetime'];
    }

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

    public function isMastered(): bool
    {
        return $this->status === ArabicCurriculum::STATUS_MASTERED;
    }

    /**
     * Move to a status, stamping the first mastery only.
     *
     * `mastered_at` is a ledger, not a mirror of `status`: a child who slips
     * back to `learning` and masters the drill again has not mastered it twice,
     * and a parent reading "mastered on 3 March" should not see that date move
     * because a teacher re-marked the card.
     */
    public function moveTo(string $status, ?int $userId = null): void
    {
        $this->status = $status;
        $this->marked_by_user_id = $userId;

        if ($status === ArabicCurriculum::STATUS_MASTERED && $this->mastered_at === null) {
            $this->mastered_at = now();
        }
    }
}
