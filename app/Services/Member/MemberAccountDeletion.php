<?php

namespace App\Services\Member;

use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\ContactLoginCode;
use App\Models\ContactLoginEvent;
use App\Models\ContactServiceInterest;
use App\Models\MobileAppUser;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Delete account" for an app member. The ONE implementation, used by the app
 * (`DELETE /api/mobile/masjids/{id}/me`) and by the public page at
 * `/account-deletion`, so the two doors cannot disagree about what deleting
 * means.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES (owner decision, 2026-09-14: "remove login, keep office records")
 * ---------------------------------------------------------------------------
 * Always, for the one contact:
 *
 *  - every token the contact holds is deleted (member, family and hand-off
 *    tokens alike), so every phone is signed out on its next request;
 *  - every handset the contact claimed is released (`mobile_app_users.contact_id`
 *    back to NULL). The device rows stay: the phone still exists and still gets
 *    `everyone` broadcasts, it has just stopped being this person's;
 *  - their service interests are deleted, so no service broadcast routes to them;
 *  - outstanding sign-in codes for the address are deleted, so a code requested a
 *    minute ago cannot sign straight back in;
 *  - the login itself is cleared: `verified_at`, `login_enabled_at` and the
 *    password, if one was set (one password, shared by the app and the parent
 *    portal).
 *
 * One exception, for a family login that is provably SOMEBODY ELSE's: when the
 * caller proved an address OTHER than `login_email` (see `delete()`), the family
 * login and its family and hand-off tokens stay, and only the app account goes.
 * Neither door passes such an address today.
 *
 * Then ONE of two outcomes:
 *
 *  - ERASED: the contact is hard-deleted, but only when app sign-up created it
 *    (`signup_source = 'app'`, the only value MemberSignupService writes) AND the
 *    office holds nothing about the person. See `reasonsToKeep()`.
 *  - LOGIN REMOVED: anything else. The office's record stays exactly as the
 *    office wrote it, and only the login goes.
 *
 * ---------------------------------------------------------------------------
 * WHY KEEPING IS THE DEFAULT
 * ---------------------------------------------------------------------------
 * `contacts` is the CRM staff work in. Several foreign keys CASCADE from it
 * (group memberships, registrants, credentials, cards), and several that do not
 * cascade still carry money or a child's record (donations, receipts through
 * donations, registrations). A member pressing a button in an app must never be
 * able to delete a gift history, a guardian edge or a class roster row, so the
 * contact row is erased only when there is provably nothing hanging off it. A
 * doubt keeps the row.
 *
 * `MemberAccountDeletionCoverageTest` walks the schema: a migration that adds a
 * contact column, or a `contact_id` to any table, fails the suite until it is
 * classified in one of the lists below.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES NOT DO
 * ---------------------------------------------------------------------------
 *  - It cancels no recurring gift and touches no money row. A donor with a live
 *    monthly gift keeps it, and their contact is kept (a gift is office data).
 *  - It does not set `login_revoked_at`. That is the office cutting someone off,
 *    and it would stop the person from ever signing up again.
 *  - It leaves `login_email` on a kept contact, so the office can see which
 *    address used to sign in and can turn a family login back on.
 *  - It does not touch another organisation. Contact ids are global keys, so
 *    every write below is keyed on this contact's id; the same person at
 *    another organisation is another contact.
 */
class MemberAccountDeletion
{
    public const OUTCOME_ERASED = 'erased';
    public const OUTCOME_LOGIN_REMOVED = 'login_removed';

    public const VIA_APP = 'app';
    public const VIA_WEB = 'web';

    /**
     * Tables whose rows about a person are the OFFICE's records. Any row here
     * keeps the contact. `table => [columns holding a contacts.id]`.
     */
    public const OFFICE_RECORDS = [
        'contact_cards' => ['contact_id'],
        'contact_credentials' => ['contact_id'],
        // Written by staff acts on a family login (enable, revoke, merge,
        // address moves) and by a person with a family login setting a
        // password. Erasing the contact would strip the subject from the
        // office's access history. An app member with no family login writes
        // no row here when they choose a password (FamilyPasswordService::set).
        'contact_login_events' => ['contact_id'],
        'donations' => ['contact_id'],
        'donation_subscriptions' => ['contact_id'],
        // Participant, leader AND guardian edges: `guardian_of_contact_id` is the
        // ward, and a ward's record is office data about them too.
        'group_memberships' => ['contact_id', 'guardian_of_contact_id'],
        'group_messages' => ['author_contact_id'],
        // A parent's 🤲/👍/💯/❓ in a class conversation — part of that
        // conversation's record, classified like the read bookmark beside it.
        // Only a guardian can write one, and a guardian's edge already keeps
        // the contact, so this changes no outcome.
        'group_message_reactions' => ['contact_id'],
        'group_thread_reads' => ['contact_id'],
        'group_threads' => ['created_by_contact_id'],
        'meal_orders' => ['contact_id'],
        'registrants' => ['contact_id'],
        'registrations' => ['contact_id'],
    ];

    /**
     * Tables that hold a contact id but are the LOGIN's plumbing, which this
     * service clears itself. They never keep a contact.
     */
    public const LOGIN_RECORDS = [
        'contact_login_codes' => ['contact_id'],
        'contact_service_interests' => ['contact_id'],
        'mobile_app_users' => ['contact_id'],
    ];

    /**
     * Office records that name a person by ADDRESS rather than by contact id —
     * a form response or an appointment request from the same mailbox, in the
     * same organisation. `table => address column`.
     */
    public const OFFICE_RECORDS_BY_ADDRESS = [
        'form_responses' => 'respondent_email',
        'appointment_requests' => 'email',
    ];

    /**
     * `contacts` columns that app sign-up itself writes, or that the system
     * maintains. Their values say nothing about the office knowing the person.
     * (`email` is here, but an `email` that differs from `login_email` means
     * somebody edited it, which `reasonsToKeep()` treats as office data.)
     */
    public const SIGNUP_COLUMNS = [
        'id',
        'masjid_id',
        'first_name',
        'last_name',
        'email',
        'login_email',
        'signup_source',
        'verified_at',
        'last_login_at',
        'created_at',
        'updated_at',
        'deleted_at',
        // A display mirror of the person's OWN broadcast unsubscribe. The opt-out
        // itself is keyed on the address and survives the contact. Named through
        // the Contact model, never spelled out here: this file only classifies
        // the column and must never consult the opt-out (EmailUnsubscribeTest).
        \App\Models\Contact::EMAIL_OPT_OUT_MIRROR_COLUMN,
        // Since 2026-09-16 "Create an account" in the app sets a password, so
        // nearly every account the app creates has one. Until then only a
        // parent with an office-granted family login could, and these two sat
        // in OFFICE_COLUMNS. Moving them loses no evidence: a family login
        // leaves `login_enabled_at` (or `login_revoked_at`) and an `enabled`
        // row in `contact_login_events`, and each of those still keeps the
        // contact. Left in OFFICE_COLUMNS, they would keep every new account,
        // and deleting one would never erase it.
        'password',
        'password_set_at',
    ];

    /**
     * `contacts` columns only the office (or a login the office granted) fills.
     * Any of them holding a value keeps the contact.
     */
    public const OFFICE_COLUMNS = [
        'phone',
        'notes',
        'is_placeholder',
        'import_batch',
        'sms_opt_in',
        'sms_consent_at',
        'sms_consent_source',
        'sms_consent_evidence',
        'sms_opted_out_at',
        'login_enabled_at',
        'login_revoked_at',
        // A child's own avatar is chosen inside the family portal, which only a
        // staff-granted login reaches; the staff override is staff's.
        'avatar_character',
        'avatar_tone',
        'avatar_color',
        'staff_avatar_character',
        'staff_avatar_tone',
        'staff_avatar_color',
    ];

    public function __construct(private TenantContext $tenant)
    {
    }

    /**
     * Delete the account of the contact a token authenticated.
     *
     * `$provenAddress` is the address the caller proved they read: the page's
     * emailed code, or the app sign-in a member token came from. Null means
     * "not known" (a member token minted before sign-in recorded it, see
     * Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL).
     *
     * It decides one thing: whether the office-granted FAMILY login goes too. The
     * owner's rule (2026-09-14) is that it does: its password, its sign-in codes
     * and every family and hand-off token go with the app account. The one
     * exception is a caller that proved an address OTHER than `login_email`,
     * such as a household `email` another person also reads. That login belongs
     * to whoever reads `login_email`, so only the APP account goes (member
     * tokens, handsets, interests, `verified_at`) and the other person's portal
     * access stays exactly as the office set it. Neither door passes such an
     * address today: the app passes `login_email` or null, and the page matches
     * `login_email` only.
     *
     * Null (unknown) follows the rule as written. Fix round 1 kept the family
     * login for it, which the owner had not agreed to (DECISIONS.md, 2026-09-14
     * fix round 2).
     *
     * @return array{outcome: string, kept_because: list<string>, devices_released: int, tokens_revoked: int, interests_removed: int, family_login_kept: bool}
     */
    public function delete(Contact $contact, string $via, ?string $ip = null, ?string $provenAddress = null): array
    {
        $result = DB::transaction(function () use ($contact, $ip, $provenAddress): array {
            // Re-read under a lock: a second tap, or the page and the app at the
            // same moment, must see the first deletion's result, not race it.
            /** @var Contact $contact */
            $contact = Contact::withoutMasjidScope()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Decided BEFORE anything is cleared: clearing `login_enabled_at`
            // below would otherwise erase the evidence that the office granted
            // this person a family login.
            $keptBecause = $this->reasonsToKeep($contact);
            $hadFamilyLogin = $contact->login_enabled_at !== null;
            $address = $this->normalise($contact->login_email);
            $officeEmail = $this->normalise($contact->email);
            $proven = $provenAddress !== null
                ? $this->normalise($provenAddress)
                // Unknown: only `login_email` could have been proved when the
                // contact has no other address to prove.
                : (($officeEmail === null || $officeEmail === $address) ? $address : null);
            // The owner's rule ends the family login. Only a caller that PROVED a
            // different address keeps it; unknown is not that exception.
            $endsFamilyLogin = ! $hadFamilyLogin
                || $provenAddress === null
                || ($proven !== null && $proven === $address);

            // MobileAppUser is not tenant-scoped; the contact id is the filter.
            $devices = MobileAppUser::query()
                ->where('contact_id', $contact->id)
                ->update(['contact_id' => null]);

            if ($endsFamilyLogin) {
                $tokens = $contact->tokens()->delete();

                ContactLoginCode::withoutMasjidScope()
                    ->where('contact_id', $contact->id)
                    ->delete();
            } else {
                // Only the app's own tokens. Abilities are a JSON column, so they
                // are read rather than matched in SQL, which SQLite and MySQL
                // spell differently.
                $memberTokenIds = $contact->tokens()
                    ->get(['id', 'abilities'])
                    ->filter(fn ($token) => in_array(Contact::MEMBER_TOKEN_ABILITIES[0], (array) $token->abilities, true))
                    ->pluck('id')
                    ->all();

                $tokens = $memberTokenIds === []
                    ? 0
                    : $contact->tokens()->whereKey($memberTokenIds)->delete();
            }

            $interests = ContactServiceInterest::withoutMasjidScope()
                ->where('contact_id', $contact->id)
                ->delete();

            // App sign-in codes for whichever address could sign straight back in:
            // the proven one, or both when that is unknown.
            $codeAddresses = $proven !== null ? [$proven] : array_values(array_unique(array_filter([$address, $officeEmail])));

            if ($codeAddresses !== []) {
                AppSignupCode::withoutMasjidScope()
                    ->where('masjid_id', $contact->masjid_id)
                    ->whereIn('email', $codeAddresses)
                    ->delete();
            }

            if ($keptBecause === []) {
                // Through the model, so Contact's own force-delete hook runs.
                // Never reached with a family login still on: `login_enabled_at`
                // is itself a reason to keep.
                $contact->forceDelete();

                return [
                    'outcome' => self::OUTCOME_ERASED,
                    'kept_because' => [],
                    'devices_released' => (int) $devices,
                    'tokens_revoked' => (int) $tokens,
                    'interests_removed' => (int) $interests,
                    'family_login_kept' => false,
                    'masjid_id' => (int) $contact->masjid_id,
                    'contact_id' => (int) $contact->id,
                ];
            }

            $contact->forceFill($endsFamilyLogin
                ? [
                    'verified_at' => null,
                    'login_enabled_at' => null,
                    'password' => null,
                    'password_set_at' => null,
                ]
                : ['verified_at' => null])->save();

            // A family login is on the office's access-history panel, so its
            // withdrawal goes there too. `revoked` with no actor: the panel reads
            // an empty actor as "not an operator", which is who did this. No
            // new verb, because the admin screen badges an unknown one "Enabled".
            if ($hadFamilyLogin && $endsFamilyLogin) {
                ContactLoginEvent::create([
                    'masjid_id' => $contact->masjid_id,
                    'contact_id' => $contact->id,
                    'action' => ContactLoginEvent::ACTION_REVOKED,
                    'login_email' => $contact->login_email,
                    'actor_user_id' => null,
                    'actor_name' => null,
                    'actor_email' => null,
                    'actor_ip' => $ip,
                ]);
            }

            return [
                'outcome' => self::OUTCOME_LOGIN_REMOVED,
                'kept_because' => $keptBecause,
                'devices_released' => (int) $devices,
                'tokens_revoked' => (int) $tokens,
                'interests_removed' => (int) $interests,
                'family_login_kept' => ! $endsFamilyLogin,
                'masjid_id' => (int) $contact->masjid_id,
                'contact_id' => (int) $contact->id,
            ];
        });

        // Warning, not info: production runs LOG_LEVEL=warning, and "a member
        // deleted their account" is exactly what the office will ask about. No
        // address in the context: the log is not a place to keep one.
        Log::warning('Member account deleted', [
            'masjid_id' => $result['masjid_id'],
            'contact_id' => $result['contact_id'],
            'via' => $via,
            'outcome' => $result['outcome'],
            'kept_because' => $result['kept_because'],
            'devices_released' => $result['devices_released'],
            'tokens_revoked' => $result['tokens_revoked'],
            'interests_removed' => $result['interests_removed'],
            'family_login_kept' => $result['family_login_kept'],
        ]);

        unset($result['masjid_id'], $result['contact_id']);

        return $result;
    }

    /**
     * Delete the account signed in with `$submittedEmail` in the BOUND
     * organisation, or null when there is no such account.
     *
     * Only for a caller who has already proved control of the address (the
     * public page's emailed code). An account is a contact whose `login_email`
     * is this address and whose login is on in either realm; a contact the
     * office merely has an `email` for has no account to delete.
     *
     * Deliberately NOT also matched on `email`. Sign-in no longer links a
     * household `email` to a contact whose `login_email` is someone else's
     * (MemberSignupService::resolveContact), so a member's address is always
     * their `login_email`. Matching `email` here would let the other reader of a
     * household mailbox sign that parent out of the app. The one member this
     * misses holds a token from before that change; it expires within the family
     * guard's 30 days, and Delete account in the app still works for them.
     *
     * @return array{outcome: string, kept_because: list<string>, devices_released: int, tokens_revoked: int, interests_removed: int}|null
     */
    public function deleteByAddress(string $submittedEmail, string $via, ?string $ip = null): ?array
    {
        // Unbound means NO tenant filter (.claude/rules/tenant-scoping.md): the
        // lookup below would search every organisation. Refuse instead.
        if (! $this->tenant->hasTenant()) {
            return null;
        }

        $email = mb_strtolower(trim($submittedEmail));

        if ($email === '') {
            return null;
        }

        $matches = Contact::query()
            ->whereNotNull('login_email')
            ->whereRaw('LOWER(login_email) = ?', [$email])
            ->limit(2)
            ->get();

        // Two contacts on one address is ambiguous, and an identity service must
        // not guess which person to delete.
        if ($matches->count() !== 1) {
            return null;
        }

        /** @var Contact $contact */
        $contact = $matches->first();

        if ($contact->verified_at === null && $contact->login_enabled_at === null) {
            return null;
        }

        return $this->delete($contact, $via, $ip, $email);
    }

    /** Lower-cased and trimmed, with an empty address read as none. */
    private function normalise(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $address = mb_strtolower(trim($address));

        return $address === '' ? null : $address;
    }

    /**
     * Why this contact must be kept, as stable keys (`contacts.phone`,
     * `donations`, `form_responses`...). Empty means it may be erased.
     *
     * @return list<string>
     */
    public function reasonsToKeep(Contact $contact): array
    {
        $reasons = [];

        if ($contact->signup_source !== 'app') {
            $reasons[] = 'contacts.signup_source';
        }

        foreach (self::OFFICE_COLUMNS as $column) {
            $value = $contact->getRawOriginal($column);

            if ($value !== null && $value !== '' && $value !== false && $value !== 0 && $value !== '0') {
                $reasons[] = 'contacts.' . $column;
            }
        }

        $login = $contact->login_email !== null ? mb_strtolower(trim($contact->login_email)) : null;
        $email = $contact->email !== null ? mb_strtolower(trim($contact->email)) : null;

        if ($email !== null && $email !== '' && $email !== $login) {
            $reasons[] = 'contacts.email';
        }

        // Plain table reads, not models: a soft-deleted gift or an ex-member's
        // roster row is still the office's record, and model scopes would hide
        // both. Every query is keyed on this contact's id.
        foreach (self::OFFICE_RECORDS as $table => $columns) {
            $held = DB::table($table)
                ->where(function ($query) use ($columns, $contact) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $contact->id);
                    }
                })
                ->exists();

            if ($held) {
                $reasons[] = $table;
            }
        }

        $addresses = array_values(array_unique(array_filter([$login, $email])));

        if ($addresses !== []) {
            foreach (self::OFFICE_RECORDS_BY_ADDRESS as $table => $column) {
                $held = DB::table($table)
                    ->where('masjid_id', $contact->masjid_id)
                    ->whereNotNull($column)
                    ->whereIn(DB::raw('LOWER(' . $column . ')'), $addresses)
                    ->exists();

                if ($held) {
                    $reasons[] = $table;
                }
            }
        }

        // Staff chose this person as a broadcast recipient by id. The snapshot
        // is JSON with no foreign key, so it is matched in PHP.
        $named = DB::table('broadcasts')
            ->where('masjid_id', $contact->masjid_id)
            ->whereNotNull('audience_contact_ids')
            ->pluck('audience_contact_ids')
            ->contains(function ($json) use ($contact) {
                $ids = json_decode((string) $json, true);

                return is_array($ids) && in_array((int) $contact->id, array_map('intval', $ids), true);
            });

        if ($named) {
            $reasons[] = 'broadcasts.audience_contact_ids';
        }

        return $reasons;
    }
}
