<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * DonationSubscription — a donor's standing recurring gift ("$50/month to Zakat").
 *
 * One row per Stripe subscription. The individual monthly charges are ordinary
 * Donation rows (type = 'recurring') linked back by stripe_subscription_id, so
 * receipts and year-end statements treat recurring and one-time gifts the same.
 *
 * Like Donation, this is created in the PUBLIC (unbound) context, so masjid_id is
 * set explicitly by the caller rather than by the BelongsToMasjid creating hook.
 * The row is 'pending' until the first invoice is paid; the webhook advances it.
 */
class DonationSubscription extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'uuid',
        'masjid_id',
        'contact_id',
        'fund_id',
        'intended_amount',
        'charged_amount',
        'currency',
        'donor_covers_fees',
        'interval',
        'status',
        'stripe_subscription_id',
        'stripe_checkout_session_id',
        'stripe_customer_id',
        'application_fee_percent',
        'idempotency_key',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'intended_amount' => 'integer',
            'charged_amount' => 'integer',
            'donor_covers_fees' => 'boolean',
            'application_fee_percent' => 'decimal:2',
            'canceled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DonationSubscription $sub): void {
            if (empty($sub->uuid)) {
                $sub->uuid = (string) Str::uuid();
            }
        });
    }

    public function fund()
    {
        return $this->belongsTo(Fund::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /** The individual charges booked against this subscription. */
    public function donations()
    {
        return $this->hasMany(Donation::class, 'stripe_subscription_id', 'stripe_subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
