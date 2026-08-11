<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BehaviorSkill — one entry in a tenant's recognition vocabulary (PLAN T-013).
 *
 * "Participation", "Kindness", "Disruption": what a school chooses to notice
 * about a child. Manara ships no fixed list, because the list is a pedagogical
 * decision the organization owns.
 *
 * This model holds NO data about any child — it is the picker. That is why it
 * does not soft-delete (see the create_behavior_skills_table docblock) and why
 * it is NOT gated by App\Support\GroupAudience: reading the vocabulary discloses
 * nothing about a minor, so it takes the ordinary `view contacts` permission
 * like any other piece of CRM configuration.
 *
 * Editing a skill NEVER rewrites history. BehaviorAward snapshots the label,
 * polarity and point value it was given with, so renaming or re-weighting a
 * skill describes what happens next, not what already happened — the same
 * reasoning as the fee-plan snapshots on Registration.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope and the
 * server-derived creating hook. masjid_id stays fillable so system/super code
 * (seeders) can set it while UNBOUND; a bound tenant always overrides it. See
 * .claude/rules/tenant-scoping.md.
 */
class BehaviorSkill extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * Whether this skill recognises something or corrects it.
     *
     * A separate column rather than the sign of `default_points` on purpose: a
     * school is free to run "Disruption, 1 point" as a tally, and a summary
     * still has to be able to say which of those points were encouragement. The
     * two facts are independent, so they are stored independently.
     *
     * PHP constants, NOT a DB enum — the same reasoning as
     * GroupMembership::ROLES: adding a polarity must never require
     * `ALTER TABLE … MODIFY` on a live table (.claude/rules/migrations.md).
     * Validated at the request boundary with Rule::in(...).
     */
    public const POLARITY_POSITIVE = 'positive';
    public const POLARITY_NEGATIVE = 'negative';

    public const POLARITIES = [
        self::POLARITY_POSITIVE,
        self::POLARITY_NEGATIVE,
    ];

    protected $fillable = [
        'masjid_id',
        'label',
        'polarity',
        'default_points',
        'is_active',
    ];

    protected $attributes = [
        'polarity' => self::POLARITY_POSITIVE,
        'default_points' => 1,
    ];

    protected function casts(): array
    {
        return [
            'default_points' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The stored polarity, degraded to POSITIVE when unrecognized — the same
     * defensive read as Group::kind(). An unknown value must not let a skill
     * behave as a polarity nobody chose; positive is the harmless direction,
     * because the failure mode is a summary that under-reports corrections
     * rather than one that invents them.
     */
    public function polarity(): string
    {
        $polarity = $this->attributes['polarity'] ?? null;

        return in_array($polarity, self::POLARITIES, true) ? $polarity : self::POLARITY_POSITIVE;
    }

    public function isPositive(): bool
    {
        return $this->polarity() === self::POLARITY_POSITIVE;
    }

    /**
     * Awards that came from this skill. Provenance only — an award never reads
     * back through here for its VALUES; it carries its own snapshot.
     */
    public function awards(): HasMany
    {
        return $this->hasMany(BehaviorAward::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
