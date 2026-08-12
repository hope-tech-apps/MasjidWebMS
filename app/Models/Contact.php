<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use App\Services\Sms\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Contact — a congregant record. First consumer of the CRM tenant-isolation
 * guardrail (Phase 0 of the donation/CRM build).
 *
 * The BelongsToMasjid trait supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * in $fillable so system/super code can set it while UNBOUND; when a tenant is
 * bound the creating hook overrides it regardless. See BelongsToMasjid.
 *
 * ---------------------------------------------------------------------------
 * T-015c — a contact can now AUTHENTICATE, behind its own guard
 * ---------------------------------------------------------------------------
 *
 * A contact already IS the person the classroom names: `group_memberships`
 * points at contacts, guardian edges point at contacts, and `GroupAudience`
 * reasons in contact ids. Giving one a login therefore adds an AUTHENTICATION
 * fact and changes no AUTHORIZATION fact — nothing below grants a contact
 * standing anywhere. `GroupAudience::identitiesFor()` still refuses any
 * principal that is not an `App\Models\User`, so an authenticated contact
 * resolves to NO identity and therefore no group, no feed, no record, until
 * T-015e adds that branch deliberately.
 *
 * The realm separation is structural, not a policy check:
 *   - `auth.guards.sanctum.provider = users` (T-015a) means a Contact token on
 *     any admin route resolves to null inside vendor code;
 *   - `auth.guards.family.provider = contacts` means a staff token on a family
 *     route does the same in reverse;
 * and both are asserted in tests/Feature/FamilyAuthGuardTest.php.
 *
 * This model deliberately does NOT use Spatie's HasRoles. If it ever does, it
 * must declare its own `$guard_name` — see the hazard recorded on
 * `User::$guard_name` and .claude/rules/auth-permissions.md.
 * ## SMS consent (T-009) is a RECORD on this row, and it is mortal
 *
 * `phone` alone has never been permission to send anything. The four consent
 * columns say whether, when, how and on what evidence this person agreed to
 * receive bulk text messages, and `hasSmsConsent()` requires ALL of them — a
 * flag set by a careless import with no timestamp and no source reads as NO
 * consent rather than as permission.
 *
 * The OPT-OUT here (`sms_opted_out_at`) is only a mirror. The authority is
 * `sms_suppressions`, keyed on the number rather than on this row, because this
 * row can be merged away, force-deleted or re-imported and an opt-out must
 * survive all three. See App\Models\SmsSuppression.
 */
class Contact extends Model implements AuthenticatableContract
{
    use HasFactory, SoftDeletes, BelongsToMasjid, AuthenticatableTrait, HasApiTokens;

    /**
     * The abilities stamped on every parent/guardian token.
     *
     * Deliberately NOT `['*']` and deliberately not `['staff']`: the two realms
     * must be distinguishable in the `personal_access_tokens` table itself, so
     * that a later slice can add `abilities:` enforcement to a route without a
     * flag day for tokens already in people's phones. Staff tokens carry
     * `AuthController::STAFF_TOKEN_ABILITIES` (`['staff']`).
     *
     * Like the staff constant this is INERT today — `tokenCan` and Sanctum's
     * ability middleware appear nowhere in this application, and the realms are
     * kept apart by the guard/provider pair rather than by an ability string.
     * Naming the realm now is what keeps that option open.
     */
    public const FAMILY_TOKEN_ABILITIES = ['family'];

    /**
     * HOW consent was obtained. A constant set rather than free text: free text
     * produces forty spellings of "website" and cannot answer "show me everyone
     * whose consent came from the admissions form" three years later, which is
     * the question a TCPA demand letter actually asks. The specific artifact
     * goes in `sms_consent_evidence` beside it.
     *
     * Stored as a plain string column — adding a source must never mean
     * `ALTER TABLE … MODIFY` on a live table (.claude/rules/migrations.md).
     *
     * `sms_reply_start` is the only value this application writes on its own:
     * it is recorded when the subscriber texts START to the organisation's own
     * registered number, which is express written consent given in the
     * subscriber's own hand.
     *
     * @var array<int, string>
     */
    public const SMS_CONSENT_SOURCES = [
        'web_form',
        'paper_form',
        'in_person',
        'phone_call',
        'sms_reply_start',
        'imported_with_proof',
    ];

    /**
     * NOTE what is absent from $fillable: the four `login_*` columns.
     *
     * They are credentials-adjacent — `login_enabled_at` alone is the
     * difference between a roster row and an account that opens children's
     * photographs — and every write path into this array is a CRM request body
     * (`ContactsController::store`/`update`, the roster import). Leaving them
     * out means no request payload can enable, re-address or un-revoke a login
     * as a side effect of editing a phone number.
     *
     * They are written by `forceFill` in exactly TWO classes and nowhere else:
     *
     *   - `App\Services\Family\FamilyAccessService` — the admin ON-SWITCH.
     *     `enable()` sets `login_email` + `login_enabled_at` and clears
     *     `login_revoked_at`; `revoke()` sets `login_revoked_at`. Both append to
     *     `contact_login_events` and both are reachable only through
     *     `ContactFamilyLoginController`, behind `manage contacts`.
     *   - `App\Services\Family\FamilyLoginService::consume()` — `last_login_at`,
     *     which is operator visibility only and authorizes nothing.
     *
     * If a third writer ever appears, the audit trail stops being complete —
     * which is the point of keeping the list this short.
     */
    protected $fillable = [
        'masjid_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
        'is_placeholder',
        'import_batch',
        // SMS consent (T-009). Fillable so an importer carrying real proof can
        // seed it; every write path that an ADMIN can reach goes through
        // App\Services\Sms\SmsConsentService, which stamps the timestamp
        // server-side and refuses to consent a suppressed number.
        'sms_opt_in',
        'sms_consent_at',
        'sms_consent_source',
        'sms_consent_evidence',
        'sms_opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'is_placeholder' => 'boolean',
            'login_enabled_at' => 'datetime',
            'login_revoked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'sms_opt_in' => 'boolean',
            'sms_consent_at' => 'datetime',
            'sms_opted_out_at' => 'datetime',
        ];
    }

    /**
     * Has this person agreed, provably, to receive bulk text messages?
     *
     * Every clause is load-bearing. The flag is the affirmative; the timestamp
     * and source are what make it defensible; and the opt-out overrides all of
     * them. A row missing any one of the first three is NOT consent — that is
     * the difference between a record and a checkbox.
     *
     * This is necessary but not sufficient: the audience resolver additionally
     * checks the number against `sms_suppressions`, which outlives this row.
     */
    public function hasSmsConsent(): bool
    {
        return $this->sms_opt_in === true
            && $this->sms_consent_at !== null
            && filled($this->sms_consent_source)
            && $this->sms_opted_out_at === null;
    }

    /** This contact's number in E.164, or null when it cannot be resolved. */
    public function smsNumber(): ?string
    {
        return PhoneNumber::e164($this->phone);
    }

    /**
     * A hard-deleted contact must take its credential DOCUMENTS with it.
     *
     * Soft deletes (the normal destroy path) keep credential rows and bytes on
     * purpose — a mis-click must not destroy a provider's credential history.
     * But the merge flow force-deletes absorbed placeholder contacts, and on a
     * hard delete the DB cascade would remove contact_credentials rows without
     * firing a single model event, orphaning the scans on the private disk
     * forever (.claude/rules/private-uploads.md). Deleting each credential
     * through the model here lets its own `deleting` hook reach the disk first.
     *
     * Note what is deliberately NOT cleaned up here: this contact's SMS
     * suppression. `sms_suppressions` has no foreign key to `contacts` and is
     * keyed on the number, precisely so that force-deleting the row cannot
     * un-say a STOP.
     */
    protected static function booted(): void
    {
        static::deleting(function (Contact $contact) {
            if (! $contact->isForceDeleting()) {
                return;
            }

            $contact->credentials()->get()->each->delete();
        });
    }

    // ----------------------------------------------------- family login (T-015c)

    /**
     * May this contact authenticate RIGHT NOW?
     *
     * The three conditions the design names (§3), evaluated together and read
     * on EVERY family request by App\Http\Middleware\EnsureFamilyLoginActive —
     * not once at mint time. That is the whole point: revocation has to reach a
     * token that is already sitting in a phone's keychain, and it has to reach
     * it on the next request rather than whenever the token happens to expire.
     *
     * `trashed()` is in here rather than left to the query layer because the
     * check must hold for a principal handed to us by ANY guard. Sanctum's
     * tokenable lookup already applies the SoftDeletes scope and so resolves a
     * trashed contact to null, but that is a second, independent mechanism —
     * and a merged contact is force-deleted, not trashed, which the tokenable
     * lookup catches and this method never sees.
     */
    public function familyLoginIsActive(): bool
    {
        return $this->login_enabled_at !== null
            && $this->login_revoked_at === null
            && ! $this->trashed();
    }

    /**
     * Mint a parent/guardian token carrying the family realm's abilities.
     *
     * ON EXPIRY, because the design (§2) asks for 30 days and this cannot
     * deliver it: Sanctum builds every guard with the single global
     * `config('sanctum.expiration')` (480 minutes here) and enforces it against
     * `created_at` inside `Laravel\Sanctum\Guard`, so a per-token `expires_at`
     * can only ever SHORTEN a token's life, never extend it past the global
     * value. Raising that value would move staff sessions too, which
     * .claude/rules/auth-permissions.md forbids ("never change how an existing
     * admin logs in"). A longer family session therefore needs a per-guard
     * expiration — a change in how the guard is constructed — and is left to
     * the slice that ships the parent app (T-015j), rather than written here as
     * an `expiresAt` that Sanctum would silently ignore.
     */
    public function createFamilyToken(string $name = 'family-token'): NewAccessToken
    {
        return $this->createToken($name, self::FAMILY_TOKEN_ABILITIES);
    }

    /**
     * Contacts have NO password column and no password-based guard, by design —
     * 200 families cannot be issued passwords and a school office cannot run a
     * reset desk (§1). Credentials are the mailbox, via the codes T-015d adds.
     *
     * Returning an empty string rather than inheriting the trait's
     * `$this->password` (which would be `null` here) is a fail-closed choice:
     * every hasher in the framework answers `check($plain, '')` with `false`
     * before doing any work, so if a password-driven guard is ever pointed at
     * the `contacts` provider by mistake, no credential can satisfy it.
     */
    public function getAuthPassword()
    {
        return '';
    }

    /**
     * There is no `remember_token` column on `contacts`, and no session guard
     * that would want one. Naming it null makes the trait's getter and setter
     * both no-ops (they check for an empty name first), so a stray
     * `setRememberToken()` cannot add an unmapped attribute that then blows up
     * the next `save()`.
     */
    public function getRememberTokenName()
    {
        return null;
    }

    /** Card last-4 records for this contact (historical lookup + placeholder merge). */
    public function cards()
    {
        return $this->hasMany(ContactCard::class);
    }

    /** Succeeded giving attributed to this contact (Stripe + offline alike). */
    public function donations()
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * This person's places in groups — their own leader/member rows AND the
     * guardian edges they hold over someone else. Additive: groups reference a
     * contact, they never duplicate one. See .claude/rules/groups.md.
     */
    public function groupMemberships()
    {
        return $this->hasMany(GroupMembership::class);
    }

    /** The groups this contact appears in, in any role. */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_memberships')
            ->withPivot(['role', 'guardian_of_contact_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * This person's credentials — a Community org's volunteer licenses,
     * background checks and certifications (T-023). Additive: credentials
     * reference a contact, they never duplicate one. See
     * .claude/rules/credentials.md.
     */
    public function credentials()
    {
        return $this->hasMany(ContactCredential::class);
    }
}
