<?php

namespace App\Support;

use App\Models\Contact;
use Illuminate\Support\Str;

/**
 * ===========================================================================
 * AN ABSENT VALUE IS NEVER AN IDENTITY MATCH.
 * ===========================================================================
 *
 * That is the whole of this class, and it is stated here once because the
 * application has now got it wrong by stating it twice.
 *
 * `RosterMergeService` asks, of a de-duplication, "did the identity this row's
 * authority is read through change?" — and a previous round answered it with
 *
 *     $holderIdentityChanged = $this->addressOf($source) !== $this->addressOf($target);
 *
 * where `addressOf()` returned `''` for a contact with no email. The method's
 * own docblock noticed the empty string and reasoned only about `'' != 'real@x'`
 * — a row nothing can resolve is not the same person as a row that resolves to a
 * real mailbox — and never about `'' == ''`. So TWO ADDRESS-LESS ADULTS WERE THE
 * SAME PERSON, and a merge carried a staff-confirmed guardianship, its
 * `confirmed_by_user_id` and its recorded media consent onto a different human,
 * reporting `unconfirmed: 0`. Measured end to end, from a caller with no account
 * and no token:
 *
 *     [P11] planted phantom #4 email=NULL phone=NULL
 *     [P11] roster block: {"moved":1,"dropped":0,"unconfirmed":0,
 *             "guardian_claims_reissued":0,"confirmed_guardian_edges_dropped":0,
 *             "family_logins_left_without_a_ward":0,"guardian_claims":[]}
 *     [P11] edge now: {"id":2,"contact_id":4,"guardian_of_contact_id":1,
 *                      "provenance":"confirmed","consent_scope":"media",
 *                      "confirmed_by_user_id":1}
 *     [P11] family-login panel: {"eligible":true,"ineligible_reason":null}
 *     [P11] enable at the attacker address -> 200
 *     [P11] attacker token: /groups 200 · /threads 200 "Safeguarding: incident
 *           on 3 Sept" · /awards 200 "Left the classroom without permission" ·
 *           /hifz 200 sabak 78:1-10
 *
 * The trigger is an ordinary school day. A school imports its parents by phone
 * with no email; children have none by construction. One unauthenticated
 * `POST /api/v1/offerings/{slug}/register` names a registrant with no address
 * (`registrants.*.email` is `nullable`), which plants a second address-less
 * contact under a real parent's name — and the registrar then merges what looks
 * like the same person.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLASS AND NOT A SENTENCE IN A COMMENT
 * ---------------------------------------------------------------------------
 *
 * The same file ALREADY refused this on the ward end, at length, in prose — and
 * the holder end got the opposite treatment twenty lines later. A rule that
 * lives in a paragraph is a rule the next writer can contradict without
 * noticing. So the rule is enforced by the SHAPE of this type instead:
 *
 *  - the address never leaves the object. There is no getter, no
 *    `__toString()`, no `label()` — nothing that hands back a scalar which
 *    could be compared with `===`, and therefore nothing that can make two
 *    absences equal;
 *  - the ONLY comparison is `isTheSamePersonAs()`, which is `false` unless BOTH
 *    sides resolve to something. Two unresolvable identities are not equal —
 *    not to each other, and not to themselves;
 *  - a reader who ignores all of that and writes `ContactIdentity::of($a) !== ContactIdentity::of($b)`
 *    compares two distinct object instances, which is always `true`. That reads
 *    as "the identity changed", which is the FAIL-CLOSED direction: it re-opens
 *    a row that did not need re-opening, costing a click, rather than carrying
 *    a confirmation onto a stranger.
 *
 * ---------------------------------------------------------------------------
 * WHAT AN "IDENTITY" IS HERE, AND WHAT IT IS NOT
 * ---------------------------------------------------------------------------
 *
 * It is THE ADDRESS A CREDENTIAL IS MINTED AGAINST. `GroupAudience::identitiesFor()`
 * resolves a staff principal to a person by `LOWER(email)` and nothing else, and
 * a parent portal sign-in is opened on an address. So "the same identity" means
 * "a credential opened on either row reaches the same mailbox", and nothing
 * wider.
 *
 * TWO USES THIS MUST NEVER BE PUT TO, both of which would re-open a measured
 * hole:
 *
 *  1. A WARD IS NOT READ THROUGH AN ADDRESS. A ward is a child; most have none,
 *     and the siblings who do share the household mailbox
 *     (`OfferingRegistrationsController::resolveRegistrantContact()` argues this
 *     at length and resolves children by name within a household for exactly
 *     this reason). Comparing two wards with this class would exempt two
 *     siblings on one mailbox from being told apart. There is NO address
 *     exemption on the ward end, and there must never be one: any change of ward
 *     retires and re-issues.
 *  2. IT IS NOT AN AUTHORIZATION. Answering `true` says only that a merge
 *     changed nothing a read path can see. Every access decision above the
 *     caller is unchanged.
 *
 * `PHONE IS NOT AN IDENTITY EITHER`, deliberately. A roster SCREEN falls back to
 * a phone number when there is no email, because a phone number is something an
 * operator can act on; but nothing in this application mints a credential
 * against one or resolves a principal by one, so it cannot answer "would a
 * credential reach the same person". Including it here would make two rows
 * "the same identity" on evidence no read path consults.
 */
final class ContactIdentity
{
    /**
     * @param  string|null  $address  the normalised address, or null for "nothing
     *                                on this row can be resolved to a person"
     */
    private function __construct(private readonly ?string $address)
    {
    }

    /**
     * The identity a contact is read through, or an unresolvable one.
     *
     * Normalised as `LOWER(TRIM(email))`, matching `GroupAudience::identitiesFor()`
     * and `OfferingRegistrationsController::normaliseEmail()` — production is
     * utf8mb4_bin (case-SENSITIVE) and the suite runs SQLite, and whether a
     * de-duplication keeps somebody's authority must not depend on which one it
     * is talking to.
     *
     * A null contact, a null email and an empty-or-whitespace email are ONE
     * state here on purpose. The database allows `''` as well as NULL — an
     * import or a hand edit writes one as readily as the other — and a rule that
     * held for NULL and not for `''` would be this defect again with a different
     * spelling.
     */
    public static function of(?Contact $contact): self
    {
        if (! $contact instanceof Contact) {
            return new self(null);
        }

        $address = Str::lower(trim((string) $contact->email));

        return new self($address === '' ? null : $address);
    }

    /**
     * Is there anything here that a read path could resolve to a person?
     *
     * Exposed because the answer is worth SAYING on a screen — an operator
     * deciding a merge needs to know that neither record can be told apart —
     * and never because a caller should branch on it before comparing.
     */
    public function isResolvable(): bool
    {
        return $this->address !== null;
    }

    /**
     * Would a credential opened on either row reach the same person?
     *
     * FALSE WHENEVER EITHER SIDE IS UNRESOLVABLE, including when BOTH are. Two
     * rows that nothing can resolve are not "the same person"; they are two rows
     * about which the question cannot be answered, and the only safe answer to a
     * question that cannot be answered is the one that grants nothing.
     */
    public function isTheSamePersonAs(self $other): bool
    {
        if ($this->address === null || $other->address === null) {
            return false;
        }

        return hash_equals($this->address, $other->address);
    }

    /**
     * Did a merge from `$from` onto `$to` change the identity the row is read
     * through?
     *
     * The negation is written HERE, once, rather than at each call site, so that
     * "not the same person" and "changed" cannot drift apart — and so that the
     * `!` that turns a safe answer into an unsafe one is not something a caller
     * has to remember to write.
     */
    public static function changed(?Contact $from, ?Contact $to): bool
    {
        return ! self::of($from)->isTheSamePersonAs(self::of($to));
    }
}
