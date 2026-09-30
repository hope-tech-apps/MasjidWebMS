<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The digest that is stored for a basket token: HMAC-SHA256 keyed on the application key,
     * the construction FamilyInviteService::hash() and FamilyLoginService::hash() use. The
     * token is 32 random bytes as hex, so a keyed digest looked up by indexed equality is
     * enough (no constant-time compare: the lookup is the comparison, on a 2^256 secret).
     * The key is read through config('app.key') and never handled beyond this line.
     */
    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /**
     * The basket this token opens at this organisation, or null.
     *
     * The public basket routes run UNBOUND, so the organisation is filtered by hand (and the
     * scope bypassed on purpose): a token belonging to another organisation's basket is not
     * found, exactly like a token that never existed. An expired basket is not found either;
     * a basket with no expiry (one not made by the public endpoint) never expires. Anything
     * that is not the 64 lower-case hex characters a token is never reaches the database.
     */
    public static function findLiveByToken(string $token, int $masjidId): ?self
    {
        if ($masjidId <= 0 || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        return static::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('token_hash', self::hashToken($token))
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * The stored digest of the basket this token opens, or null when it opens none. Asked by
     * the basket's own rate limiters, which key on the digest (never on the token, a bearer
     * secret that would otherwise sit in the cache) and send a token that names no live
     * basket to the per-connection bucket instead.
     */
    public static function liveTokenHash(mixed $token, int $masjidId): ?string
    {
        if (! is_string($token)) {
            return null;
        }

        $cart = static::findLiveByToken($token, $masjidId);

        return $cart === null ? null : (string) $cart->token_hash;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
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
