<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\GroupMembership;
use App\Models\Registration;
use Illuminate\Support\Collection;

/**
 * WHAT SEPARATES ONE ROSTER CLAIM FROM ANOTHER ONE THAT LOOKS THE SAME.
 *
 * `GroupMembershipsController::confirm` is a disclosure decision about a named
 * child, and until this class existed the screen it is pressed from rendered two
 * strings — the adult's name and the ward's name. Two rows carrying the same two
 * strings are not the same relationship, and the office had nothing on screen to
 * tell them apart. Measured, on the wire, before this existed:
 *
 *     GUARDIAN ROW #2  "Aisha Ahmed" -> "Fatima Ahmed"  [self_asserted]
 *     GUARDIAN ROW #7  "Aisha Ahmed" -> "Fatima Ahmed"  [self_asserted]
 *
 *       #2  contact email aisha@household.test   registration 1, payer contact 2
 *       #7  contact email bilal@evil.test        registration 4, payer contact 7
 *
 * Row #7 came from an anonymous POST by a stranger who knew a child's name and
 * her household address, typed the MOTHER's name as payer and his own email.
 * `GroupRosterTab.vue` contained `source_registration` zero times and rendered
 * no address on either roster table, so "Confirm all 6" was one press and his
 * bearer token then read her behaviour record.
 *
 * Three things live here, and they are three answers to one question — CAN THE
 * OPERATOR TELL THESE TWO ROWS APART?
 *
 *  1. `identity()` — the bytes that separate them, assembled for the screen:
 *     the claimant's own address, and the signup + payer that asserted the row.
 *     Rendered by the roster tables and enumerated in the confirm dialog.
 *  2. `contestedClaimIds()` — the shape where rendering is not enough, because
 *     the two rows READ the same: more than one guardian row over ONE ward whose
 *     displayed names collide. Those are lifted OUT of the bulk sweep and have
 *     to be decided one at a time (`GroupMembershipsController::confirm`).
 *  3. `fingerprint()` — what the caller must echo back to prove the row it is
 *     confirming is still the row it drew. A merge re-points `contact_id` on a
 *     pending claim and the row id does not change, so an id alone does not
 *     identify what an operator agreed to.
 *
 * WHY THIS IS NOT ON THE MODEL. `GroupMembership` answers "what is this row";
 * these three answer "what could an operator mistake this row for", which is a
 * question about a row's NEIGHBOURS and about a screen. Keeping it out of the
 * model also keeps the fingerprint's definition in exactly one place that both
 * the index payload and the confirm verb call — the property that stops the two
 * drifting into a check that always passes.
 */
final class RosterClaimIdentity
{
    /** An authenticated staff act stands behind this row; there is nothing to judge. */
    public const ORIGIN_CONFIRMED = 'confirmed';

    /** A public registration asserted it, and the signup + payer are on record. */
    public const ORIGIN_REGISTRATION = 'registration';

    /**
     * A public registration asserted it and the payer can no longer be named.
     *
     * MEASURED, and the reason this state is spelled out instead of falling back
     * to "no evidence": after a merge force-deletes an absorbed payer contact,
     * `registrations.contact_id` is `nullOnDelete`, so the roster payload reads
     * `"source_registration":{"id":2,"offering_id":1,"contact_id":null}` — the
     * one field `ConfirmGroupMembershipsRequest` calls the judgeable evidence is
     * degraded by an ordinary de-duplication. A screen that renders that as
     * blank is the F9 failure again with a different cause.
     */
    public const ORIGIN_PAYER_UNAVAILABLE = 'registration_payer_unavailable';

    /**
     * Nothing on record says where this claim came from.
     *
     * Two ways in, and they are indistinguishable in the row: the source
     * registration was purged (`nullOnDelete` on `source_registration_id`), or
     * `RosterMergeService` dropped a staff-confirmed edge back to a claim via
     * `GroupMembership::unconfirm()`, which clears the confirming actor. The
     * screen says exactly that rather than guessing at either.
     */
    public const ORIGIN_UNRECORDED = 'unrecorded';

    /**
     * The identity of one roster row, as the office has to read it.
     *
     * `contact` is already on the row and carries the address; what is added
     * here is the PROVENANCE half — which signup asserted the claim and who paid
     * for it — plus an explicit state for every way that evidence can be
     * missing, because "nothing" is what F9 rendered.
     *
     * @return array{state: string, registration_id: int|null, payer: array{id: int, first_name: string|null, last_name: string|null, email: string|null}|null}
     */
    public static function origin(GroupMembership $membership): array
    {
        if ($membership->isConfirmed()) {
            return [
                'state' => self::ORIGIN_CONFIRMED,
                'registration_id' => $membership->source_registration_id === null
                    ? null
                    : (int) $membership->source_registration_id,
                'payer' => null,
            ];
        }

        $registration = self::sourceRegistration($membership);

        if ($registration === null) {
            return [
                'state' => self::ORIGIN_UNRECORDED,
                'registration_id' => null,
                'payer' => null,
            ];
        }

        $payer = self::payer($registration);

        return [
            'state' => $payer === null ? self::ORIGIN_PAYER_UNAVAILABLE : self::ORIGIN_REGISTRATION,
            'registration_id' => (int) $registration->getKey(),
            'payer' => $payer === null ? null : [
                'id' => (int) $payer->getKey(),
                'first_name' => $payer->first_name,
                'last_name' => $payer->last_name,
                'email' => $payer->email,
            ],
        ];
    }

    /**
     * WHAT THE CALLER MUST ECHO BACK TO PROVE IT READ THIS ROW.
     *
     * Round five bound the operator's agreement to a list of ids, which is
     * airtight against a row INSERTED after the draw and useless against a row
     * that MUTATED after the draw: `RosterMergeService` re-points `contact_id`
     * on a pending claim and the id does not move, so the operator confirms a
     * relationship they never read.
     *
     * Every component is something the screen renders, or something the screen
     * renders is derived from:
     *
     *   - `id`, so a fingerprint cannot be replayed against a different row;
     *   - `role` + `contact_id` + `guardian_of_contact_id`, which are WHO is
     *     claiming WHAT over WHOM — the three a merge can move;
     *   - `provenance`, so a row somebody else confirmed meanwhile is not
     *     silently re-confirmed under a stale description;
     *   - `source_registration_id` AND the registration's own `contact_id`,
     *     because the payer is the evidence the office is asked to judge and it
     *     degrades to null on a merge WITHOUT the membership row changing at
     *     all. Fingerprinting only the membership would call that row unchanged.
     *
     * DELIBERATELY NOT INCLUDED: the contact's own name, email and phone. An
     * administrator fixing a typo in one parent's address would otherwise
     * invalidate that row mid-dialog — a refusal aimed at ordinary work — while
     * the identity it is standing in for (`contact_id`) has not moved. The
     * address is re-read from live data on the next draw either way.
     *
     * NOT A SECRET AND NOT AN AUTHORIZATION TOKEN. It is a change detector: it
     * is computed from data the caller was just served, it is compared with
     * `hash_equals` only to keep the comparison total, and holding one grants
     * nothing — every access decision above it (`auth:sanctum`, `admin`,
     * `tenant`, `permission:manage contacts`) is unchanged and unmoved.
     */
    public static function fingerprint(GroupMembership $membership): string
    {
        $registration = self::sourceRegistration($membership);

        return substr(hash('sha256', implode('|', [
            'roster-claim-v1',
            (int) $membership->getKey(),
            (string) $membership->role,
            (string) $membership->provenance,
            (int) $membership->contact_id,
            $membership->guardian_of_contact_id === null ? '-' : (int) $membership->guardian_of_contact_id,
            $membership->source_registration_id === null ? '-' : (int) $membership->source_registration_id,
            $registration === null
                ? 'no-registration'
                : ($registration->contact_id === null ? 'payer-unavailable' : (int) $registration->contact_id),
        ])), 0, 32);
    }

    /**
     * THE ROWS AN OPERATOR CANNOT TELL APART, so a sweep must not decide them.
     *
     * A ward is CONTESTED when two or more guardian rows in the same group point
     * at them from DIFFERENT contacts whose displayed names collide — which is
     * F9 exactly: "Aisha Ahmed -> Fatima Ahmed" twice, one the mother and one a
     * stranger who typed her name. Every PENDING row in such a set is returned;
     * `confirm()` refuses to sweep those and requires each to be named as an
     * individual decision.
     *
     * WHY NAME COLLISION AND NOT SIMPLY "MORE THAN ONE GUARDIAN".
     *
     * Two guardians over one child is the ordinary case — a mother and a father,
     * two different names, two different addresses, both legible on the screen —
     * and lifting all of those out of the sweep would put a school's camp intake
     * back into one-dialog-per-family, which is the over-refusal this whole
     * affordance exists to avoid. The hazard is not plurality; it is
     * INDISTINGUISHABILITY. Same rendered name, different human, one child.
     *
     * A CONFIRMED rival counts as a rival. Otherwise the same attack survives in
     * two steps: let the office confirm the mother this week, and the stranger's
     * identical row sweeps through next week beside a row that now looks settled.
     *
     * AN UNRENDERABLE NAME IS ALWAYS CONTESTED. A row whose contact is missing
     * shows the operator nothing at all, which is the strongest form of "cannot
     * tell it apart" — so it fails closed into the individual-decision path
     * rather than out of it.
     *
     * @param  Collection<int, GroupMembership>  $roster  every membership of ONE group
     * @return array<int, int> membership ids of the pending, contested claims
     */
    public static function contestedClaimIds(Collection $roster): array
    {
        $guardianRows = $roster->filter(
            fn (GroupMembership $m): bool => $m->role === GroupMembership::ROLE_GUARDIAN
                && $m->guardian_of_contact_id !== null
        );

        $contested = [];

        foreach ($guardianRows->groupBy('guardian_of_contact_id') as $overOneWard) {
            foreach ($overOneWard->groupBy(fn (GroupMembership $m): string => self::nameKey($m)) as $nameKey => $sameName) {
                $distinctClaimants = $sameName->pluck('contact_id')->unique();

                // One human holding one edge over one child is not a contest,
                // however that row got there.
                if ($nameKey !== '' && $distinctClaimants->count() < 2) {
                    continue;
                }

                foreach ($sameName as $row) {
                    if ($row->isPendingClaim()) {
                        $contested[] = (int) $row->getKey();
                    }
                }
            }
        }

        sort($contested);

        return array_values(array_unique($contested));
    }

    /**
     * The other guardian rows an operator could confuse THIS one with.
     *
     * Rendered beside the row and enumerated in its dialog, so "decide it
     * individually" means deciding between two addresses rather than staring at
     * one row twice.
     *
     * @param  Collection<int, GroupMembership>  $roster
     * @return array<int, int>
     */
    public static function rivalClaimIds(GroupMembership $membership, Collection $roster): array
    {
        if ($membership->role !== GroupMembership::ROLE_GUARDIAN || $membership->guardian_of_contact_id === null) {
            return [];
        }

        $key = self::nameKey($membership);

        // `guardian_of_contact_id !== null` is stated rather than left to the
        // cast, and it is BEHAVIOUR-PRESERVING: contact ids start at 1, so
        // `(int) null === (int) $ward` was already false for every real ward.
        // What it removes is the SHAPE — two absences meeting in a `===` — which
        // is the comparison this area shipped as a disclosure once already. A
        // ward-less guardian row is inert anyway (`GroupAudience` and
        // `FamilyAccessService` both require `whereNotNull('guardian_of_contact_id')`),
        // so it is not a rival that could grant anything.
        return $roster
            ->filter(fn (GroupMembership $m): bool => $m->getKey() !== $membership->getKey()
                && $m->role === GroupMembership::ROLE_GUARDIAN
                && $m->guardian_of_contact_id !== null
                && (int) $m->guardian_of_contact_id === (int) $membership->guardian_of_contact_id
                && (self::nameKey($m) === $key || $key === '' || self::nameKey($m) === ''))
            ->map(fn (GroupMembership $m): int => (int) $m->getKey())
            ->sort()
            ->values()
            ->all();
    }

    // ------------------------------------------------------------- internals

    /**
     * The displayed name, folded so that "Aisha  Ahmed" and "aisha ahmed" are
     * the same key — the operator reads them as the same string, so the guard
     * has to as well. An empty key means the row renders no name at all.
     */
    private static function nameKey(GroupMembership $membership): string
    {
        $contact = $membership->relationLoaded('contact') ? $membership->getRelation('contact') : null;

        if (! $contact instanceof Contact) {
            return '';
        }

        $name = trim(preg_replace('/\s+/u', ' ', trim((string) $contact->first_name . ' ' . (string) $contact->last_name)));

        return $name === '' ? '' : mb_strtolower($name);
    }

    /**
     * The registration that asserted this row, WITHOUT reaching for the database.
     *
     * Both callers eager-load it. Lazy-loading here would put one query per row
     * behind a 200-row roster listing and behind every confirm sweep, and — worse
     * for a guard — would silently succeed in tests that never loaded it, hiding
     * the case where the relation is genuinely absent.
     */
    private static function sourceRegistration(GroupMembership $membership): ?Registration
    {
        if (! $membership->relationLoaded('sourceRegistration')) {
            return null;
        }

        $registration = $membership->getRelation('sourceRegistration');

        return $registration instanceof Registration ? $registration : null;
    }

    private static function payer(Registration $registration): ?Contact
    {
        if ($registration->contact_id === null || ! $registration->relationLoaded('contact')) {
            return null;
        }

        $payer = $registration->getRelation('contact');

        return $payer instanceof Contact ? $payer : null;
    }
}
