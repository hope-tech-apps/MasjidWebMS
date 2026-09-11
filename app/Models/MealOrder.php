<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A customer's meal order.
 *
 * The Stripe discipline mirrors `Registration`: the row is written `pending`
 * BEFORE Stripe is called, carries an `idempotency_key`, and is only advanced
 * to paid by the webhook — never by the browser redirect. Money is integer
 * minor units; status/payment columns are strings backed by the constants here.
 *
 * `donation_minor` is the one amount a CUSTOMER chooses, but it is still not
 * fillable: it is clamped to a ceiling in the controller and forced to 0 when
 * the menu does not offer it, so it reaches the row through that gate or not at
 * all. `fee_covered_minor` is chosen by the customer too, but only as a yes/no —
 * the AMOUNT is always computed on the server from Stripe's published rate, so
 * no request body ever states a surcharge. `total_minor` is always
 * subtotal + donation + fee_covered.
 *
 * Server-computed columns (totals, the two status columns, the Stripe ids,
 * `order_number`, the timestamps, and who marked it paid by hand and how) are
 * DELIBERATELY not fillable — they move only through the methods below or the
 * checkout/payment services, never a request body. `$fillable` is the small set
 * a customer actually supplies.
 */
class MealOrder extends Model
{
    use HasFactory, BelongsToMasjid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_READY = 'ready';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_READY,
        self::STATUS_PICKED_UP,
        self::STATUS_CANCELLED,
    ];

    public const METHOD_ONLINE = 'online';
    public const METHOD_PICKUP = 'pickup';

    public const METHODS = [
        self::METHOD_ONLINE,
        self::METHOD_PICKUP,
    ];

    /** Which door the order came through. */
    public const SOURCE_ONLINE = 'online'; // the public order page
    public const SOURCE_STAFF = 'staff';   // taken on the lunch board by an admin or volunteer

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_REFUNDED = 'refunded';

    /**
     * How the money came when staff mark an order paid by hand (`paid_via`),
     * chosen on the board from exactly these. `stripe` is money taken through
     * some OTHER Stripe route (the organisation's own link or dashboard), a
     * label staff record like the others. A payment on the order's own
     * Checkout page is recorded by the webhook alone and leaves `paid_via`
     * null (DECISIONS.md 2026-09-11).
     */
    public const PAID_VIA_CASH = 'cash';
    public const PAID_VIA_ZELLE = 'zelle';
    public const PAID_VIA_TERMINAL = 'terminal';
    public const PAID_VIA_STRIPE = 'stripe';

    public const PAID_VIA = [
        self::PAID_VIA_CASH,
        self::PAID_VIA_ZELLE,
        self::PAID_VIA_TERMINAL,
        self::PAID_VIA_STRIPE,
    ];

    /** The words the board shows for each, in the order it offers them. */
    public const PAID_VIA_LABELS = [
        self::PAID_VIA_CASH => 'Cash',
        self::PAID_VIA_ZELLE => 'Zelle',
        self::PAID_VIA_TERMINAL => 'Masjid Terminal',
        self::PAID_VIA_STRIPE => 'Stripe',
    ];

    /**
     * Ceiling on the optional extra, in minor units ($1,000).
     *
     * Not a judgement about generosity — a bound on what an unauthenticated,
     * public endpoint can put into a Checkout Session on a masjid's live Stripe
     * account. Anyone wanting to give more than this should be giving through
     * the donations module, where it is designated to a fund and receipted.
     */
    public const MAX_DONATION_MINOR = 100000;

    protected $fillable = [
        'masjid_id',
        'meal_menu_id',
        'contact_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_notes',
        'payment_method',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'payment_method' => self::METHOD_PICKUP,
        'payment_status' => self::PAYMENT_UNPAID,
        'subtotal_minor' => 0,
        'donation_minor' => 0,
        'fee_covered_minor' => 0,
        'total_minor' => 0,
        'currency' => 'usd',
        'source' => self::SOURCE_ONLINE,
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'donation_minor' => 'integer',
            'fee_covered_minor' => 'integer',
            'total_minor' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'entered_by_user_id' => 'integer',
            'marked_paid_by_user_id' => 'integer',
            'paid_via' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MealOrder $order): void {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(MealMenu::class, 'meal_menu_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealOrderItem::class);
    }

    /**
     * The staff login that took this order on the board (null for online orders).
     * withTrashed: removing a volunteer soft-deletes their login, and "who took
     * this order?" must still have an answer afterwards.
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id')->withTrashed();
    }

    /** The staff login that marked the order paid by hand (null for an order Stripe marked paid). */
    public function markedPaidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_paid_by_user_id')->withTrashed();
    }

    /**
     * The next per-menu order number ("007"), shared by every door that creates
     * an order — the public page and staff entry — so the two can never number
     * differently. Call inside the transaction that saves the order.
     *
     * The MENU row is locked first. It always exists, so two first orders on an
     * empty menu queue here; locking only meal_orders would give each a gap lock
     * (compatible with each other) and InnoDB would deadlock their inserts,
     * failing one customer. The unique index on (masjid_id, meal_menu_id,
     * order_number) stays the final guarantee against a duplicate number.
     */
    public static function nextOrderNumber(int $masjidId, int $menuId): string
    {
        MealMenu::withoutMasjidScope()->whereKey($menuId)->lockForUpdate()->first();

        $seq = static::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('meal_menu_id', $menuId)
            ->lockForUpdate()
            ->count() + 1;

        return str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function isOnline(): bool
    {
        return $this->payment_method === self::METHOD_ONLINE;
    }

    /**
     * Flip to paid, once. Idempotent: a second success event (the webhook can
     * see both checkout.session.completed and payment_intent.succeeded) is a
     * no-op on the timestamp so `paid_at` records when money first landed.
     */
    public function markPaid(?string $paymentIntentId = null): void
    {
        if ($paymentIntentId !== null && $this->stripe_payment_intent_id === null) {
            $this->stripe_payment_intent_id = $paymentIntentId;
        }

        if ($this->payment_status !== self::PAYMENT_PAID) {
            $this->payment_status = self::PAYMENT_PAID;
            $this->paid_at = Carbon::now();
            if ($this->status === self::STATUS_PENDING) {
                $this->status = self::STATUS_CONFIRMED;
            }
        }

        $this->save();
    }

    /**
     * Money taken by staff (MealOrdersController::markPaid): how it came and who
     * recorded it, then paid. The first press only: on an order already paid
     * nothing is written, so a second press, or a colleague's, never rewrites
     * how the money came or who took it. False when it was already paid.
     */
    public function markPaidByHand(string $via, ?int $userId): bool
    {
        if (! in_array($via, self::PAID_VIA, true)) {
            throw new \InvalidArgumentException("Unknown way of paying: {$via}");
        }

        if ($this->payment_status === self::PAYMENT_PAID) {
            return false;
        }

        $this->paid_via = $via;
        $this->marked_paid_by_user_id = $userId;
        $this->markPaid();

        return true;
    }

    /**
     * Paid on its own Checkout page, as the webhook records it: paid, on an online
     * order, with no way of paying and nobody recorded. Mark paid refuses it
     * (MealOrdersController::markPaid). A pickup order marked paid before the
     * board asked how, or before it said who, was paid by hand, not this.
     */
    public function paidOnItsOwnPage(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID
            && $this->payment_method === self::METHOD_ONLINE
            && $this->paid_via === null
            && $this->marked_paid_by_user_id === null;
    }

    public function markPickedUp(): void
    {
        $this->status = self::STATUS_PICKED_UP;
        if ($this->picked_up_at === null) {
            $this->picked_up_at = Carbon::now();
        }
        $this->save();
    }

    /**
     * Resolve an order by public uuid within one masjid. Public status/return
     * pages run UNBOUND, so masjid_id is filtered by hand — a foreign uuid misses.
     */
    public static function findByUuidForMasjid(string $uuid, int $masjidId): ?self
    {
        return static::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('uuid', $uuid)
            ->first();
    }
}
