<?php

namespace App\Services\Family;

use App\Models\Contact;
use App\Models\GroupMembership;
use App\Models\ContactLoginEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The ON-SWITCH for the parent portal, and the only one (T-015d, admin half).
 *
 * Everything else in the family realm has existed since T-015c/d/e — the guard,
 * the codes, the OTP endpoints, `GroupAudience`'s guardian branch, the whole
 * read surface. None of it was reachable, because nothing in the application
 * ever wrote `contacts.login_enabled_at`: the four `login_*` columns are
 * deliberately absent from `Contact::$fillable` (see the model for why), so no
 * request body could set them and no controller did. Production read 487
 * contacts, 0 with a `login_email`, 0 enabled. This class is the door that was
 * missing, and it is deliberately narrow.
 *
 * ---------------------------------------------------------------------------
 * `login_email` is CHOSEN, never derived
 * ---------------------------------------------------------------------------
 *
 * There is no code path here that reads `contacts.email`. That column is
 * imported in bulk from an admissions spreadsheet, is routinely a HOUSEHOLD
 * address shared by both parents, and was verified by nobody — and
 * `GroupAudience::identitiesFor()` already reads it as an identity bridge for
 * STAFF, so quietly promoting it to a credential would hand a parent's mailbox
 * whatever that bridge grants. Defaulting the credential address to it would
 * also make "enable sign-in" a one-click way to mail a child's records to
 * whatever address a spreadsheet happened to carry. An administrator types the
 * address, one contact at a time, or nothing happens. See the migration that
 * added the columns.
 *
 * ---------------------------------------------------------------------------
 * THE UNIQUENESS RULE, and why the database index is not enough
 * ---------------------------------------------------------------------------
 *
 * `FamilyLoginService::resolveContact()` matches on `LOWER(login_email)` and
 * requires EXACTLY ONE row — two matches resolve to nobody, silently, with the
 * same 202 a stranger's address gets. So a duplicate does not produce an error
 * anybody sees: it produces a parent who can never sign in and an office that
 * cannot tell why.
 *
 * The `(masjid_id, login_email)` unique index cannot prevent that on its own.
 * Production MySQL is utf8mb4_bin — case-SENSITIVE — so `Parent@x.com` and
 * `parent@x.com` are two distinct values to the index and two matches to the
 * lookup, which is the exact shape of the bug. Two things close it, and both
 * stay:
 *
 *  1. **The stored value is normalised to lower case here**, so the index is
 *     computed over the same form the lookup compares. That is the structural
 *     half: it makes the collision impossible rather than merely reported.
 *  2. **A case-insensitive pre-check refuses the address with a 422** naming
 *     the member who already holds it, so an admin gets an explanation instead
 *     of an integrity-constraint 500.
 *
 * The pre-check spans SOFT-DELETED contacts too (`withTrashed`), matching the
 * index — which deliberately keeps pinning a deleted contact's address so a new
 * contact cannot silently inherit a mailbox that used to open a specific child's
 * records. Re-issuing it is an operator's decision (restore the contact, or
 * clear the column), not a side effect.
 *
 * Uniqueness is per TENANT and never global, for the reason the migration
 * records: a globally unique credential address would answer "is this parent
 * also at that other school?".
 *
 * ---------------------------------------------------------------------------
 * WHAT REVOCATION DOES TO A LIVE SESSION — two independent mechanisms
 * ---------------------------------------------------------------------------
 *
 *  1. `App\Http\Middleware\EnsureFamilyLoginActive` (alias `family.active`) is
 *     mounted on EVERY authenticated family route and re-reads
 *     `familyLoginIsActive()` off the principal the guard just loaded from the
 *     database — not a token-embedded copy. So a token already sitting in a
 *     parent's phone stops working on their NEXT request, without anything
 *     having to reach the token itself. This was verified rather than assumed:
 *     routes/family.php mounts `family.active` on the whole authenticated
 *     group, and `FamilyPortalTest::a_revoked_login_closes_every_read_endpoint_not_only_me`
 *     pins it for `/me` as well as for the endpoints that go through
 *     `GroupAudience`.
 *  2. `revoke()` additionally DELETES the contact's personal access tokens. The
 *     middleware makes a live token inert; this makes it not exist. They are
 *     not redundant — the middleware covers tokens minted after the delete and
 *     any route mounted without it, and the delete covers the case where the
 *     middleware is one edit away from being dropped from a route group. A
 *     revoked guardian should not leave a working bearer credential in the
 *     `personal_access_tokens` table for the rest of its 8-hour life either
 *     way.
 *
 * Both are asserted separately in tests/Feature/FamilyLoginEnablementTest.php,
 * so neither can pass on the other's behalf.
 */
class FamilyAccessService
{
    /**
     * Open (or re-open, or re-address) family sign-in for one contact.
     *
     * @throws RuntimeException when the contact cannot hold a login, or the
     *                          address is already in use in this tenant.
     */
    public function enable(Contact $contact, string $submittedEmail, ?User $actor = null, ?string $ip = null): Contact
    {
        // A placeholder is an "Unidentified Card ####" stub the donation flow
        // created for a card it could not attribute. It names no person, it is
        // the thing the merge flow force-deletes, and a credential attached to
        // one would be a login belonging to nobody that survives until somebody
        // notices. Refuse it here rather than let an admin discover it later.
        if ($contact->is_placeholder) {
            throw new RuntimeException(
                'This is an unidentified card placeholder, not a member. '
                . 'Attach it to a member first, then enable sign-in on that member.'
            );
        }

        // GUARDIAN EDGES ONLY. This is the condition App\Support\GroupAudience
        // wrote down as the thing that must hold before anything in this
        // application sets `login_enabled_at`, and it is not a preference:
        //
        //   "ON A CHILD'S CONTACT ROW IS A STUDENT LOGIN. standingIn() sets
        //    feed = true outright for any participant, so that child would read
        //    the whole class feed — every classmate's photograph, with nobody's
        //    consent — plus the participant threads about themselves, which are
        //    where a teacher and a guardian discuss a safeguarding concern."
        //
        // Without this check a registrar working down a school roster reaches a
        // nine-year-old's own contact row, reads a screen promising
        // guardian-scope, clicks, and hands that child their classmates'
        // photographs and the safeguarding thread about themselves. Measured
        // end to end before this guard existed: the class feed, an attachment's
        // bytes and the participant thread all answered 200.
        //
        // A student login is not a flag on this design — it is a DIFFERENT
        // standing computation (own record only, no group feed, no participant
        // threads) and belongs to its own task. Until that exists, the only
        // contact that may hold a family credential is one that is somebody's
        // guardian.
        $this->assertHoldsAGuardianEdge($contact);

        $email = $this->normalise($submittedEmail);

        if ($email === '') {
            throw new RuntimeException('A sign-in email address is required.');
        }

        $this->assertAddressIsFree($email, $contact);

        $previousEmail = $contact->login_email;

        return DB::transaction(function () use ($contact, $email, $previousEmail, $actor, $ip): Contact {
            // forceFill because the four login_* columns are deliberately not
            // fillable. This is one of exactly two places that writes them.
            $contact->forceFill([
                'login_email' => $email,
                'login_enabled_at' => Carbon::now(),
                // Re-enabling CLEARS the revocation. Leaving it set would make
                // `familyLoginIsActive()` false forever and produce a login that
                // reads as enabled in the UI and refuses every request.
                'login_revoked_at' => null,
            ])->save();

            // A CHANGE of address ends the sessions established under the old
            // one. The usual reason to re-address a login is that the previous
            // mailbox was wrong or is no longer the right person's — a
            // separation, a guardian handover, a typo that reached a stranger —
            // and in every one of those a session opened under the old address
            // is a session the change was meant to end.
            //
            // Deliberately NOT done when the address is unchanged: re-typing the
            // same address to no effect must not silently sign a parent out.
            if ($previousEmail !== null && $previousEmail !== $email) {
                $contact->tokens()->delete();
            }

            $this->record($contact, ContactLoginEvent::ACTION_ENABLED, $email, $actor, $ip);

            return $contact->refresh();
        });
    }

    /**
     * Withdraw family sign-in, and end any session already holding a token.
     *
     * @throws RuntimeException when there is nothing to revoke.
     */
    public function revoke(Contact $contact, ?User $actor = null, ?string $ip = null): Contact
    {
        if ($contact->login_enabled_at === null && $contact->login_revoked_at === null) {
            throw new RuntimeException('This member has no family sign-in to revoke.');
        }

        return DB::transaction(function () use ($contact, $actor, $ip): Contact {
            // Revoking an ALREADY-revoked login is allowed and is a no-op on the
            // timestamp: the original `login_revoked_at` is when access actually
            // ended, and moving it forward would rewrite that fact. What DOES
            // re-run is the token delete below — a revoke button must never
            // refuse, because the one time it matters is the time somebody is
            // clicking it twice in a hurry.
            if ($contact->login_revoked_at === null) {
                $contact->forceFill(['login_revoked_at' => Carbon::now()])->save();
            }

            // The credential stops existing, on top of `family.active` making it
            // inert on the next request. See the class docblock: two mechanisms,
            // deliberately not one.
            $contact->tokens()->delete();

            $this->record(
                $contact,
                ContactLoginEvent::ACTION_REVOKED,
                $contact->login_email,
                $actor,
                $ip,
            );

            return $contact->refresh();
        });
    }

    /**
     * The three states a contact's family login can be in, as one word.
     *
     * Derived in PHP and served to the SPA rather than reconstructed there: the
     * portal's own liveness rule lives in `Contact::familyLoginIsActive()`, and a
     * second copy of it in TypeScript is a copy that agrees today.
     */
    public function state(Contact $contact): string
    {
        if ($contact->login_revoked_at !== null) {
            return 'revoked';
        }

        return $contact->login_enabled_at !== null ? 'enabled' : 'never_enabled';
    }

    // ------------------------------------------------------------- internals

    /**
     * Lower-cased and trimmed — the form `FamilyLoginService::resolveContact()`
     * compares against, and the form the unique index must therefore hold.
     */
    private function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @throws RuntimeException when another contact in this tenant already holds
     *                          this address, case-insensitively.
     */
    private function assertAddressIsFree(string $email, Contact $contact): void
    {
        // Tenant-scoped by BelongsToMasjid — never hand-filtered on masjid_id
        // (.claude/rules/tenant-scoping.md). `withTrashed` because the unique
        // index constrains soft-deleted rows too and because a deleted contact's
        // address must not be silently inheritable.
        $holder = Contact::withTrashed()
            ->whereNotNull('login_email')
            ->whereRaw('LOWER(login_email) = ?', [$email])
            ->whereKeyNot($contact->getKey())
            ->first();

        if ($holder === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'That sign-in email is already used by %s%s. '
            . 'Two members cannot share one sign-in address — neither of them would be able to sign in.',
            trim($holder->first_name . ' ' . $holder->last_name) ?: 'another member',
            $holder->trashed() ? ' (deleted)' : '',
        ));
    }

    /** Append one immutable act to the trail. */
    private function record(
        Contact $contact,
        string $action,
        ?string $loginEmail,
        ?User $actor,
        ?string $ip,
    ): void {
        ContactLoginEvent::create([
            // Explicit rather than left to the creating hook: this must be the
            // CONTACT's tenant even in an unbound context (a console caller, a
            // future backfill). Under a bound request the hook overrides it with
            // the same value.
            'masjid_id' => $contact->masjid_id,
            'contact_id' => $contact->id,
            'action' => $action,
            'login_email' => $loginEmail,
            'actor_user_id' => $actor?->id,
            // Snapshotted, because a name read back through the foreign key is
            // the name that person has today — and no name at all once they are
            // deleted. See the migration.
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => $ip,
        ]);
    }

    /**
     * Refuse a contact that is nobody's guardian.
     *
     * A guardian edge is a `group_memberships` row with `role = guardian` AND a
     * non-null `guardian_of_contact_id` — "a guardian of nobody" is already
     * refused at the other end by StoreGroupMembershipRequest, so the two halves
     * agree on what the edge means.
     *
     * Scoped to the contact's OWN masjid explicitly rather than relying on the
     * bound tenant: this runs behind admin+tenant middleware today, but the
     * check that decides whether a child gets a login must not depend on a
     * caller having bound the context correctly.
     *
     * Deliberately NOT requiring `consent_granted_at`: consent governs what a
     * guardian may RECEIVE (GroupAudience reads it per disclosure), not whether
     * they may hold a credential at all. Conflating the two would let a missing
     * consent record silently lock a parent out of signing in.
     */
    private function assertHoldsAGuardianEdge(Contact $contact): void
    {
        $isGuardian = GroupMembership::query()
            ->where('contact_id', $contact->id)
            ->where('masjid_id', $contact->masjid_id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->whereNotNull('guardian_of_contact_id')
            ->exists();

        if (! $isGuardian) {
            throw new RuntimeException(
                'Family sign-in can only be enabled for a guardian. This member is not '
                . 'listed as the guardian of any student, so a login here would be a '
                . 'student login — which grants the whole group feed and is not what '
                . 'this portal is built for. Add them as a guardian on their child\'s '
                . 'roster entry first.'
            );
        }
    }
}
