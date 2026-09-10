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
 * `order_number`, the timestamps) are DELIBERATELY not fillable — they move only
 * through the methods below or the checkout/payment services, never a request
 * body. `$fillable` is the small set a customer actually supplies.
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

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_REFUNDED = 'refunded';

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
