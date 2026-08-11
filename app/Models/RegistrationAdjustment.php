<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RegistrationAdjustment — one auditable reduction to what a registration is
 * charged: financial aid, a discount, a promo code
 * (docs/t006-registration-billing-design.md).
 *
 * STRICTLY PRE-CHECKOUT and ADMIN-GRANTED, server-side (T-006d) — no
 * self-service discount hole. Applied when computing
 * registrations.adjusted_total_minor BEFORE the Checkout Session exists;
 * Checkout charges exactly that snapshot, and there is no post-hoc money
 * movement, ever. An adjustment that takes the total to 0 (or below Stripe's
 * minimum) branches to the free path — never a $0 session.
 *
 * amount_minor is the UNSIGNED magnitude of the reduction, integer minor
 * units — this table cannot express a surcharge by design.
 *
 * Tenant-scoped via BelongsToMasjid (.claude/rules/tenant-scoping.md).
 */
class RegistrationAdjustment extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * PHP constants over a plain string column, never a DB enum
     * (.claude/rules/migrations.md).
     */
    public const KIND_AID = 'aid';
    public const KIND_DISCOUNT = 'discount';
    public const KIND_CODE = 'code';

    public const KINDS = [
        self::KIND_AID,
        self::KIND_DISCOUNT,
        self::KIND_CODE,
    ];

    protected $fillable = [
        'masjid_id',
        'registration_id',
        'kind',
        'amount_minor',
        'reason',
        'granted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** The admin who granted it — the audit trail's who. */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
