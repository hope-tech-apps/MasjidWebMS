<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactLoginCode — one emailed sign-in code for one parent/guardian (T-015d).
 *
 * The row is the RECORD of a credential, never the credential: `code_hash` is a
 * keyed digest (see the migration for why keyed and not a bare sha256), the
 * plaintext exists only inside App\Services\Family\FamilyLoginService::issue()
 * for as long as it takes to hand it to the mailer, and nothing in this class
 * can reproduce it.
 *
 * `BelongsToMasjid` because a sign-in attempt is tenant data — which family
 * asked to sign in, when, and from where is exactly as sensitive as the roster
 * it opens, and .claude/rules/tenant-scoping.md admits no unscoped CRM model.
 * Cross-tenant isolation is pinned by
 * tests/Feature/ContactLoginCodeTenantIsolationTest.php, which that rule
 * requires of every new model using the trait. (It named
 * `FamilyPortalTenantIsolationTest` until 2026-08-12; no such file was ever
 * written, so the mandatory test read as done while none existed.)
 *
 * ## `$fillable` is deliberately narrow
 *
 * `consumed_at` and `attempts` are the two columns the whole single-use /
 * lockout guarantee rests on, so they are not mass-assignable: they are written
 * by the service, explicitly, and by nothing that takes a request body.
 */
class ContactLoginCode extends Model
{
    use BelongsToMasjid;

    /**
     * How a code reached the parent.
     *
     * EMAIL IS THE ONLY VALUE, and SMS is absent by decision rather than by
     * backlog:
     *
     *  - `.claude/rules/broadcasts.md`: "a phone number is not consent". A
     *    contact's `phone` is typically imported from an admissions
     *    spreadsheet, and `sms_opt_in` — with its timestamp, source and
     *    evidence — is what makes texting that number lawful. Almost no roster
     *    row carries one, so an SMS channel would be unusable for the very
     *    population it was added for.
     *  - Even WITH consent it would be wrong here. That consent was given for
     *    bulk announcements, not for a credential; a recycled mobile number is
     *    then a working key to a specific child's photographs and safeguarding
     *    conversations, and the subscriber who inherited it never did anything
     *    wrong. `docs/t015-parent-identity-design.md` §3 states this directly:
     *    delivery goes only to a `login_email` an administrator set, "never to
     *    an unverified imported phone".
     *
     * A plain string column, so adding a channel one day is a constant here and
     * not an `ALTER TABLE` (.claude/rules/migrations.md).
     *
     * @var array<int, string>
     */
    public const CHANNEL_EMAIL = 'email';

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
    ];

    protected $fillable = [
        'masjid_id',
        'contact_id',
        'code_hash',
        'channel',
        'expires_at',
        'requested_ip',
    ];

    /**
     * The digest never travels. `$hidden` is belt-and-braces — nothing in the
     * family realm serializes a model directly (every payload is built by hand,
     * see App\Http\Controllers\Family\MeController) — but a future `toArray()`
     * on this row must not be the thing that publishes it.
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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** The most codes a single row tolerates being guessed at. */
    public static function maxAttempts(): int
    {
        return max(1, (int) config('family.login.max_attempts', 5));
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
     * Could this row still mint a token if the right digits arrived?
     *
     * Three independent reasons it could not, all checked together and all
     * answering the caller with the same refusal — see
     * App\Http\Controllers\Family\FamilyAuthController for why the API never
     * distinguishes them.
     */
    public function isRedeemable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired() && ! $this->isLockedOut();
    }

    /**
     * Codes that could still be redeemed right now.
     *
     * Ordered newest-first by the caller: requesting a second code does NOT
     * invalidate the first (a parent whose first mail was slow must not be
     * locked out by their own retry), so more than one may be live at a time
     * and each is independently single-use.
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::maxAttempts());
    }
}
