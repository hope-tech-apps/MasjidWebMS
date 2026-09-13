<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
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

    // -------------------------------------------------------- which door (T-041i)

    /**
     * WHICH DOOR this registration came through — and nothing else. It is not a
     * third state machine: `source` never changes after the row is written, and
     * no read path anywhere may branch on it to decide what somebody owes or
     * whether a seat is held. Both doors run the same intake transaction, take a
     * seat under the same lock, and snapshot the same price.
     *
     *  - PUBLIC — the unauthenticated /api/v1 endpoints. Every row that existed
     *    before T-041i, which is what the column default records.
     *  - STAFF  — an administrator recorded it on the roster screen: a family
     *    paying at the desk, a phone call, a household with no email address.
     *    `entered_by_user_id` names them.
     *
     * PHP constants over a plain string column, never a DB enum — same reasoning
     * as STATUSES above and `MealOrder::SOURCE_ONLINE`/`SOURCE_STAFF`, the
     * precedent this follows.
     */
    public const SOURCE_PUBLIC = 'public';
    public const SOURCE_STAFF = 'staff';

    public const SOURCES = [
        self::SOURCE_PUBLIC,
        self::SOURCE_STAFF,
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
        // `source`, `entered_by_user_id` and `staff_note` are DELIBERATELY
        // ABSENT, for the same reason GroupMembership's provenance columns are:
        // they record on whose authority a registration — and therefore the
        // guardian edge it materialises — exists, so no request body may set
        // them and no mass assignment may carry them in from a payload. The one
        // writer that legitimately sets them is `enteredByStaff()` below, which
        // is also what keeps "who may enter a registration by hand" answerable
        // by finding its callers.
    ];

    /**
     * The column defaults are stated HERE as well as in the schema.
     *
     * A row created without naming `source` read NULL in memory until it was
     * refreshed while the same row read 'public' from the database — the exact
     * split GroupMembership's `$attributes` docblock records for `provenance`.
     * Any writer handing an unrefreshed model to a read path would otherwise get
     * a different answer from the one the row actually has.
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'payment_status' => self::PAYMENT_NONE,
        'source' => self::SOURCE_PUBLIC,
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

    /**
     * The reaper's sweep set (T-006f): seats still HELD for a checkout that
     * closed on or before $deadline, where $deadline is `now minus the grace
     * margin` — never a bare `now()`.
     *
     * Every clause is load-bearing:
     *  - `pending` only, because that is the only state that holds a seat AND
     *    can still lose it (confirmed keeps its seat, waitlisted never had one,
     *    cancelled already gave it back). It is also releaseSeat()'s exact
     *    precondition, so nothing this scope returns can be un-releasable.
     *  - `awaiting` only. `none` is the free path (no Stripe leg, nothing to
     *    expire); `paid`/`active` are settled money; and `past_due` is
     *    deliberately EXCLUDED — a failed installment never ejects an enrolled
     *    child, that is an explicit admin action
     *    (docs/t006-registration-billing-design.md).
     *  - a NON-NULL window. Null means "no deadline was ever set", not "expired
     *    long ago" — and settlement nulls it on purpose so a paid seat can
     *    never be swept even if some other clause were relaxed.
     */
    public function scopeCheckoutExpiredBefore(Builder $query, $deadline): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where('payment_status', self::PAYMENT_AWAITING)
            ->whereNotNull('checkout_expires_at')
            ->where('checkout_expires_at', '<=', $deadline);
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

    /**
     * The administrator who recorded this registration by hand, or null for
     * every registration that came through the public door.
     *
     * Nullable rather than required even on the staff path, because a console
     * or seeder caller has no `users` row to name — the same call
     * `GroupMembership::confirmedByStaff()` and `ContactFamilyLoginController`
     * already make. A staff entry with no recorded actor is still better
     * evidence than one with no provenance at all, and the gap is visible on the
     * screen as such rather than guessed at.
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    /**
     * Stamp this registration as one an authenticated administrator entered by
     * hand — THE ONE PLACE `source` becomes 'staff'.
     *
     * Written with forceFill because the three columns are not fillable: they
     * record on whose authority the row exists, and the guardian edge
     * `RegistrationService::writeRosterMemberships()` derives from it opens a
     * child's behaviour, ḥifẓ and safeguarding records to whoever holds it. The
     * service cannot see who asked — `confirm()` runs from a webhook days later
     * with no request and no principal — so the door that assembled the
     * registrant list is the only place that can record the author, and this is
     * that record.
     *
     * IT CLAIMS NOTHING ABOUT MONEY. Nothing here touches `payment_status`,
     * `adjusted_total_minor` or any Stripe column, and no caller may add one:
     * a registration is paid when a signature-verified webhook says so, when
     * its plan is free, or when a 100% waiver routes it through `confirm()`.
     */
    public function enteredByStaff(?User $actor, ?string $note = null): static
    {
        $note = $note === null ? null : trim($note);

        $this->forceFill([
            'source' => self::SOURCE_STAFF,
            'entered_by_user_id' => $actor?->getKey(),
            'staff_note' => $note === '' ? null : $note,
        ]);

        return $this;
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

    /**
     * A delayed payment (a bank debit) completed this registration's current
     * Checkout page and its money is still moving: the seat is held with no
     * deadline, and nothing is owed a second time.
     *
     * Only RegistrationPaymentService::holdWhilePaymentClears() produces this
     * shape, and `stripe_checkout_session_id` is the clause that keeps it that
     * way. A pending, awaiting seat with NO deadline is otherwise also what
     * `register()` and `promoteFromWaitlist()` write for a STAFF-entered
     * registration — deliberately, because no session exists to expire — so the
     * two shapes differ only in whether a Checkout Session was ever opened. Drop
     * the session clause and every hand-entered seat starts claiming a payment
     * is on its way when nobody has paid anything. Every settlement moves the
     * money state on. One definition, so the checkout door and anything that
     * explains the state agree.
     */
    public function paymentIsClearing(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->payment_status === self::PAYMENT_AWAITING
            && $this->stripe_checkout_session_id !== null
            && $this->checkout_expires_at === null;
    }
}
