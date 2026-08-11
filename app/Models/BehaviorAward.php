<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BehaviorAward — one recognition given to ONE student (PLAN T-013).
 *
 * A CHILD'S RECORD IS PRIVATE. An award is disclosed to the group's leaders, to
 * the student themselves, and to that student's own guardians — never to
 * another guardian in the same group, and never as a class-wide ranking. That
 * is a design constraint of this module, not a preference: public point tallies
 * are the single most-documented harm of the product this replaces. WHO may
 * read an award is decided per request by App\Support\GroupAudience — never
 * here, never in a controller — and is applied as a query constraint so a
 * forbidden row cannot surface in a listing. See .claude/rules/groups.md.
 *
 * THE VALUES ARE A SNAPSHOT. `skill_label`, `skill_polarity` and `points` are
 * copied from the BehaviorSkill at award time and never re-read from it. The
 * same reasoning as Registration's fee-plan snapshots: re-weighting a skill
 * says what should happen next term, not that a child's October record was
 * wrong. `behaviorSkill()` exists for provenance and may legitimately be null.
 *
 * REVOCATION IS THE SOFT DELETE. `deleted_at` is the revocation clock, so a
 * revoked award drops out of every listing and every total through the ordinary
 * SoftDeletes scope — one mechanism, no parallel `is_revoked` flag that a query
 * could forget. `revoked_by_user_id` records who made the correction.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope and the
 * server-derived creating hook. masjid_id stays fillable so system/super code
 * (seeders, the purge command) can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 */
class BehaviorAward extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'group_membership_id',
        'behavior_skill_id',
        'awarded_by_user_id',
        'skill_label',
        'skill_polarity',
        'points',
        'note',
        'awarded_at',
        'revoked_by_user_id',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'awarded_at' => 'datetime',
            'retained_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $award): void {
            // An award records WHEN the recognition happened, which is not
            // always when it was typed in. Defaulted in the model rather than
            // the controller so every caller — imports, seeders — gets a real
            // timestamp instead of a null the listings would have to sort around.
            if ($award->awarded_at === null) {
                $award->awarded_at = now();
            }

            // RETENTION IS A DEFAULT, NOT A HOPE — the same stance as GroupPost
            // and GroupThread, and for the same reason: this is a behaviour
            // record about a child, so "keep forever unless somebody remembers
            // to say otherwise" is the wrong default. A caller that sets
            // retained_until itself is respected; config 0 leaves the column
            // null and the row outside the purge sweep entirely.
            if ($award->retained_until !== null) {
                return;
            }

            $days = (int) config('groups.behavior.retention_days', 0);

            if ($days > 0) {
                $award->retained_until = now()->addDays($days)->toDateString();
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * THE STUDENT: their own participant membership in the group. The audience
     * rule is decided from this row (its contact is the student; guardian edges
     * in the group name that contact as their ward), which is why an award
     * cannot outlive it — see the migration docblock.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    /** Provenance only; null once the vocabulary entry is deleted. */
    public function behaviorSkill(): BelongsTo
    {
        return $this->belongsTo(BehaviorSkill::class);
    }

    /** The admin account that gave it; null once that account is deleted. */
    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by_user_id');
    }

    /** The admin account that revoked it; null on a live award. */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * The stored polarity, degraded to POSITIVE when unrecognized — the same
     * defensive read as BehaviorSkill::polarity(), applied to the SNAPSHOT so a
     * summary never has to trust an unreadable value.
     */
    public function polarity(): string
    {
        $polarity = $this->attributes['skill_polarity'] ?? null;

        return in_array($polarity, BehaviorSkill::POLARITIES, true)
            ? $polarity
            : BehaviorSkill::POLARITY_POSITIVE;
    }

    public function isRevoked(): bool
    {
        return $this->trashed();
    }

    /**
     * Awards whose retention window has closed, revoked ones included — the
     * sweep `groups:purge-feed` runs, identical in shape to
     * GroupPost::scopeDueForPurge. A null retained_until is never due: it means
     * nobody has set a window, not "purge me now".
     */
    public function scopeDueForPurge(Builder $query, $asOf = null): Builder
    {
        return $query->withTrashed()
            ->whereNotNull('retained_until')
            ->whereDate('retained_until', '<=', $asOf ?? now()->toDateString());
    }

    /**
     * Narrow a listing to a closed date range over `awarded_at` — the column
     * that says when the recognition HAPPENED, never created_at. Either end may
     * be omitted; whereDate keeps a bare `2026-03-01` inclusive of that whole
     * day instead of cutting it off at midnight.
     */
    public function scopeAwardedBetween(Builder $query, $from = null, $to = null): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('awarded_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('awarded_at', '<=', $to));
    }

    /**
     * Destroy this award for good.
     *
     * forceDelete() is enough here, as it is for GroupThread and unlike
     * GroupPost: an award is rows only — it carries nothing on disk — so there
     * are no bytes a cascade could orphan.
     */
    public function purge(): void
    {
        $this->forceDelete();
    }
}
