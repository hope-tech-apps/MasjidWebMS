<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use App\Services\Broadcast\EmailSuppressionService;
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
     * The abilities stamped on a self-registered APP MEMBER's token.
     *
     * A third realm beside `['family']` and `['staff']`, named for the same
     * reason those are: the `personal_access_tokens` row must say which door a
     * credential came through, so a later slice can enforce `abilities:` without
     * a flag day for tokens already on people's phones.
     *
     * What actually keeps a member out of the family realm today is NOT this
     * string — it is that self-registration never sets `login_enabled_at`, so
     * `familyLoginIsActive()` is false and `family.active`
     * (EnsureFamilyLoginActive) refuses the token on every family route. A
     * member and a parent may be the same person and hold one contact; the
     * parent half of that person is granted by staff, never by signing up.
     */
    public const MEMBER_TOKEN_ABILITIES = ['member'];

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
     *   - `App\Services\Family\FamilyPasswordService` — `password` /
     *     `password_set_at`, added 2026-09-08. It is on this list for the same
     *     reason the others are: `password` is credentials-adjacent in the
     *     strongest possible sense, and leaving it out of $fillable means no
     *     request body — not a CRM update, not a roster import — can set a
     *     family's credential as a side effect of editing something else. The
     *     service writes it only for the contact the caller's OWN token names.
     *
     * If a fourth writer ever appears, the audit trail stops being complete —
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
        // The chosen avatar: a character, a skin tone and a hijab/kufi
        // colour. Three strings naming one of forty drawings the app ships,
        // never an upload — see App\Support\Avatar.
        'avatar_character',
        'avatar_tone',
        'avatar_color',
        // A STAFF override, laid on top of the child's own choice rather
        // than replacing it. Clearing these restores what the child picked.
        'staff_avatar_character',
        'staff_avatar_tone',
        'staff_avatar_color',
    ];

    /**
     * The credential never leaves the database, on ANY surface.
     *
     * This model IS serialized wholesale, unlike the family realm's hand-built
     * projections: `AdminDashboard\ContactsController` answers `->paginate()`
     * on index, `'data' => $contact` on store and update, and `$contact
     * ->toArray()` on show. Adding a `password` column without this line would
     * have put a parent's bcrypt hash into four staff-facing JSON responses on
     * the day the column landed.
     *
     * `password_set_at` is deliberately NOT hidden — "does this family have a
     * password?" is a legitimate thing for an admin screen to answer, and the
     * timestamp answers it without the hash.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_placeholder' => 'boolean',
            'login_enabled_at' => 'datetime',
            'login_revoked_at' => 'datetime',
            'last_login_at' => 'datetime',
            // NOT the `hashed` cast. The hash is written in exactly one place
            // (FamilyPasswordService) which calls Hash::make itself, and a cast
            // that silently re-hashes on assignment would make a double-hash
            // depend on how a value happened to be set.
            'password_set_at' => 'datetime',
            'verified_at' => 'datetime',
            'sms_opt_in' => 'boolean',
            'sms_consent_at' => 'datetime',
            'sms_opted_out_at' => 'datetime',
            // A DISPLAY MIRROR of email_suppressions (T-042c). Deliberately NOT
            // in $fillable: it is written in exactly two places — by
            // App\Services\Broadcast\EmailSuppressionService when the OPT-OUT
            // changes, and by this model's own hooks when the ADDRESS changes —
            // so no importer or admin payload can set it and imply an opt-out
            // that does not exist.
            'email_opted_out_at' => 'datetime',
        ];
    }

    /**
     * Does the directory believe this person has unsubscribed from this
     * organisation's broadcast emails?
     *
     * For DISPLAY only — a badge on the contact screen, so staff stop wondering
     * why somebody hears nothing. **Nothing may call this to decide whether to
     * send.** The authority is `email_suppressions`, consulted once in
     * App\Services\Broadcast\BroadcastAudienceResolver::emailAudience(), and it
     * is keyed on the ADDRESS with no foreign key here precisely because this
     * row is mortal: the merge path force-deletes it, the donation importer
     * mints and destroys placeholders, and a CSV re-import recreates people.
     * A column on a row like that can be wrong; the suppression row cannot.
     *
     * It is nonetheless kept true rather than left to drift — `syncEmailOptOutMirror`
     * below re-reads the durable list on every write that moves this row's
     * address — because a badge staff can see is a badge staff will believe.
     */
    public function hasEmailOptOut(): bool
    {
        return $this->email_opted_out_at !== null;
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

        /*
         * The email opt-out MIRROR follows the address, on every path that
         * changes one (T-042c).
         *
         * `email_opted_out_at` is a display copy of a row in
         * `email_suppressions`, which is keyed on the ADDRESS. Without this
         * hook the copy is only ever recomputed for the address being
         * suppressed or released, so it goes wrong in both directions the
         * moment somebody edits an email:
         *
         *  (a) a person unsubscribes at bob@x.com and an admin later corrects
         *      the address to robert@x.com. The badge still reads
         *      "unsubscribed" while broadcasts now go out to that person —
         *      staff read a badge that is the opposite of the truth;
         *  (b) an admin edits a contact onto an address that is ALREADY
         *      suppressed (an older row, a re-import, a merge survivor). No
         *      badge appears, yet `emailAudience()` silently drops them from
         *      every send — which is precisely the "why does this person hear
         *      nothing" question the column was added to answer.
         *
         * A display mirror that can silently disagree with the table that
         * decides is a defect waiting to be trusted, and the contact screen now
         * DOES trust it (ContactsView.vue renders the badge). So it is kept
         * true here, in the model, rather than in one controller: importers,
         * the merge path, seeders and console commands all save contacts too,
         * and a rule enforced in the model is the only one none of them can
         * skip.
         *
         * Two hooks rather than one, because Eloquent reports the two writes
         * differently. `created` covers the insert — a contact re-imported at
         * an address that already unsubscribed is precisely the case the
         * suppression table exists for, and on an INSERT no `changes` are synced
         * so `wasChanged()` would be false there. `updated` covers the edit, and
         * asks `wasChanged('email')` so the recompute costs one extra indexed
         * SELECT only on the writes that actually move an address. Neither is
         * `saving`: BelongsToMasjid stamps masjid_id from the `creating` hook,
         * which runs after `saving`, and this lookup is per-tenant.
         *
         * The authority is untouched: nothing here decides whether to send, and
         * clearing this column by hand still changes nothing about who is
         * emailed. Pinned by
         * `the_mirror_follows_the_address_when_a_contacts_email_is_edited`.
         */
        static::created(function (Contact $contact) {
            self::syncEmailOptOutMirror($contact, onInsert: true);
        });

        static::updated(function (Contact $contact) {
            if ($contact->wasChanged('email')) {
                self::syncEmailOptOutMirror($contact, onInsert: false);
            }
        });
    }

    /**
     * Copy the durable email opt-out for THIS row's current address onto the
     * display column, or clear it when the address is mailable.
     *
     * Written with a keyed query rather than `save()` for two reasons: it fires
     * no model events, so it cannot re-enter the hooks that call it; and inside
     * `created` the model's originals have not been synced yet, so a `save()`
     * there would rewrite every column instead of this one. The tenant scope is
     * lifted because the row's own key is the filter and this runs from unbound
     * code (the public unsubscribe landing) as well as bound.
     *
     * Note what is NOT done: the write is not skipped when the value in hand
     * already looks right. On an UPDATE the loaded attribute can be STALE —
     * EmailSuppressionService mirrors through its own freshly-fetched instances
     * of this same row, so an in-memory null routinely hides a date in the
     * database, which is precisely the case this hook exists for. On an INSERT
     * there is no such doubt: the column is not fillable, so it is null by
     * construction and a mailable address means there is nothing to write —
     * which keeps a bulk import to one extra SELECT per row rather than an
     * extra UPDATE as well.
     *
     * The date written is the date the opt-out BEGAN, so the badge and the
     * evidence can never quote different days.
     */
    private static function syncEmailOptOutMirror(Contact $contact, bool $onInsert): void
    {
        $at = app(EmailSuppressionService::class)
            ->suppressedAt((int) $contact->masjid_id, $contact->email);

        if ($onInsert && $at === null) {
            return;
        }

        // `withTrashed()` as well as the tenant bypass: the row's own key is the
        // filter, and a soft-deleted contact silently matching nothing would
        // leave a stale copy behind for whoever restores it.
        static::withoutMasjidScope()
            ->withTrashed()
            ->whereKey($contact->getKey())
            ->update(['email_opted_out_at' => $at]);

        $contact->forceFill(['email_opted_out_at' => $at])
            ->syncOriginalAttribute('email_opted_out_at');
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
     * ON EXPIRY — RESOLVED 2026-09-08, and still not by an `expiresAt` here.
     *
     * The problem was never this method. Sanctum builds every guard with the
     * single global `config('sanctum.expiration')` (480 minutes) and enforces it
     * against `created_at` inside `Laravel\Sanctum\Guard`, so a per-token
     * `expires_at` can only ever SHORTEN a token's life, never extend it past
     * the global. Raising the global would move staff sessions too, which
     * .claude/rules/auth-permissions.md forbids.
     *
     * The fix this docblock predicted — "a per-guard expiration, a change in how
     * the guard is constructed" — is now built: the `family` guard has its own
     * driver (AppServiceProvider::registerFamilyGuard) carrying
     * `config('family.session.expiration_minutes')`, 30 days by default. Staff
     * remain at 8 hours because their guard is untouched.
     *
     * So this method still mints a plain token with no expiry argument, and that
     * is correct: the LIFETIME is a property of the guard that reads it, not of
     * the token. Adding an `expiresAt` here would only be able to shorten it.
     */
    public function createFamilyToken(string $name = 'family-token'): NewAccessToken
    {
        return $this->createToken($name, self::FAMILY_TOKEN_ABILITIES);
    }

    /**
     * A HAND-OFF token: the parent passes the device to their child.
     *
     * ClassDojo's youngest students sign in "from your parent's account", which
     * is the only way a six-year-old with no email gets an account of their own.
     * This is that, made into a real boundary rather than a screen the child is
     * asked not to leave.
     *
     * The token is minted from the PARENT's contact — the server authenticated
     * them, not the child — but it carries ONLY `student:{membership}`. It
     * therefore fails `family` on every ordinary family route, so a child
     * holding the phone cannot open the parent's messages, another child's
     * record, or the class feed. The abilities constant was written to be inert
     * "so that a later slice can add abilities: enforcement to a route without a
     * flag day"; this is that slice.
     *
     * Short-lived on purpose. Sanctum enforces the global expiry against
     * `created_at`, and a per-token `expires_at` can only SHORTEN a life, never
     * extend it — so this is safe to set without touching how anyone else's
     * session ages.
     */
    public function createStudentHandoffToken(int $membershipId, int $minutes = 60): NewAccessToken
    {
        return $this->createToken(
            'student-handoff',
            [self::studentAbility($membershipId)],
            now()->addMinutes($minutes)
        );
    }

    public static function studentAbility(int $membershipId): string
    {
        return 'student:'.$membershipId;
    }

    /**
     * The password this contact CHOSE, or the empty string if they have not.
     *
     * ---------------------------------------------------------------------
     * THIS REVERSES A RECORDED DECISION — deliberately, and only halfway
     * ---------------------------------------------------------------------
     *
     * What stood here until 2026-09-08:
     *
     *     "Contacts have NO password column and no password-based guard, by
     *      design — 200 families cannot be issued passwords and a school office
     *      cannot run a reset desk (§1). Credentials are the mailbox, via the
     *      codes T-015d adds."
     *
     * Both of those reasons were about the OFFICE ISSUING credentials, and both
     * still hold. Nothing sets a password except the parent who owns it, while
     * already holding a token they got from their own mailbox; no password is
     * ever mailed; and there is still no reset desk — a parent who forgets
     * theirs signs in with a code, exactly as before. What changed is that a
     * parent who has already proved control of their mailbox may now choose not
     * to return to it every time. See FamilyPasswordService.
     *
     * ---------------------------------------------------------------------
     * The fail-closed choice is PRESERVED, not dropped
     * ---------------------------------------------------------------------
     *
     * The old body returned `''` unconditionally so that no credential could
     * ever satisfy a hasher pointed at this provider. That property is exactly
     * what a contact with no password still needs, so the coalesce keeps it:
     * `password` is NULL for every row that has not opted in — which is all of
     * them until a parent acts — and `Hash::check($any, '')` is false before any
     * work is done. An un-enrolled contact therefore cannot be signed in by ANY
     * password, including the empty string and including null.
     *
     * Read from `$this->attributes` rather than `$this->password` on purpose:
     * an accessor or a cast added to this model later must not be able to
     * change what the authentication layer compares against.
     */
    public function getAuthPassword()
    {
        return (string) ($this->attributes['password'] ?? '');
    }

    /**
     * Has this parent chosen a password? Never reads the hash.
     *
     * `password_set_at` is the flag on purpose — a screen that wants to say
     * "you have a password" must not have to touch the credential to find out,
     * and a NULL hash with a non-NULL timestamp (or the reverse) is a bug this
     * predicate makes visible rather than papering over.
     */
    public function hasFamilyPassword(): bool
    {
        return ($this->attributes['password'] ?? null) !== null
            && $this->password_set_at !== null;
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

    /**
     * The URL of this person's chosen avatar, or null when they have not chosen
     * one. Null is a real answer — the client draws initials — and is preferred
     * over a default image, which would show a child somebody else's face.
     */
    /**
     * The avatar columns, as an eager-load column list.
     *
     * Every `with('contact:id,first_name,...')` in this application must include
     * these or the appended `avatar` silently resolves to null — the accessor
     * reads columns the query never selected, and nothing errors. There were
     * twelve such lists when avatars were added, so the set lives HERE and the
     * call sites append it, rather than twelve places each remembering three
     * column names.
     */
    public const AVATAR_COLUMNS = 'avatar_character,avatar_tone,avatar_color,'
        .'staff_avatar_character,staff_avatar_tone,staff_avatar_color';

    /**
     * Serialized on every contact payload, so a face shows up wherever a person
     * does without each controller opting in. Null when unchosen — the client
     * draws initials.
     */
    protected $appends = ['avatar'];

    /**
     * What to DRAW for this person, and where it came from.
     *
     * A staff override wins when present — a teacher setting a recognisable
     * avatar for a roster is doing it for a reason. `source` says which is
     * showing, and `student_choice` carries the child's own even while
     * overridden, so a client can offer "restore" without a second request.
     */
    public function getAvatarAttribute(): ?array
    {
        $staff = $this->staffAvatarParts();
        $own = $this->studentAvatarParts();
        $effective = $staff ?? $own;

        if ($effective === null) {
            return null;
        }

        return $effective + [
            'source' => $staff !== null ? 'staff' : 'student',
            'student_choice' => $staff !== null ? $own : null,
        ];
    }

    /** The child's own choice, whether or not it is currently showing. */
    public function studentAvatarParts(): ?array
    {
        return $this->avatarParts($this->avatar_character, $this->avatar_tone, $this->avatar_color);
    }

    public function staffAvatarParts(): ?array
    {
        return $this->avatarParts(
            $this->staff_avatar_character,
            $this->staff_avatar_tone,
            $this->staff_avatar_color
        );
    }

    public function hasStaffAvatarOverride(): bool
    {
        return $this->staffAvatarParts() !== null;
    }

    private function avatarParts(?string $character, ?string $tone, ?string $color): ?array
    {
        $url = \App\Support\Avatar::imageUrl($character, $tone, $color);

        if ($url === null) {
            return null;
        }

        return ['character' => $character, 'tone' => $tone, 'color' => $color, 'url' => $url];
    }

    public function avatarUrl(): ?string
    {
        $effective = $this->staffAvatarParts() ?? $this->studentAvatarParts();

        return $effective['url'] ?? null;
    }


    /**
     * The services this member asked to hear about.
     *
     * Deliberately NOT `$fillable` anywhere near this: interests are written
     * only through App\Services\Member\MemberInterestService, so that every
     * change is the member's own and is diffed rather than replaced blind.
     */
    public function serviceInterests()
    {
        return $this->hasMany(ContactServiceInterest::class);
    }

    /** The Service rows themselves, for rendering a member's picks. */
    public function interestedServices()
    {
        return $this->belongsToMany(Service::class, 'contact_service_interests')
            ->withTimestamps();
    }

    /**
     * Did this contact create itself through an app, rather than being typed
     * by the office? NULL `signup_source` means staff-authored — every row that
     * predates app sign-up, and everything an admin creates from now on.
     */
    public function isSelfRegistered(): bool
    {
        return $this->signup_source === 'app';
    }

    /** Has somebody proved control of `login_email` by redeeming a code sent to it? */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Mint an app member's token.
     *
     * Carries the member realm's abilities and, like `createFamilyToken`, no
     * `expiresAt`: lifetime is a property of the guard that reads the token,
     * not of the token (see that method for why an `expiresAt` here could only
     * ever shorten it).
     */
    public function createMemberToken(string $name = 'member-token'): NewAccessToken
    {
        return $this->createToken($name, self::MEMBER_TOKEN_ABILITIES);
    }

    /**
     * May this contact use the member app right now?
     *
     * Deliberately a DIFFERENT predicate from `familyLoginIsActive()`:
     *
     *  - it turns on `verified_at` (the member proved control of the address)
     *    rather than `login_enabled_at` (the office granted portal access), so
     *    signing up for an app never quietly hands somebody a parent's view of
     *    a child's records;
     *  - it still honours `login_revoked_at`. An administrator who revoked a
     *    contact revoked the person, not one channel, and self-registration
     *    must never be a way to restore access staff took away.
     */
    public function memberAccessIsActive(): bool
    {
        return $this->verified_at !== null
            && $this->login_revoked_at === null
            && ! $this->trashed();
    }
}
