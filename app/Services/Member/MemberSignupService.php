<?php

namespace App\Services\Member;

use App\Mail\FamilyLoginCodeMail;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Family\FamilyPasswordService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

/**
 * Self-serve sign-up and sign-in for a tenant's app members.
 *
 * The mirror of App\Services\Family\FamilyLoginService, and deliberately shaped
 * like it — same digest, same TTL/attempt discipline, same silence — with one
 * inversion: the family realm sends a code only to somebody an administrator
 * already enabled, and this one sends a code to anybody who asks. That single
 * difference is the whole security problem here, and everything below is about
 * containing it.
 *
 * ---------------------------------------------------------------------------
 * THERE IS NO "REGISTER" — CREATING AN ACCOUNT IS A CODE WITH A PASSWORD
 * ---------------------------------------------------------------------------
 * A member submits an address and gets a code; redeeming it either matches an
 * existing contact or creates one. A distinct registration endpoint could not
 * avoid answering "is this address already known here?", and for a
 * congregation that question is about who attends.
 *
 * Since 2026-09-16 the redeem may carry a `password`, and that one addition is
 * both "Create an account" (a new address: name, password, code) and "Forgot
 * password?" (a known one: password, code). The password is written in the
 * transaction that burns the code, through FamilyPasswordService::set(), so a
 * refused redeem sets nothing. Both flows prove the mailbox first, so neither
 * says anything about an address to somebody who merely typed it. On a known
 * address the password REPLACES whatever that person had, in the app and the
 * parent portal alike, and ends their other sessions. The shared password is
 * the owner's choice (2026-09-16: "one password per person, shared with the
 * parent portal"); ending the other sessions is the sign-in contract's, via
 * FamilyPasswordService::set(), not a decision the owner stated.
 *
 * `attemptPassword()` is the third door: address + password, no code. It
 * resolves the contact through the same `resolveContact()` and
 * `mayHoldMemberAccess()` as the code door, and every failure is the same null.
 *
 * ---------------------------------------------------------------------------
 * ISSUING A CODE CREATES NO CONTACT
 * ---------------------------------------------------------------------------
 * `issue()` writes one `app_signup_codes` row and sends mail. The `contacts`
 * row is created only inside `redeem()`, in the transaction that burns the
 * code — so somebody spraying the request endpoint with a dictionary of
 * addresses fills a prunable table of expiring codes and never writes a person
 * into the CRM the office works in.
 *
 * ---------------------------------------------------------------------------
 * WHAT VERIFICATION DOES AND DOES NOT GRANT
 * ---------------------------------------------------------------------------
 * Redeeming sets `verified_at`. It NEVER sets `login_enabled_at`. That column
 * is the office granting portal access to a parent, and `family.active` gates
 * the whole family realm on it — so no amount of signing up reaches a child's
 * records. It also never clears `login_revoked_at`: a contact staff revoked
 * stays revoked, and self-registration is not a way back in.
 *
 * ---------------------------------------------------------------------------
 * MERGE, NEVER DUPLICATE — AND NEVER OVERWRITE
 * ---------------------------------------------------------------------------
 * An address the office already has must LINK to that contact, or the CRM fills
 * with shadow records of people staff already know. But the person proving the
 * address is a stranger until they prove it, so a link copies NOTHING from the
 * request: a submitted name is used only when creating a brand-new contact and
 * is discarded on a link. Otherwise anyone who could receive mail at a known
 * congregant's address could rename that congregant in the office's own CRM.
 *
 * The one thing a link does take from the request is a `password`, because it
 * is not a fact about the person the office recorded: it is the credential of
 * whoever reads the mailbox, and they have just proved they do. That is the
 * same authority the code itself grants, so it opens nothing the code did not
 * — for as long as the contact keeps this address. When the office later
 * enables the parent portal at a different address, or gives this address to
 * someone else, FamilyAccessService clears the password and `verified_at`, so
 * a password chosen through a household address never opens the portal (or
 * the app) at the parent's own one.
 *
 * An address matching two contacts is ambiguous and refused outright, exactly
 * as FamilyLoginService refuses it — guessing which person a credential belongs
 * to is the one thing an identity service must never do.
 *
 * ---------------------------------------------------------------------------
 * TENANT BINDING IS A PRECONDITION, NOT A DETAIL
 * ---------------------------------------------------------------------------
 * Every query here runs through the BelongsToMasjid global scope and is correct
 * ONLY when TenantContext is bound. The mobile API is unbound by default
 * (.claude/rules/tenant-scoping.md), so the routes carry a binding middleware.
 * Unbound, `resolveContact()` would search every masjid in the database and the
 * mailer would become a cross-tenant existence oracle — the trap
 * App\Http\Middleware\ResolveFamilyGuestTenant was written to close.
 */
class MemberSignupService
{
    public function __construct(
        private TenantContext $tenant,
        private FamilyPasswordService $passwords,
    ) {
    }

    /**
     * Send a fresh code to `$submittedEmail`. Always void.
     *
     * Void for the same reason FamilyLoginService::issue() is: a return value
     * is something a controller can branch on, and a controller that branches
     * is one edit away from disclosing whether an address is known here.
     */
    public function issue(string $submittedEmail, ?string $ip = null): void
    {
        $email = $this->normalise($submittedEmail);

        if ($email === '') {
            return;
        }

        // A contact that exists but was REVOKED gets no code. Sending one would
        // be a working credential for somebody staff deliberately cut off, and
        // redeem() would refuse it anyway — this just declines to send mail we
        // would not honour. Silent, like every other outcome.
        $existing = $this->resolveContact($email);
        if ($existing !== null && ! $this->mayHoldMemberAccess($existing)) {
            return;
        }

        $code = $this->generateCode();

        AppSignupCode::create([
            'email' => $email,
            'code_hash' => $this->hash($code),
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'requested_ip' => $ip,
        ]);

        $this->deliver($email, $existing, $code);
    }

    /**
     * Exchange an address + code for a member token, creating or linking the
     * contact. Null for every failure, with no reason attached, with one
     * exception that only a correct code can reach: see NewMemberNameRequired.
     *
     * `$firstName`/`$lastName` are used ONLY when a contact is created. On a
     * link they are discarded — see the class docblock.
     *
     * `$password`, when not null, becomes the contact's password — new or
     * linked alike — in the same transaction, before the token is minted. Its
     * strength is the caller's to check first (VerifyMemberCodeRequest applies
     * SetFamilyPasswordRequest::strength()); by the time it reaches here, a
     * refusal about it would come after the code was spent.
     *
     * @return array{contact: Contact, token: NewAccessToken, created: bool}|null
     *
     * @throws NewMemberNameRequired when the code matched and was unconsumed, the
     *   address would create a contact, and a name is blank. The code is NOT
     *   consumed and no password is set.
     */
    public function redeem(
        string $submittedEmail,
        string $submittedCode,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $ip = null,
        #[\SensitiveParameter] ?string $password = null,
    ): ?array {
        $email = $this->normalise($submittedEmail);

        if ($email === '') {
            return null;
        }

        $row = $this->matchLiveCode($email, $this->hash($submittedCode));

        return $row === null ? null : $this->consume($row, $email, $firstName, $lastName, $password, $ip);
    }

    /**
     * Exchange an address + password for a member token, or null.
     *
     * Null for EVERY failure, and the controller answers all of them with the
     * one `verify-code` 410: an unknown address, a wrong password, no password
     * chosen, a contact that never proved the address here (`verified_at`), a
     * revoked or deleted contact, an address matching two contacts, and a
     * password that belongs to a different address than the one submitted.
     * Confirming that an address has an account here is a disclosure about who
     * attends, and at a school, about a child.
     *
     * Every one of those costs one hash comparison (FamilyPasswordService::
     * hashOrBurn), so the time taken does not separate them either.
     *
     * The token is the same kind `redeem()` mints, named for the same fact: the
     * contact's `login_email` is the address submitted, which the check below
     * makes true rather than assumed.
     *
     * @return array{contact: Contact, token: NewAccessToken, created: bool}|null
     */
    public function attemptPassword(
        string $submittedEmail,
        #[\SensitiveParameter] string $submittedPassword,
        ?string $ip = null,
    ): ?array {
        $email = $this->normalise($submittedEmail);

        // The SAME resolver and gate as the code door: bound tenant,
        // case-insensitive, two rows are no row, revoked and trashed refused.
        $contact = $email === '' ? null : $this->resolveContact($email);

        if ($contact !== null && ! $this->mayUsePassword($contact, $email)) {
            $contact = null;
        }

        // Refused contacts are passed as null, so they are compared against the
        // decoy and cost what a real comparison costs.
        if (! $this->passwords->hashOrBurn($contact, $submittedPassword)) {
            return null;
        }

        /** @var Contact $contact */
        $contact->forceFill(['last_login_at' => now()])->save();

        return [
            'contact' => $contact,
            'token' => $contact->createMemberToken(Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL),
            'created' => false,
        ];
    }

    /**
     * Mail a code that confirms DELETING the account at this address: the first
     * step of the public /account-deletion page. Always void.
     *
     * Unlike `issue()`, it does not look for a contact at all, and it mails every
     * address, revoked contacts included. The page must answer the same way for
     * an address with an account and one without, and a send on only one path
     * would put that answer back into the time the request takes. Deleting takes
     * a login away and grants nothing, so there is nobody to withhold it from.
     *
     * The row goes in `app_signup_codes` with the same TTL, attempt cap and
     * single use as a sign-in code. What keeps the two apart is the digest: the
     * purpose is inside the HMAC (see `hash()`), so this code can never sign
     * anybody in and a sign-in code can never confirm a deletion. A wrong guess
     * at either door still charges every live code for the address.
     */
    public function issueAccountDeletionCode(string $submittedEmail, ?string $ip = null): void
    {
        $email = $this->normalise($submittedEmail);

        if ($email === '') {
            return;
        }

        $code = $this->generateCode();

        AppSignupCode::create([
            'email' => $email,
            'code_hash' => $this->hash($code, FamilyLoginCodeMail::PURPOSE_ACCOUNT_DELETION),
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'requested_ip' => $ip,
        ]);

        // No recipient name: that would need a contact lookup, and the mail
        // would then differ between an address with an account and one without.
        $this->deliver($email, null, $code, FamilyLoginCodeMail::PURPOSE_ACCOUNT_DELETION);
    }

    /**
     * Spend a deletion code. True only when this exact code was live for this
     * address in the bound organisation and this call consumed it.
     */
    public function redeemAccountDeletionCode(string $submittedEmail, string $submittedCode): bool
    {
        $email = $this->normalise($submittedEmail);

        if ($email === '') {
            return false;
        }

        $row = $this->matchLiveCode(
            $email,
            $this->hash($submittedCode, FamilyLoginCodeMail::PURPOSE_ACCOUNT_DELETION),
        );

        if ($row === null) {
            return false;
        }

        // The same compare-and-swap `consume()` starts with: a double-submit
        // spends the code once.
        return AppSignupCode::query()
            ->whereKey($row->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]) === 1;
    }

    /**
     * The live code row whose digest matches `$candidate`, or null, charging a
     * guess to every live code for the address on a miss.
     */
    private function matchLiveCode(string $email, string $candidate): ?AppSignupCode
    {
        // Newest first: somebody who requested twice types the code from the
        // most recent mail. A second request does not kill the first.
        $live = AppSignupCode::query()
            ->where('email', $email)
            ->redeemable()
            ->orderByDesc('id')
            ->get();

        foreach ($live as $row) {
            if (hash_equals((string) $row->code_hash, $candidate)) {
                return $row;
            }
        }

        // A WRONG code charges every live code for this address, not just the
        // newest — otherwise requesting twenty codes buys a hundred guesses
        // against the one actually under attack. Identical reasoning, and
        // identical effect, to FamilyLoginService.
        if ($live->isNotEmpty()) {
            AppSignupCode::query()
                ->whereIn('id', $live->pluck('id')->all())
                ->increment('attempts');
        }

        return null;
    }

    /**
     * Burn the code and produce the identity, atomically.
     *
     * The contact is created INSIDE this transaction so that a code which loses
     * the race to a concurrent redeem creates nobody: the conditional UPDATE is
     * the gate, and it runs first.
     *
     * @return array{contact: Contact, token: NewAccessToken, created: bool}|null
     *
     * @throws NewMemberNameRequired rolled back, so the code survives.
     */
    private function consume(
        AppSignupCode $row,
        string $email,
        ?string $firstName,
        ?string $lastName,
        #[\SensitiveParameter] ?string $password,
        ?string $ip,
    ): ?array {
        return DB::transaction(function () use ($row, $email, $firstName, $lastName, $password, $ip): ?array {
            $affected = AppSignupCode::query()
                ->whereKey($row->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            // Another request consumed this row between the SELECT and here.
            // The replay arriving concurrently must lose exactly like one
            // arriving a second later.
            if ($affected === 0) {
                return null;
            }

            $contact = $this->resolveContact($email);
            $created = false;

            if ($contact === null) {
                // Names are required by the schema and are the ONLY thing a
                // brand-new contact takes from the request. Absent, we refuse
                // rather than invent a placeholder that staff would then have
                // to clean out of their CRM.
                $first = trim((string) $firstName);
                $last = trim((string) $lastName);

                if ($first === '' || $last === '') {
                    // Thrown, not returned: DB::transaction rolls back on the
                    // way out, undoing the consumed_at the gate just wrote, so
                    // the member can resend this same code with their name.
                    // Only a caller whose code matched AND won the gate gets
                    // here, which is what makes saying so safe. Every earlier
                    // refusal (wrong, expired, replayed, locked out, a lost
                    // race) is still the silent null above this line.
                    throw NewMemberNameRequired::fromBlank($first === '', $last === '');
                }

                $contact = new Contact();
                $contact->forceFill([
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'login_email' => $email,
                    'signup_source' => 'app',
                    'verified_at' => now(),
                ]);
                // masjid_id is stamped by the BelongsToMasjid creating hook from
                // the bound tenant, never from the request.
                $contact->save();
                $created = true;
            } else {
                if (! $this->mayHoldMemberAccess($contact)) {
                    return null;
                }

                // A LINK. Nothing from the request is copied onto a contact the
                // office already owns — not the name, not the email. The only
                // writes are the two facts this exchange actually established:
                // that the address is theirs, and that they just used it (and,
                // below, a password the mailbox's owner chose for themselves).
                $updates = ['verified_at' => now(), 'last_login_at' => now()];

                // Adopt login_email only when the match came from the office's
                // `email` column and no login address is set yet, so the
                // contact has a stable identity for the next sign-in.
                $adopted = $contact->login_email === null;

                if ($adopted) {
                    $updates['login_email'] = $email;
                }

                $contact->forceFill($updates)->save();

                // A password on a contact with no login address was chosen
                // under an address it no longer has (FamilyAccessService
                // clears it when an address is released, so only an older row
                // can carry one). Adopting this address must not make it this
                // address's password: nobody proved this mailbox when it was
                // chosen. A request that brings its own password replaces it
                // below instead.
                if ($adopted && $password === null) {
                    $this->passwords->clear($contact, $ip);
                }
            }

            // Create-account and forgot-password. Only a proven mailbox gets
            // here, after the name check, so a refused redeem sets nothing.
            // `set()` with no current token ends EVERY session the contact
            // holds (member, family and hand-off), which is what a reset is
            // for; the token minted below is the only live one afterwards.
            // Its "Your password was set" email waits for THIS transaction to
            // commit (PasswordSetNotice), so it goes out only once the code is
            // spent for good, and never if anything below throws.
            if ($password !== null) {
                $this->passwords->set($contact, $password, '', $ip);
            }

            return [
                'contact' => $contact,
                // Named for what this exchange proved: after resolveContact(), the
                // redeemed address IS this contact's login_email. Account deletion
                // relies on that (Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL).
                'token' => $contact->createMemberToken(Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL),
                'created' => $created,
            ];
        });
    }

    /**
     * The one contact this address names in the bound tenant, or null.
     *
     * `login_email` wins over `email`: it is the address somebody chose as a
     * credential, whereas `email` is the office's contact data. Either way an
     * address matching more than one contact resolves to null — an identity
     * service must not guess which person a credential belongs to.
     *
     * The `email` fallback skips a contact that already HAS a `login_email`
     * (necessarily a different address, or the first query would have found it).
     * That column is a person's own credential, and `email` is frequently a
     * household address both parents read (see the login-columns migration).
     * Linking the household address would sign its other reader in AS that
     * parent: their gifts on "Your monthly giving", and a Delete account that
     * ends the family login the office gave the other parent. Such an address is
     * treated like any unmatched one, so a new member gives their name and gets a
     * contact of their own. The invariant this buys: a member token's contact
     * always has the redeemed address as its `login_email`.
     */
    private function resolveContact(string $email): ?Contact
    {
        $byLogin = Contact::query()
            ->whereNotNull('login_email')
            ->whereRaw('LOWER(login_email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($byLogin->count() === 1) {
            return $byLogin->first();
        }

        if ($byLogin->count() > 1) {
            return null;
        }

        $byEmail = Contact::query()
            ->whereNotNull('email')
            ->whereNull('login_email')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->get();

        return $byEmail->count() === 1 ? $byEmail->first() : null;
    }

    /**
     * May this contact sign in to the app with a password, for this address?
     *
     * The code door's gate, plus what a password cannot prove on its own:
     *
     *  - `verified_at`: the person has proved this mailbox to the app before. A
     *    contact that never did holds no member access (`member.active` would
     *    refuse the token on every route anyway); a parent-portal password on
     *    such a contact is used here only after "Forgot password?" proves it.
     *    It speaks for the CURRENT `login_email` only because FamilyAccessService
     *    clears it, with the password, whenever the office moves the login to
     *    another address or releases the address to someone else.
     *  - a password was chosen (`hasFamilyPassword()`, the one password both
     *    realms share).
     *  - the password belongs to THIS address. `resolveContact()` also matches
     *    the office's `email` column when a contact has no `login_email`, which
     *    is how a code sign-in links an office record. A password is a
     *    credential for `login_email` only, and a household address both
     *    parents read must not open it. The resolver only falls back to
     *    `email` for a contact whose `login_email` is NULL, so today the
     *    non-null test decides it and the equality cannot fail; it is kept so
     *    that a change to the resolver cannot quietly widen this door.
     */
    private function mayUsePassword(Contact $contact, string $email): bool
    {
        return $this->mayHoldMemberAccess($contact)
            && $contact->verified_at !== null
            && $contact->hasFamilyPassword()
            && $contact->login_email !== null
            && $this->normalise($contact->login_email) === $email;
    }

    /**
     * A contact staff revoked, or soft-deleted, can never be signed into.
     * Note this is NOT `memberAccessIsActive()`: that asks whether an ALREADY
     * verified member may act, and this asks whether this exchange is allowed
     * to verify them in the first place.
     */
    private function mayHoldMemberAccess(Contact $contact): bool
    {
        return $contact->login_revoked_at === null && ! $contact->trashed();
    }

    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function generateCode(): string
    {
        $length = max(4, (int) config('member.signup.code_length', 6));

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * HMAC over app.key, matching the family realm. There is no stored code.
     *
     * A sign-in code's digest is exactly what it always was, so codes already in
     * people's inboxes still redeem. Any other purpose is written INTO the MAC,
     * so one purpose's code never matches another's row.
     */
    private function hash(string $code, string $purpose = FamilyLoginCodeMail::PURPOSE_SIGN_IN): string
    {
        $message = $purpose === FamilyLoginCodeMail::PURPOSE_SIGN_IN ? $code : $purpose . '|' . $code;

        return hash_hmac('sha256', $message, (string) config('app.key'));
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('member.signup.code_ttl_minutes', 10));
    }

    /**
     * Sent INLINE and unqueued, reusing FamilyLoginCodeMail.
     *
     * Reused rather than copied precisely because that class is the one
     * Mailable in this application that is deliberately NOT `ShouldQueue` —
     * QUEUE_CONNECTION is `database`, so queueing it would write the plaintext
     * code into `jobs.payload` and into `failed_jobs` on a relay outage. A
     * second, parallel mailable is a second chance for somebody to add
     * `implements ShouldQueue` to the copy. Its subject ("Your sign-in code")
     * and body are already organisation-neutral.
     *
     * The same TIMING ORACLE that class documents applies here and is WEAKER in
     * this realm: a stranger's address gets a code too, so the send happens on
     * both paths and the wall clock no longer separates "known here" from
     * "unknown". The remaining difference is the revoked-contact path, which
     * returns without sending.
     */
    private function deliver(
        string $email,
        ?Contact $contact,
        string $code,
        string $purpose = FamilyLoginCodeMail::PURPOSE_SIGN_IN,
    ): void {
        try {
            // Masjid is the tenant itself and carries no BelongsToMasjid scope,
            // so this is a plain lookup by the bound id.
            $masjid = Masjid::find($this->tenant->get());

            Mail::to($email)->send(new FamilyLoginCodeMail(
                orgName: $masjid?->name ?? config('app.name'),
                code: $code,
                expiresInMinutes: $this->ttlMinutes(),
                recipientName: $contact?->first_name,
                orgEmail: $masjid?->email,
                purpose: $purpose,
            ));
        } catch (Throwable $e) {
            // Never surfaced. A relay outage must look exactly like every other
            // outcome of issue().
            Log::warning('member signup code delivery failed', [
                'masjid_id' => $this->tenant->get(),
                'exception' => $e::class,
            ]);
        }
    }
}
