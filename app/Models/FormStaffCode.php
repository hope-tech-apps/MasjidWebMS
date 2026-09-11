<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One staff member's secret code for taking cash at one form's gate
 * (DECISIONS.md 2026-09-11, "per-staff codes settle cash").
 *
 * A valid code settles a walk-up's registration as cash that its HOLDER now
 * owes, at the list price (FormResponse::settleCash()). That is the point of one
 * code per person rather than one per event: "who took this money?" becomes a
 * GROUP BY over staff_code_id instead of a memory.
 *
 * The row is the RECORD of a credential, never the credential. `code_hash` is an
 * HMAC keyed on APP_KEY (the ContactLoginCode / FamilyLoginService pattern), and
 * the plaintext exists only in issue()'s return value, which an admin sees once.
 * A bare sha256 of eight characters is reversible by anyone holding a copy of
 * the table — or of the database cache, where a throttle bucket keyed on it
 * would land — so every key derived from a code goes through hashFor().
 *
 * ## Mistyping is expected, so the alphabet forgives it
 *
 * Codes are Crockford base32 (no I, L, O or U). normalise() folds what a person
 * reading one off a phone actually types — lower case, O for 0, I or L for 1,
 * spaces and dashes — onto the one spelling that was hashed. Without that,
 * every fat-fingered code is a failed attempt against a limiter that every phone
 * on the venue's wifi shares.
 *
 * ## Scoping
 *
 * `BelongsToMasjid`: the admin panel runs bound, so another masjid's codes are
 * invisible there. The public gate runs UNBOUND — no admin, only a phone and a
 * code — so findUsable() filters by the header's masjid AND the form by hand.
 * Isolation is pinned by tests/Feature/FormStaffCodeTenantIsolationTest.php and
 * the helpers by tests/Feature/FormStaffCodeModelTest.php.
 *
 * `$fillable` is what an admin types plus the two keys the form decides (the
 * creating hook overrides masjid_id whenever a tenant is bound). The digest, the
 * counters, the device binding and the revocation are server-set only.
 */
class FormStaffCode extends Model
{
    use BelongsToMasjid, HasFactory;

    /** Crockford's base32 alphabet: 32 symbols, none easily misread as another. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Eight symbols — 32^8, about 1.1e12 codes — shown as XXXX-XXXX. */
    public const LENGTH = 8;

    protected $fillable = [
        'masjid_id',
        'form_id',
        'holder_name',
        'expires_at',
    ];

    /**
     * The digest never travels. Nothing should serialise this model whole, but a
     * future toArray() must not be the thing that publishes it.
     */
    protected $hidden = [
        'code_hash',
    ];

    protected $attributes = [
        'use_count' => 0,
        'binding_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'bound_at' => 'datetime',
            'binding_released_at' => 'datetime',
            'last_used_at' => 'datetime',
            'use_count' => 'integer',
            'binding_count' => 'integer',
            'created_by_user_id' => 'integer',
            'revoked_by_user_id' => 'integer',
            'binding_released_by_user_id' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** The registrations this code settled — the holder's cash, row by row. */
    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class, 'staff_code_id');
    }

    /**
     * withTrashed: removing an admin soft-deletes their login, and "who issued
     * (or revoked, or released) this code?" must still have an answer afterwards.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id')->withTrashed();
    }

    public function bindingReleasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'binding_released_by_user_id')->withTrashed();
    }

    // ------------------------------------------------------------ the secret

    /**
     * A fresh code, formatted XXXX-XXXX. `random_int()` is the CSPRNG;
     * `rand()`, `mt_rand()` and `Str::random()` are not, and a predictable code
     * is the same failure as a stored one.
     */
    public static function generate(): string
    {
        $symbols = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $symbols .= self::ALPHABET[random_int(0, $last)];
        }

        return substr($symbols, 0, 4) . '-' . substr($symbols, 4);
    }

    /**
     * The one spelling a code is hashed in: upper case, with spaces and dashes
     * removed — including the non-breaking spaces and Unicode dashes a pasted
     * message may carry — and the letters Crockford excludes folded onto the
     * digits they are mistaken for.
     */
    public static function normalise(string $code): string
    {
        $stripped = (string) preg_replace('/[\s\x{00A0}\x{202F}\-\x{2010}-\x{2015}\x{2212}]+/u', '', $code);

        return strtr(strtoupper($stripped), ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /**
     * The digest stored and looked up — and the ONLY thing any other key (a
     * throttle bucket, a log line) may be derived from. Normalises first, so no
     * caller can hash a spelling the lookup would not.
     */
    public static function hashFor(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), (string) config('app.key'));
    }

    /** The last two characters, for telling codes apart in the admin panel. */
    public static function hintFor(string $code): string
    {
        return substr(self::normalise($code), -2);
    }

    /**
     * Mint a code for one person on one form.
     *
     * Returns the row AND the plaintext, and the plaintext exists nowhere else:
     * the caller shows it once and lets it go. The unique (form_id, code_hash)
     * index is the collision guard — with 1.1e12 codes, a clash among the dozen
     * on one form is not a case worth a retry loop.
     *
     * The form decides the masjid. On a bound admin request the creating hook
     * stamps the bound tenant instead, so a form from any other masjid is refused
     * here rather than written as a code whose masjid and form disagree.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Form $form, string $holderName, CarbonInterface $expiresAt, ?User $createdBy = null): array
    {
        $tenant = app(TenantContext::class);

        if ($tenant->hasTenant() && (int) $tenant->get() !== (int) $form->masjid_id) {
            throw new LogicException('A staff code is issued only on a form of the masjid being administered.');
        }

        $plain = self::generate();

        $code = new self([
            'masjid_id' => $form->masjid_id,
            'form_id' => $form->id,
            'holder_name' => $holderName,
            'expires_at' => $expiresAt,
        ]);

        $code->forceFill([
            'code_hash' => self::hashFor($plain),
            'code_hint' => self::hintFor($plain),
            'created_by_user_id' => $createdBy?->getKey(),
        ])->save();

        return [$code, $plain];
    }

    // ------------------------------------------------------------- lifecycle

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Expired AT the stored instant, not a second after it. */
    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at === null || ! ($at ?: now())->lt($this->expires_at);
    }

    public function isUsable(?CarbonInterface $at = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($at);
    }

    /**
     * The live code a phone at the gate typed, for this form at this masjid — or
     * null.
     *
     * Hand-filtered, because the public submit runs unbound: the masjid comes
     * from the request header and the form from the URL, and both must match.
     * Unknown, revoked, expired, another form's and another masjid's codes all
     * come back as the same null, so a caller cannot help but refuse them the
     * same way. (It must also refuse when the form has codes switched off —
     * Form::takesStaffCodes() — which this does not know about.)
     */
    public static function findUsable(int $masjidId, int $formId, string $code): ?self
    {
        if (self::normalise($code) === '') {
            return null;
        }

        $row = static::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('form_id', $formId)
            ->where('code_hash', self::hashFor($code))
            ->first();

        return $row !== null && $row->isUsable() ? $row : null;
    }

    /**
     * Revoke, once. One conditional UPDATE, so the first press is the one
     * recorded: a second — or a colleague's in the same second — changes nothing
     * and returns false.
     */
    public function revoke(?User $by = null): bool
    {
        $affected = static::withoutMasjidScope()
            ->whereKey($this->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $by?->getKey()]);

        $this->refresh();

        return $affected === 1;
    }

    /**
     * Tie this code to the first device that uses it.
     *
     * One conditional UPDATE, so two phones trying the same code at once cannot
     * both win, and a copy of the row loaded before someone else claimed it
     * cannot steal it back. The claim is counted in the same statement
     * (binding_count), so the count is exactly the claims: more phones than its
     * holder has had means the code was used from someone else's. True when the
     * code is (now, or already) bound to this device; false when another device
     * holds it. A blank or oversized id binds nothing.
     */
    public function bindToDevice(string $deviceId): bool
    {
        $deviceId = trim($deviceId);

        if ($deviceId === '' || strlen($deviceId) > 255) {
            return false;
        }

        static::withoutMasjidScope()
            ->whereKey($this->getKey())
            ->whereNull('bound_device_id')
            ->increment('binding_count', 1, ['bound_device_id' => $deviceId, 'bound_at' => now()]);

        $this->refresh();

        return $this->bound_device_id === $deviceId;
    }

    /**
     * An admin's "reset device": the next device to use the code claims it.
     *
     * Stamped with who released it and when, in the same conditional UPDATE, and only
     * when a phone was holding it. A release is how a code comes to be used from a phone
     * that is not its holder's, so, like a revoke, it never happens without a name
     * against it; with binding_count the panel can show that a code has changed hands.
     * False when no phone had claimed it, and then nothing is recorded.
     */
    public function releaseDevice(?User $by = null): bool
    {
        $affected = static::withoutMasjidScope()
            ->whereKey($this->getKey())
            ->whereNotNull('bound_device_id')
            ->update([
                'bound_device_id' => null,
                'bound_at' => null,
                'binding_released_at' => now(),
                'binding_released_by_user_id' => $by?->getKey(),
            ]);

        $this->refresh();

        return $affected === 1;
    }

    /**
     * Count one cash entry recorded against this code. Atomic at the database.
     * FormResponse::settleCash() calls it inside the transaction that settles the
     * row, so the count and the rows it counts cannot disagree.
     */
    public function recordUse(): void
    {
        $this->increment('use_count', 1, ['last_used_at' => now()]);
    }
}
