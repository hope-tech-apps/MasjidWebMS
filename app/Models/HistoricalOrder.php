<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order another system took, imported as history — today, MEC's Wix store
 * and Wix Events orders (2017-2026), written only by `crm:import-wix-orders`
 * (App\Services\Crm\WixOrderHistoryImporter).
 *
 * The money on these rows moved through Square or PayPal at the Wix checkout,
 * never through Manara or Stripe. That is why the donations and registrations
 * made from an order carry `source = 'historical'` and point back here, and why
 * every report of what Manara processed leaves them out (DECISIONS.md
 * 2026-09-25, "Wix order history").
 *
 * Tenant-scoped (BelongsToMasjid). The importer runs from the console with the
 * tenant bound explicitly and also sets `masjid_id` itself.
 *
 * `lines` holds each line as it was sold — product name, quantity, unit price in
 * minor units — and `recorded_as`: `donation`, `registration`, or `order_only`
 * for a purchase that is neither a gift nor a seat (festival food tickets, the
 * 2021 prayer rugs). An `order_only` line lives here and nowhere else.
 */
class HistoricalOrder extends Model
{
    use BelongsToMasjid;

    public const SOURCE_WIX_STORES = 'wix_stores';
    public const SOURCE_WIX_EVENTS = 'wix_events';

    public const PROVIDER_SQUARE = 'square';
    public const PROVIDER_PAYPAL = 'paypal';
    /** Paid at the Wix checkout; the export does not say which processor took it. */
    public const PROVIDER_WIX = 'wix';

    public const PROVIDERS = [self::PROVIDER_SQUARE, self::PROVIDER_PAYPAL, self::PROVIDER_WIX];

    public const STATUS_PAID = 'paid';
    /** Wix cancels a checkout that was abandoned or never paid. No money moved. */
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_DECLINED = 'declined';

    public const RECORDED_AS_DONATION = 'donation';
    public const RECORDED_AS_REGISTRATION = 'registration';
    public const RECORDED_AS_ORDER_ONLY = 'order_only';

    protected $fillable = [
        'masjid_id',
        'source',
        'order_number',
        'provider',
        'payment_method',
        'status',
        'ordered_at',
        'contact_id',
        'total_minor',
        'discount_minor',
        'fee_minor',
        'currency',
        'lines',
        'import_batch',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'total_minor' => 'integer',
            'discount_minor' => 'integer',
            'fee_minor' => 'integer',
            'lines' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
