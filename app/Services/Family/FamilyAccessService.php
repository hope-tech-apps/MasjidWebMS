<?php

namespace App\Services\Family;

use App\Models\Contact;
use App\Models\GroupMembership;
use App\Models\ContactLoginEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
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
 * records. Re-issuing it is an operator's decision, not a side effect.
 *
 * ---------------------------------------------------------------------------
 * …AND THE DECISION AN OPERATOR CAN ACTUALLY MAKE (`reassign_address`)
 * ---------------------------------------------------------------------------
 *
 * "Restore the contact, or clear the column" described two doors that were never
 * built: this application has exactly one restore route (`masjids/{id}/restore`)
 * and no way to clear `login_email` — `enable()` is its only writer and `revoke()`
 * leaves it standing. So the pre-check, spanning revoked AND deleted holders,
 * had no exit at all. Two ordinary things were therefore impossible:
 *
 *   - a household with ONE mailbox: revoke the mother, try to give the father
 *     household@example.com, and the only way through was to RE-ENABLE the
 *     mother at a throwaway address — i.e. to re-grant a parent access to a
 *     child's file in order to move an email address;
 *   - a member deleted and re-imported: their address was dead for that tenant
 *     forever, short of a database console.
 *
 * The exit is a SECOND, EXPLICIT act, not a relaxation of the rule:
 *
 *   1. A holder who can sign in RIGHT NOW (`familyLoginIsActive()`) still
 *      refuses, unconditionally and with the flag set. Two live logins on one
 *      address is the ambiguity `resolveContact()` answers with silence, and no
 *      confirmation box may buy it. Revoke that member first — a separate verb
 *      that ends their access properly and says so on the record.
 *   2. A holder whose access has ALREADY ended (revoked, or soft-deleted, or
 *      both) still refuses by default — nothing is inherited silently, which is
 *      the whole property the `withTrashed` span exists for. The refusal now
 *      names the way through instead of being a dead end.
 *   3. Re-submitting with `reassign_address` releases the address from that
 *      holder and grants it here, in one transaction, writing
 *      `address_released` on the loser and `enabled` on the winner. Both halves
 *      are on the record, so "which mailbox used to open this child's file" is
 *      still answerable — from the trail, which snapshots the address per act,
 *      rather than from a stale column that had to be kept forever to stand in
 *      for it.
 *
 * The refusal does NOT name a soft-deleted holder. No screen in this application
 * shows a deleted contact, so naming one in an error message discloses a member
 * to staff who were never shown them; a live holder is named exactly as before,
 * because they are in the directory the reader is already looking at and the
 * admin needs to know whose sign-in to revoke.
 *
 * ---------------------------------------------------------------------------
 * LEAVING THE DIRECTORY TAKES THE PORTAL WITH IT (merge, delete)
 * ---------------------------------------------------------------------------
 *
 * `ContactsController::merge` force-deletes the absorbed contact and
 * `::destroy` soft-deletes. Both used to end a parent's access as a pure side
 * effect: no `revoked` event, no token delete, a survivor that gained nothing,
 * and — after a merge — an audit trail orphaned beyond every screen by the
 * `nullOnDelete` that was chosen to preserve it. `absorbOnMerge()` and the
 * revoke inside `destroy()` close that. The rule both follow is one sentence: a
 * contact leaving the directory takes its portal access with it, LOUDLY.
 *
 * A merge deliberately does NOT carry a live credential onto the survivor. See
 * `absorbOnMerge()` for the argument. It DOES carry the roster edges, which is a
 * different kind of fact and the one this class's own guard reads — see
 * App\Services\Groups\RosterMergeService.
 *
 * ---------------------------------------------------------------------------
 * NOTHING RE-DERIVES A GRANT AFTER THE FACT — and that is now the whole rule
 * ---------------------------------------------------------------------------
 *
 * `enable()` checks the roster once, at the moment an operator grants access.
 * A previous round added a second, automatic re-derivation in the direction
 * that "adds disclosure": a `created` hook on `GroupMembership` that revoked a
 * live credential the instant its holder gained a participant row. It is gone,
 * and the reasoning is on that model — in short, it was reachable by an
 * anonymous POST to the public registration endpoint, it fired on two ordinary
 * admin acts (a parent joining the adult ḥalaqa, a teacher who is also a parent
 * being given a class), it wrote an audit row naming nobody, and the office
 * could not put the credential back.
 *
 * The hazard it was aimed at — a participant edge widening what a credential
 * reads — is answered by SCOPING THE READ instead, in
 * `App\Support\GroupAudience::membershipsFor()`: a family principal speaks
 * through their guardian edges and nothing else. That is evaluated per request
 * against live roster data, so no ordering of admin acts can walk around it,
 * and it destroys nothing, refuses nobody, and cannot be triggered by a caller.
 *
 *  - LOST. Removing a guardian edge does NOT revoke anything, and this is a
 *    decision rather than an omission. A child changing class deletes an edge
 *    and creates another; a roster correction deletes one and retypes it. For
 *    the interval in between the parent holds none, and revoking there would
 *    burn a family's sign-in every term — over-refusal with an extremely
 *    ordinary trigger, and silent, because nobody is looking at that parent's
 *    record while editing a roster. The disclosure work is already done without
 *    it: the roster is what `GroupAudience` reads, so the parent's whole read
 *    surface empties on their next request
 *    (`FamilyPortalTest::removing_the_child_from_the_roster_takes_the_guardian_edge_with_them`
 *    pins it, and pins that `/me` still answers, which is their own record).
 *    What was genuinely missing was the OFFICE being able to see it, so
 *    `ineligibilityReason()` is served on the family-login panel and a lapsed
 *    grant now reads as one on screen. Ending it stays a deliberate act, which
 *    is what this design says about every other grant.
 *
 *  - AND NOT AT THE DOOR. `EnsureFamilyLoginActive` and
 *    `FamilyLoginService::resolveContact()` were left alone. They already re-read
 *    `familyLoginIsActive()` from the database on EVERY request, so a revocation
 *    written above reaches a token already sitting in a phone on its next
 *    request — the read was never the missing half, the write was. Putting a
 *    roster query in `familyLoginIsActive()` would add one to two queries to
 *    every family request AND convert every transient roster edit into a silent
 *    lockout with nothing on any screen, which is the failure shape this realm
 *    is written against.
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
     * @param  bool  $reassignAddress  the operator has been shown the refusal and
     *                                 confirmed taking the address off a holder
     *                                 whose access has already ended. Never
     *                                 overrides a holder who can sign in now.
     *
     * @throws RuntimeException when the contact cannot hold a login, or the
     *                          address is already in use in this tenant.
     */
    public function enable(
        Contact $contact,
        string $submittedEmail,
        ?User $actor = null,
        ?string $ip = null,
        bool $reassignAddress = false,
    ): Contact {
        // A placeholder is an "Unidentified Card ####" stub the donation flow
        // created for a card it could not attribute. It names no person, it is
        // the thing the merge flow force-deletes, and a credential attached to
        // one would be a login belonging to nobody that survives until somebody
        // notices. Refused by the same rule as everything else below, so the
        // screen that previews the refusal shows this one too.
        //
        // GUARDIAN EDGES ONLY, AND NOTHING ELSE. This is the condition
        // App\Support\GroupAudience wrote down as the thing that must hold
        // before anything in this application sets `login_enabled_at`, and it is
        // not a preference:
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
        // guardian AND is nobody's classmate. See ineligibilityReason() for why
        // the second half is not a nicety.
        $this->assertMayHoldAFamilyLogin($contact);

        $email = $this->normalise($submittedEmail);

        if ($email === '') {
            throw new RuntimeException('A sign-in email address is required.');
        }

        // Refuses outright for a LIVE holder; returns the ended-access holder to
        // be released below when the operator has confirmed it; returns null
        // when the address is free.
        $holder = $this->resolveAddressConflict($email, $contact, $reassignAddress);

        $previousEmail = $contact->login_email;

        // The pre-check above runs OUTSIDE the transaction, and moving it inside
        // would not make it atomic either — SELECT-then-INSERT is not a lock. Two
        // admins enabling the same address in the same second therefore both pass
        // it, and `contacts_masjid_login_email_unique` catches the loser. That is
        // the index doing its job and no corruption results; what the loser must
        // NOT get is a 500 where the sentence explaining the collision was already
        // written. Translated here rather than in the controller so both doors —
        // the pre-check and the index — hand back one refusal in one voice.
        try {
            return $this->write($contact, $email, $previousEmail, $holder, $actor, $ip);
        } catch (QueryException $e) {
            if (! $this->isTheLoginEmailUniqueViolation($e)) {
                throw $e;
            }

            throw new RuntimeException(
                'That sign-in email was just taken by another member — somebody else '
                . 'was assigning it at the same moment. Reload this member and try again.'
            );
        }
    }

    /**
     * The write half of `enable()`, as one transaction.
     *
     * Split out so the QueryException translation above wraps a call rather than
     * a closure, and so the rollback is complete before the refusal is thrown.
     */
    private function write(
        Contact $contact,
        string $email,
        ?string $previousEmail,
        ?Contact $holder,
        ?User $actor,
        ?string $ip,
    ): Contact {
        return DB::transaction(function () use ($contact, $email, $previousEmail, $holder, $actor, $ip): Contact {
            // The address comes off the previous holder FIRST — the unique index
            // spans revoked and soft-deleted rows, so the grant below cannot even
            // be written while they still hold it. Same transaction: there is no
            // instant at which the address belongs to nobody, and a failure
            // downstream gives it back.
            if ($holder !== null) {
                $this->releaseAddressFrom($holder, $contact, $actor, $ip);
            }

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
     * Settle the absorbed contact's family login before a merge destroys it.
     *
     * Called by `ContactsController::merge` INSIDE its transaction and BEFORE
     * `forceDelete()`. Returns a summary of what it did for the operator, or
     * null when the source never had a login at all — which is the overwhelmingly
     * common case (the flow exists for "Unidentified Card ####" stubs, and a
     * placeholder is refused a credential outright), and which must stay a
     * no-op so an ordinary card attribution writes no audit rows.
     *
     * ---------------------------------------------------------------------------
     * WHY THE CREDENTIAL IS ENDED AND NOT CARRIED
     * ---------------------------------------------------------------------------
     *
     * The tempting reading is that a merge asserts "these two rows are the same
     * human", so the credential should follow them the way donations and cards
     * do. It is the wrong call, for four reasons that compound:
     *
     *  1. **The survivor may not be allowed to hold a login.** `enable()` refuses
     *     a placeholder and refuses anyone who is nobody's guardian, because a
     *     login on a non-guardian row is a STUDENT login and hands a child the
     *     whole class feed. Carrying the column would write `login_enabled_at`
     *     onto a contact that has passed neither check — the one door in this
     *     application that writes that column, bypassed by a de-duplication.
     *  2. **The survivor may already hold their own login, at another address.**
     *     Carrying would either silently re-address a working credential (ending
     *     that parent's session and changing which mailbox opens the child's
     *     file) or have to refuse the merge. Both are worse than ending one.
     *  3. **A grant is a typed, deliberate act, and this design says so
     *     everywhere.** The four `login_*` columns are unfillable, there is no
     *     default from `contacts.email`, and the address is typed one contact at
     *     a time. A carried credential is a grant no administrator typed, and its
     *     audit row would read `enabled` for an act nobody performed.
     *  4. **The failure modes are not symmetrical.** Ending access wrongly costs
     *     one click to undo — and after the force-delete the address is genuinely
     *     free, so the survivor can be enabled at the SAME address immediately,
     *     deliberately, with a real `enabled` row naming who did it. Granting
     *     access wrongly is a stranger reading a specific child's file, and
     *     nobody finds out.
     *
     * So: fail closed, and be loud about it. The `revoked` row is written by the
     * ordinary `revoke()` path (which also kills the tokens), a `merged` row
     * records why the trail moved, and the whole trail is re-pointed at the
     * survivor so it stays readable on a screen instead of being orphaned by the
     * `nullOnDelete` that was chosen to preserve it. The controller reports what
     * happened, because an audit row nobody is told about is not "loudly".
     *
     * @return array{login_email: ?string, was_active: bool, events_carried: int}|null
     */
    public function absorbOnMerge(Contact $source, Contact $target, ?User $actor = null, ?string $ip = null): ?array
    {
        $trail = ContactLoginEvent::query()
            ->where('contact_id', $source->getKey())
            ->orderBy('id')
            ->get();

        $hasLoginState = $source->login_enabled_at !== null
            || $source->login_revoked_at !== null
            || $source->login_email !== null;

        if ($trail->isEmpty() && ! $hasLoginState) {
            return null;
        }

        $address = $source->login_email;
        $wasActive = $source->familyLoginIsActive();

        if ($wasActive) {
            // Access is ending here, so the record must say so — with the tokens
            // deleted by the same call, because a force-deleted contact leaving a
            // live bearer credential in `personal_access_tokens` is the merge
            // silently doing the one thing revocation exists to prevent.
            $this->revoke($source, $actor, $ip);
        }

        // The marker that makes the carried trail legible on somebody else's
        // screen. Written on the SOURCE so it travels with the rest of the rows
        // below and lands in chronological order beneath them.
        $this->record($source, ContactLoginEvent::ACTION_MERGED, $address, $actor, $ip);

        // Re-read rather than reuse the collection above: `revoke()` and
        // `record()` appended rows it could not have seen.
        $carried = ContactLoginEvent::query()
            ->where('contact_id', $source->getKey())
            ->orderBy('id')
            ->get();

        $carried->each(fn (ContactLoginEvent $event) => $event->reassignTo($target));

        return [
            'login_email' => $address,
            'was_active' => $wasActive,
            'events_carried' => $carried->count(),
        ];
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
     * Who else holds this address, and may this act take it from them?
     *
     * Returns the holder to release (already confirmed by the operator), or null
     * when the address is free.
     *
     * @throws RuntimeException when the address is held by a member who can sign
     *                          in right now, or by one whose access has ended and
     *                          the operator has not confirmed the reassignment.
     */
    private function resolveAddressConflict(string $email, Contact $contact, bool $reassignAddress): ?Contact
    {
        $holder = $this->currentHolderOf($email, $contact);

        if ($holder === null) {
            return null;
        }

        // A HOLDER WHO CAN SIGN IN NOW. Refused unconditionally, confirmation or
        // not: two live logins on one address is the exact ambiguity
        // `resolveContact()` answers by resolving to NOBODY, silently, behind the
        // same 202 a stranger gets — both parents locked out with nothing in any
        // log. They are named because they are in the directory the admin is
        // already looking at, and because "revoke whose?" needs an answer.
        if ($holder->familyLoginIsActive()) {
            throw new RuntimeException(sprintf(
                'That sign-in email is already used by %s, who can sign in with it right now. '
                . 'Two members cannot share one sign-in address — neither of them would be able to '
                . 'sign in. Revoke their sign-in first if this address should move.',
                $this->nameOf($holder),
            ));
        }

        // A HOLDER WHOSE ACCESS HAS ALREADY ENDED — revoked, deleted, or both.
        // Nothing is inherited silently, which is the property the `withTrashed`
        // span exists for; but the refusal now names a door instead of being one.
        // A soft-deleted holder is NOT named: no screen in this application shows
        // a deleted contact, so naming one here discloses a member to staff who
        // were never shown them.
        if (! $reassignAddress) {
            throw new AddressReassignmentRequired(sprintf(
                'That sign-in email is still assigned to %s, whose portal access has already ended. '
                . 'Confirm the reassignment to move the address to this member — it will be released '
                . 'from the other record, and both halves are written to THIS member\'s access '
                . 'history below.',
                $holder->trashed() ? 'a member who has since been deleted' : $this->nameOf($holder),
            ));
        }

        return $holder;
    }

    /**
     * Whoever else in this tenant currently holds `$email` as a credential.
     *
     * Tenant-scoped by BelongsToMasjid — never hand-filtered on masjid_id
     * (.claude/rules/tenant-scoping.md). `withTrashed` because the unique index
     * constrains soft-deleted rows too and because a deleted contact's address
     * must not be silently inheritable.
     */
    private function currentHolderOf(string $email, Contact $claimant): ?Contact
    {
        return Contact::withTrashed()
            ->whereNotNull('login_email')
            ->whereRaw('LOWER(login_email) = ?', [$email])
            ->whereKeyNot($claimant->getKey())
            ->first();
    }

    /**
     * WHAT AN OPERATOR IS ABOUT TO TAKE, so `reassign_address` cannot be silent.
     *
     * `reassign_address` is `sometimes|boolean` and nothing enforces that the
     * refusal it "confirms" was ever issued. A caller may therefore send it on
     * the FIRST attempt, and the refusal that names the loser never runs — so a
     * credential address was stripped off a soft-deleted holder with the operator
     * never told whose it was, and the deliberate decision NOT to name a deleted
     * member in an error message turned into never naming them at all.
     *
     * Requiring the refusal to have happened is not available: this is HTTP, the
     * refusal leaves nothing on the server, and a token to prove it would be a
     * new grant to forge. What IS available is telling them AFTERWARDS. The
     * controller calls this BEFORE `enable()` and reports what it says, so the
     * outcome carries the same disclosure the refusal would have — no more (a
     * deleted holder is still described rather than named, because no screen in
     * this application shows a deleted contact) and no less.
     *
     * @return array{name: string, was_active: bool, trashed: bool}|null
     */
    public function addressHolderReport(Contact $contact, string $submittedEmail): ?array
    {
        $email = $this->normalise($submittedEmail);

        if ($email === '') {
            return null;
        }

        $holder = $this->currentHolderOf($email, $contact);

        if ($holder === null) {
            return null;
        }

        return [
            'name' => $holder->trashed() ? 'a member who has since been deleted' : $this->nameOf($holder),
            'was_active' => $holder->familyLoginIsActive(),
            'trashed' => $holder->trashed(),
        ];
    }

    /**
     * Free `login_email` from a holder whose access has ended, on the record.
     *
     * The ONLY path in this application that clears the column, which is why
     * `revoke()` can keep leaving it standing: an address stops being a member's
     * when an operator gives it to somebody else, and never as a side effect of
     * anything.
     *
     * `login_enabled_at` and `login_revoked_at` are untouched — they are the
     * record of the grant and of when it ended, and this is neither. What the
     * cleared column used to stand in for ("which mailbox opened this child's
     * file") is answered by the trail, which snapshots the address on every act
     * including the `address_released` row written here.
     *
     * The revocation backstop below exists for one shape: a contact soft-deleted
     * with a live login before `ContactsController::destroy` learned to revoke.
     * Their access ended at the delete with no `revoked` row, and taking their
     * address away must not be the moment that fact disappears entirely.
     *
     * ---------------------------------------------------------------------------
     * AND ONE ROW ON THE CLAIMANT, because the other three are unreadable
     * ---------------------------------------------------------------------------
     *
     * The refusal an operator accepts to get here ends "…it will be released
     * from the other record and both halves go on the access history". Measured
     * against the first version of this method, that was false in exactly the
     * case the door exists for: the holder is soft-deleted, the panel reads
     * `where('contact_id', $contact->id)`, and `ContactsController::index/show`
     * use the non-trashed scope — so no screen in this application can reach a
     * deleted contact's trail. The winner's panel carried ONE event, `enabled`,
     * and "which mailbox used to open this child's file" was answerable only
     * from a database console. That is the same "orphaned beyond every screen"
     * failure `absorbOnMerge()` was written to fix, reintroduced.
     *
     * So the claimant gets an `address_claimed` row naming the address they took.
     * Written for BOTH kinds of loser rather than only the deleted one: a live
     * loser's rows sit on a DIFFERENT member's record, which is not the history
     * the operator was just promised and not the screen they are looking at, and
     * a rule with a branch in it is a rule that is true of one case and quietly
     * false of the other — which is precisely the defect being fixed.
     *
     * The loser keeps their own two rows. They are that member's history, they
     * must survive a restore, and moving them the way a merge moves a trail
     * would erase the fact that this member ever held the address.
     */
    private function releaseAddressFrom(Contact $holder, Contact $claimant, ?User $actor, ?string $ip): void
    {
        $released = $holder->login_email;

        if ($holder->login_enabled_at !== null && $holder->login_revoked_at === null) {
            $holder->forceFill(['login_revoked_at' => Carbon::now()])->save();
            $holder->tokens()->delete();
            $this->record($holder, ContactLoginEvent::ACTION_REVOKED, $released, $actor, $ip);
        }

        $holder->forceFill(['login_email' => null])->save();

        $this->record($holder, ContactLoginEvent::ACTION_ADDRESS_RELEASED, $released, $actor, $ip);

        // The half the operator was promised, on the record they are reading.
        $this->record($claimant, ContactLoginEvent::ACTION_ADDRESS_CLAIMED, $released, $actor, $ip);
    }

    /** A holder's name for a message, or a neutral noun when the row has none. */
    private function nameOf(Contact $contact): string
    {
        return trim($contact->first_name . ' ' . $contact->last_name) ?: 'another member';
    }

    /**
     * Is this the `(masjid_id, login_email)` index refusing a duplicate?
     *
     * Driver-agnostic on purpose: production MySQL names the index
     * (`contacts_masjid_login_email_unique`) and SQLite names the columns
     * (`UNIQUE constraint failed: contacts.masjid_id, contacts.login_email`), so
     * the shared, checkable facts are SQLSTATE 23000 plus the column name. Any
     * other integrity violation — a foreign key, another index — is re-thrown
     * rather than dressed up as an address collision, because a 422 saying
     * "somebody took that address" for a broken foreign key is a lie that costs
     * an afternoon.
     */
    private function isTheLoginEmailUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();
        $message = Str::lower($e->getMessage());

        return (string) $sqlState === '23000'
            && Str::contains($message, 'login_email')
            && (Str::contains($message, 'unique') || Str::contains($message, 'duplicate'));
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

    private function assertMayHoldAFamilyLogin(Contact $contact): void
    {
        $reason = $this->ineligibilityReason($contact);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    /**
     * WHY THIS CONTACT MAY NOT HOLD A FAMILY CREDENTIAL, or null if they may.
     *
     * The single definition of the rule, and public because three surfaces need
     * it and must not each own a copy: `enable()` refuses with the sentence this
     * returns, `ContactFamilyLoginController` puts it on the screen BEFORE the
     * operator types an address, and `ContactsController::merge` uses it to
     * decide whether the survivor of a merge can actually be told to enable
     * sign-in. A preview computed by a second implementation is a preview that
     * agrees today; this one cannot be stricter or looser than the write,
     * because it IS the write's check.
     *
     * ---------------------------------------------------------------------------
     * THE DUAL-ROLE REFUSAL THAT USED TO BE CONDITION 1, AND WHY IT IS GONE
     * ---------------------------------------------------------------------------
     *
     * A previous round refused any contact holding a `leader`/`member` edge
     * ANYWHERE in the tenant. The hazard it named is real and is quoted above:
     * `GroupAudience::standingIn()` set `feed = true` outright for any
     * participant, so a credential granted for one child's records also carried
     * whatever class its holder happened to be on — measured with Tariq, 16,
     * `member` of the Teens Ḥalaqa and `guardian` of his seven-year-old sister
     * because a school records who may collect a child.
     *
     * But the refusal was aimed at the wrong noun. It asked WHO MAY HOLD a
     * credential in order to control WHAT A CREDENTIAL MAY READ, and those come
     * apart the moment an ordinary adult is both:
     *
     *   - a parent enrolled in the adult ḥalaqa holds a `member` edge;
     *   - a teacher who is also a parent holds a `leader` edge.
     *
     * Both were refused a portal sign-in outright, and — through the `created`
     * hook that enforced the same rule after the fact — both LOST one they
     * already held, on a 201, as a side effect of ordinary roster work. The
     * brief's requirement is explicit that a legitimate guardian, including a
     * parent enrolled in an adult ḥalaqa, must be able to get AND KEEP access,
     * and the pair failed it in both directions.
     *
     * So the property moved to where it can be true without refusing anybody:
     * `App\Support\GroupAudience::membershipsFor()` narrows a FAMILY principal
     * to their GUARDIAN EDGES ONLY. A parent portal credential now speaks for
     * wards and for nothing else — the holder's own participant rows grant it no
     * feed, no participant thread about themselves, no award and no ḥifẓ record,
     * and a group they are only a participant of answers 403. Tariq keeps a
     * credential that reads exactly his sister's records, which is what the
     * school asserted when it recorded him as her guardian, and reads nothing at
     * all about himself.
     *
     * That is strictly stronger than the refusal it replaces: the refusal only
     * ever fired when a contact was BOTH, so a student-aged contact recorded
     * only as a sibling's guardian was always eligible and always read through
     * that edge. What has changed is that no participant row anywhere now widens
     * a credential, so the ordering of two admin acts no longer decides what a
     * parent can see — the roster decides it, on every request.
     *
     * WHAT REMAINS REFUSED, and it is the half that was always load-bearing: a
     * contact who is NOBODY's guardian gets no credential (condition 2 below).
     * A child's own contact row is nobody's guardian, so a registrar working
     * down a school roster still cannot hand a nine-year-old a login.
     *
     * ---------------------------------------------------------------------------
     * THE CONDITION THAT REMAINS
     * ---------------------------------------------------------------------------
     *
     * 2. A CONFIRMED GUARDIAN EDGE OVER A WARD WHO STILL EXISTS. A guardian edge
     *    is a `group_memberships` row with `role = guardian` AND a non-null
     *    `guardian_of_contact_id` — "a guardian of nobody" is already refused at
     *    the other end by StoreGroupMembershipRequest, so the two halves agree on
     *    what the edge means. `contacts` SOFT-delete, so removing a child from
     *    the directory cascades nothing and the edge row survives it intact:
     *    measured, `enable()` wrote a fresh grant, dated today, to somebody who
     *    is nobody's guardian in the directory the office is looking at — and the
     *    credential opened nothing, because every read of a ward's records runs
     *    through the same SoftDeletes scope.
     *
     *    ...AND CONFIRMED IS NOT DECORATION ON THAT SENTENCE. This is the sole
     *    remaining condition, so before provenance existed the ONE thing standing
     *    between a stranger and a child's file was a row an ANONYMOUS POST could
     *    write: `/api/v1/offerings/{slug}/register` named a real child by email,
     *    `RegistrationService::writeRosterMemberships()` minted a genuine
     *    `guardian` row from the payer over her, and this method — asked whether
     *    the payer may hold a credential — read that row and answered yes.
     *    Measured: the panel built to stop a registrar handing a nine-year-old a
     *    login advertised a stranger as `"eligible": true`, and `enable()`
     *    answered 200. `GroupMembership::scopeConfirmed()` is the same definition
     *    `App\Support\GroupAudience` reads standing through, so the door and the
     *    disclosure cannot disagree about what an edge is worth.
     *
     * Scoped to the contact's OWN masjid explicitly rather than relying on the
     * bound tenant: this runs behind admin+tenant middleware today, but the check
     * that decides whether a child gets a login must not depend on a caller
     * having bound the context correctly. The ward lookup drops the tenant global
     * scope and re-applies the same masjid by hand for that reason, and keeps the
     * SoftDeletes scope, which is the whole point of the clause.
     *
     * Deliberately NOT requiring `consent_granted_at`: consent governs what a
     * guardian may RECEIVE (GroupAudience reads it per disclosure), not whether
     * they may hold a credential at all. Conflating the two would let a missing
     * consent record silently lock a parent out of signing in.
     */
    public function ineligibilityReason(Contact $contact): ?string
    {
        if ($contact->is_placeholder) {
            return 'This is an unidentified card placeholder, not a member. '
                . 'Attach it to a member first, then enable sign-in on that member.';
        }

        if ($this->holdsAGuardianEdgeOverALiveWard($contact)) {
            return null;
        }

        if ($this->holdsAGuardianEdge($contact)) {
            return 'Family sign-in can only be enabled for a guardian. Every member this person is '
                . 'listed as a guardian of has been deleted from the directory, so a sign-in here '
                . 'would open nothing. Restore the member, or add this person as a guardian on a '
                . 'current student\'s roster entry first.';
        }

        // THE PENDING CASE, and it needs its own sentence rather than the one
        // below. A registrar looking at a roster that plainly shows this person
        // as a guardian, told "this member is not listed as the guardian of any
        // student", concludes the screen is broken and goes looking for a way
        // around it. What is actually true is narrower and has an obvious next
        // step, so the refusal says it and names the step.
        if ($this->holdsAnUnconfirmedGuardianEdge($contact)) {
            return 'This person is listed as a guardian only by a claim made on a public '
                . 'registration form — nobody at the organisation has confirmed it, and an '
                . 'unconfirmed claim opens nothing. Confirm the guardian entry on that group\'s '
                . 'roster first, and then family sign-in can be enabled here.';
        }

        return 'Family sign-in can only be enabled for a guardian. This member is not '
            . 'listed as the guardian of any student, so a login here would be a '
            . 'student login — which grants the whole group feed and is not what '
            . 'this portal is built for. Add them as a guardian on their child\'s '
            . 'roster entry first.';
    }

    /** The convenience half of the rule above, for callers that only need yes/no. */
    public function mayHoldAFamilyLogin(Contact $contact): bool
    {
        return $this->ineligibilityReason($contact) === null;
    }

    private function holdsAGuardianEdge(Contact $contact): bool
    {
        return $this->guardianEdges($contact)->exists();
    }

    private function holdsAGuardianEdgeOverALiveWard(Contact $contact): bool
    {
        return $this->guardianEdgesOverALiveWard($contact)->exists();
    }

    /**
     * Is the ONLY thing making this person look like a guardian a claim nobody
     * has stood behind? Asked over LIVE wards, so it names the pending claim
     * rather than a deleted child — the deleted-ward branch above owns that.
     */
    private function holdsAnUnconfirmedGuardianEdge(Contact $contact): bool
    {
        return $this->guardianEdgesOverALiveWard($contact, confirmedOnly: false)->exists();
    }

    private function guardianEdgesOverALiveWard(Contact $contact, bool $confirmedOnly = true): Builder
    {
        // A sub-select rather than whereHas: the tenant global scope is dropped
        // and the same masjid re-applied by hand (this check must not depend on
        // a caller having bound the context), while the SoftDeletes scope stays
        // — a deleted ward is the whole point of the clause.
        return $this->guardianEdges($contact, $confirmedOnly)
            ->whereIn(
                'guardian_of_contact_id',
                Contact::withoutMasjidScope()
                    ->where('masjid_id', $contact->masjid_id)
                    ->select('id'),
            );
    }

    /**
     * The guardian edges this contact holds.
     *
     * CONFIRMED BY DEFAULT, because every caller that decides ACCESS wants that
     * one and a default that grants is a default that eventually grants by
     * accident. The single caller passing false is the refusal sentence above,
     * which is describing rows rather than trusting them.
     */
    private function guardianEdges(Contact $contact, bool $confirmedOnly = true): Builder
    {
        return GroupMembership::withoutMasjidScope()
            ->where('contact_id', $contact->id)
            ->where('masjid_id', $contact->masjid_id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->whereNotNull('guardian_of_contact_id')
            ->when($confirmedOnly, fn (Builder $q) => $q->confirmed());
    }
}
