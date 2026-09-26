<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Donation — one donor gift. Tenant-scoped by masjid_id (BelongsToMasjid).
 *
 * All amounts are integer minor units (cents). A row is created `pending`
 * before the Stripe redirect and only advanced to `succeeded`/`failed` by
 * webhooks — the browser redirect is never trusted (see StripeWebhookController
 * and .claude/rules/stripe-payments.md).
 *
 * Note the donation is usually created in the PUBLIC (unbound) mobile context,
 * where the BelongsToMasjid creating hook does NOT stamp masjid_id — the
 * caller (DonationService) sets it explicitly from the validated route masjid.
 * A public UUID is generated on create for use as an opaque external handle.
 */
class Donation extends Model
{
    use HasFactory, BelongsToMasjid;

    /** Created and advanced by a Stripe webhook. */
    public const SOURCE_STRIPE = 'stripe';

    /** Recorded by an administrator (cash, cheque, Zelle…) or a ledger import. */
    public const SOURCE_OFFLINE = 'offline';

    /**
     * A gift another system took before the organisation came to Manara — today,
     * MEC's Wix store orders paid through Square or PayPal (DECISIONS.md
     * 2026-09-25, "Wix order history"). Written only by `crm:import-wix-orders`,
     * always with `historical_order_id` naming the order.
     *
     * It is donor HISTORY, not money Manara handled: it issues no receipt, is not
     * editable here, sits on no annual statement, and is left out of every total
     * that reports what came in (the giving dashboard, the ledger and its CSV
     * unless asked for by name, impact figures, module facts). `withoutHistorical()`
     * is the one spelling of that exclusion.
     */
    public const SOURCE_HISTORICAL = 'historical';

    public const SOURCES = [self::SOURCE_STRIPE, self::SOURCE_OFFLINE, self::SOURCE_HISTORICAL];

    /**
     * How an OFFLINE gift arrived (`payment_method`), the one allow-list both
     * offline-gift requests validate against. Bank transfer (2026-09-25) is one of
     * the ways an organisation can advertise being paid (App\Support\
     * PaymentMethods::OFFLINE), so a gift that came that way has to be recordable
     * as such rather than as "other". Appended: every stored value keeps its meaning.
     */
    public const OFFLINE_PAYMENT_METHODS = [
        'cash', 'check', 'zelle', 'venmo', 'paypal', 'square', 'credit', 'giftcard', 'other', 'bank_transfer',
    ];

    protected $fillable = [
        'uuid',
        'masjid_id',
        'contact_id',
        'fund_id',
        'type',
        // The giver's zakat restriction on THIS gift, and how it was arrived at.
        // Not derived from the fund — see App\Support\ZakatDesignation.
        'is_zakat',
        'zakat_source',
        'source',
        'payment_method',
        'check_number',
        'donated_at',
        'note',
        'import_batch',
        'intended_amount',
        'charged_amount',
        'currency',
        'donor_covers_fees',
        'status',
        'stripe_payment_intent_id',
        'stripe_checkout_session_id',
        'stripe_subscription_id',
        'stripe_invoice_id',
        'stripe_charge_id',
        'stripe_balance_transaction_id',
        'application_fee_amount',
        'stripe_fee_amount',
        'net_amount',
        'receipt_eligible_amount',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'donated_at' => 'date',
            'is_zakat' => 'boolean',
            'intended_amount' => 'integer',
            'charged_amount' => 'integer',
            'donor_covers_fees' => 'boolean',
            'application_fee_amount' => 'integer',
            'stripe_fee_amount' => 'integer',
            'net_amount' => 'integer',
            'receipt_eligible_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Assign an opaque public UUID if the caller didn't supply one. Kept
        // separate from the auto-increment id so it can be exposed to clients.
        static::creating(function (Donation $donation): void {
            if (empty($donation->uuid)) {
                $donation->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Rows Manara itself recorded — Stripe and offline — leaving out imported
     * history. Every report of money received starts here.
     */
    public function scopeWithoutHistorical(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('source'), '!=', self::SOURCE_HISTORICAL);
    }

    public function isHistorical(): bool
    {
        return $this->source === self::SOURCE_HISTORICAL;
    }

    /** The imported order this gift came from; null for everything Manara recorded. */
    public function historicalOrder(): BelongsTo
    {
        return $this->belongsTo(HistoricalOrder::class);
    }

    public function fund()
    {
        return $this->belongsTo(Fund::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function receipt()
    {
        return $this->hasOne(DonationReceipt::class);
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
