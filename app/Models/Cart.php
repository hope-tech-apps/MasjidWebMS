<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shopper's open basket at one organisation (universal cart, DECISIONS
 * 2026-09-26). See the carts migration for why a basket reserves nothing, why a
 * guest's handle is only ever stored hashed, and why a basket goes with its
 * contact on account deletion.
 *
 * Tenant-scoped (BelongsToMasjid). The public basket pages run UNBOUND, so every
 * read on them filters masjid_id explicitly — see CartPricer, which also loads
 * each line's item within this basket's organisation only.
 */
class Cart extends Model
{
    use BelongsToMasjid;

    public const STATUS_OPEN = 'open';

    /**
     * The basket was paid for. Set by the settlement transaction (CartSettlementService),
     * never by a shopper: a closed basket is refused by checkout, so the same lines can
     * never be charged and recorded a second time.
     */
    public const STATUS_CHECKED_OUT = 'checked_out';

    protected $fillable = [
        'masjid_id',
        'contact_id',
        'token_hash',
        'status',
        'expires_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    /** Never serialised: whoever holds the token can read and edit this basket. */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function masjid(): BelongsTo
    {
        return $this->belongsTo(Masjid::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
