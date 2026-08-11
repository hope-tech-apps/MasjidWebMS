<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Registration — one household's signup for one Offering
 * (docs/t006-registration-billing-design.md).
 *
 * TWO INDEPENDENT STATE MACHINES: `status` is the SEAT, `payment_status` is
 * the MONEY. A failed installment moves payment_status to past_due and never
 * touches status — un-enrolling a mid-semester child is an explicit admin
 * action. Note the doc-exact spellings differ on purpose: seat
 * STATUS_CANCELLED ("cancelled") vs money PAYMENT_CANCELED ("canceled",
 * Stripe's spelling). Both are PHP constant sets over plain string columns.
 *
 * Money is integer minor units. list_total_minor / adjusted_total_minor are
 * snapshots taken at creation from the immutable fee plan minus admin
 * adjustments; adjusted_total_minor IS the charged amount, always.
 *
 * `uuid` is the opaque public handle (donation pattern). The public endpoints
 * run UNBOUND (no tenant middleware), where the BelongsToMasjid global scope
 * adds no filter — so uuid lookups MUST go through findByUuidForMasjid(),
 * which filters by masjid_id explicitly. Never resolve a registration from a
 * client-supplied uuid alone on an unbound path.
 *
 * State is advanced by webhooks only (T-006c/e), except the declared free-path
 * carve-out — this model holds no transition logic itself.
 */
class Registration extends Model
{
    use HasFactory, BelongsToMasjid;

    // ----------------------------------------------------- seat state machine

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_WAITLISTED,
        self::STATUS_CANCELLED,
    ];

    // ---------------------------------------------------- money state machine

    public const PAYMENT_NONE = 'none';
    public const PAYMENT_AWAITING = 'awaiting';
    public const PAYMENT_ACTIVE = 'active';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_PAST_DUE = 'past_due';
    public const PAYMENT_CANCELED = 'canceled';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_NONE,
        self::PAYMENT_AWAITING,
        self::PAYMENT_ACTIVE,
        self::PAYMENT_PAID,
        self::PAYMENT_PAST_DUE,
        self::PAYMENT_CANCELED,
    ];

    protected $fillable = [
        'uuid',
        'masjid_id',
        'offering_id',
        'fee_plan_id',
        'contact_id',
        'form_response_id',
        'status',
        'payment_status',
        'list_total_minor',
        'adjusted_total_minor',
        'stripe_checkout_session_id',
        'stripe_subscription_id',
        'stripe_subscription_schedule_id',
        'checkout_expires_at',
        'idempotency_key',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'payment_status' => self::PAYMENT_NONE,
    ];

    protected function casts(): array
    {
        return [
            'list_total_minor' => 'integer',
            'adjusted_total_minor' => 'integer',
            'checkout_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Opaque public UUID, assigned on create when the caller didn't supply
        // one — kept separate from the auto-increment id (donation pattern).
        static::creating(function (Registration $registration): void {
            if (empty($registration->uuid)) {
                $registration->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * THE uuid lookup. Filters by masjid_id explicitly because the public
     * paths run unbound, where the tenant scope adds no constraint — a uuid
     * belonging to another organization is a miss (null → 404), never a hit.
     * Harmlessly redundant when a tenant is bound.
     */
    public static function findByUuidForMasjid(string $uuid, int $masjidId): ?self
    {
        return static::query()
            ->where('uuid', $uuid)
            ->where('masjid_id', $masjidId)
            ->first();
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class);
    }

    /** The payer/guardian who submitted the registration. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** The intake answers, stored as a normal form_response — never duplicated. */
    public function formResponse(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class);
    }

    /** Who this registration is FOR (one row per child/participant). */
    public function registrants(): HasMany
    {
        return $this->hasMany(Registrant::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(RegistrationAdjustment::class);
    }

    /** The per-charge ledger: one row per Stripe charge, N for installments. */
    public function payments(): HasMany
    {
        return $this->hasMany(RegistrationPayment::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
