<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A sign-in code issued to an EMAIL ADDRESS, before anybody is known.
 *
 * ---------------------------------------------------------------------------
 * Why this is not `contact_login_codes`
 * ---------------------------------------------------------------------------
 * That table's `contact_id` is a non-nullable constrained FK, and the premise
 * of app sign-up is a first code sent to an address with no contact behind it.
 * Making that column nullable would loosen a shipped auth table to accommodate
 * a newer, less trusted flow — and would let a bug in this path write a
 * contact-less row into the family realm's own table. Two tables, same shape,
 * no shared failure.
 *
 * ---------------------------------------------------------------------------
 * A row here is not an account
 * ---------------------------------------------------------------------------
 * Issuing a code creates nothing but this row. The `contacts` record is created
 * or matched only when a code is REDEEMED, in the transaction that burns it. So
 * an attacker spraying the request endpoint with a dictionary fills this table
 * (which expires and is prunable) and never writes a single contact into the
 * CRM the office works in.
 *
 * Tenant-scoped like every CRM model (.claude/rules/tenant-scoping.md). That
 * matters more here than usual: the mobile API is UNBOUND by default and names
 * its masjid in the URL, so the route MUST bind the tenant before touching this
 * model — an unbound lookup would match an address across every masjid in the
 * database and turn the mailer into a cross-tenant existence oracle. See
 * App\Http\Middleware\ResolveFamilyGuestTenant, which documents that trap for
 * the family realm and is the reason this one binds too.
 */
class AppSignupCode extends Model
{
    use BelongsToMasjid;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
    ];

    protected $fillable = [
        'masjid_id',
        'email',
        'code_hash',
        'channel',
        'expires_at',
        'requested_ip',
    ];

    /**
     * The digest never travels. Nothing in this realm serializes a model
     * directly, but a future `toArray()` must not be what publishes it.
     */
    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** The most guesses a single row tolerates. */
    public static function maxAttempts(): int
    {
        return max(1, (int) config('member.signup.max_attempts', 5));
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isLockedOut(): bool
    {
        return (int) $this->attempts >= self::maxAttempts();
    }

    /**
     * Three independent reasons a code cannot mint a token, checked together
     * and reported to the caller as one indistinguishable refusal.
     */
    public function isRedeemable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired() && ! $this->isLockedOut();
    }

    /**
     * Codes that could still be redeemed right now. Requesting a second code
     * does NOT invalidate the first — a slow relay must not lock somebody out
     * of their own retry — so several may be live and each is single-use.
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::maxAttempts());
    }
}
