<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FeePlan — how one offering may be paid for: free, one_time, N installments,
 * or open-ended recurring (docs/t006-registration-billing-design.md).
 *
 * IMMUTABLE ONCE REFERENCED: a plan any registration points at is never edited
 * — deactivate-and-replace, so in-flight registrations keep their snapshot.
 * T-006d's controller exposes create/deactivate only; the registrations table
 * backs it with a RESTRICT FK. All money integer minor units.
 *
 * Tenant-scoped via BelongsToMasjid (.claude/rules/tenant-scoping.md).
 *
 * There is deliberately NO kind() degrade helper here (contrast Offering::kind()
 * / Group::kind()): those kinds are presentation, this one decides MONEY
 * behaviour. Guessing free for an unknown value would waive a fee, guessing
 * one_time could charge the wrong shape — an unrecognized kind must fail
 * validation loudly, never degrade silently. Check explicitly (isFree(), ...).
 */
class FeePlan extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * PHP constants over a plain string column, never a DB enum
     * (.claude/rules/migrations.md).
     */
    public const KIND_FREE = 'free';
    public const KIND_ONE_TIME = 'one_time';
    public const KIND_INSTALLMENT = 'installment';
    public const KIND_RECURRING = 'recurring';

    public const KINDS = [
        self::KIND_FREE,
        self::KIND_ONE_TIME,
        self::KIND_INSTALLMENT,
        self::KIND_RECURRING,
    ];

    /**
     * Stripe's own subscription interval vocabulary — billing_interval is only
     * meaningful (and only validated to this set) for installment/recurring.
     */
    public const INTERVAL_DAY = 'day';
    public const INTERVAL_WEEK = 'week';
    public const INTERVAL_MONTH = 'month';
    public const INTERVAL_YEAR = 'year';

    public const BILLING_INTERVALS = [
        self::INTERVAL_DAY,
        self::INTERVAL_WEEK,
        self::INTERVAL_MONTH,
        self::INTERVAL_YEAR,
    ];

    protected $fillable = [
        'masjid_id',
        'offering_id',
        'kind',
        'amount_minor',
        'currency',
        'billing_interval',
        'installment_count',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'installment_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Explicit equality — see the class docblock on why there is no degrade. */
    public function isFree(): bool
    {
        return $this->kind === self::KIND_FREE;
    }

    /** Plans that carry a Stripe subscription leg (installment or recurring). */
    public function isSubscriptionBased(): bool
    {
        return in_array($this->kind, [self::KIND_INSTALLMENT, self::KIND_RECURRING], true);
    }
}
