<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\RecordGuardianConsentRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\RosterMove;
use App\Support\RosterMovePlan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guardian consent, recorded against ONE guardian edge (PLAN T-005b).
 *
 * .claude/rules/groups.md, obligation 2: "A guardian edge records a
 * relationship, NOT consent." This controller is where the separate act of
 * consenting is written down; App\Support\GroupAudience is where it is CHECKED,
 * at the point of disclosure. Neither half is optional — a recorded consent
 * nobody reads is paperwork, and a check with nothing to read is a guess.
 *
 * Why the edge and not the person: the edge already answers "guardian of WHOM,
 * in WHICH group", so consent recorded here cannot leak sideways to the parent's
 * other children or to their other groups. A parent with two children in one
 * classroom consents twice, once per child, because those are two different
 * decisions.
 *
 * ## SINCE A MOVE CARRIES CONSENT (2026-10-05)
 *
 * A student who is moved takes each guardian's consent along as it was
 * recorded: the move copies the two columns onto the entry it creates in the
 * new class and marks that entry with the class it came from
 * (`GroupMembership::carriedFrom`, THE MARKER). So one adult and one child can
 * hold consent in two classes, and the two verbs here each owe the office one
 * more thing:
 *
 *   - RECORDING clears the marker, also when the form is saved unchanged: the
 *     office is now asserting this consent for this class, and it is no longer
 *     "carried". EXCEPT a record of LESS than the class it was carried from
 *     still holds: that keeps the marker. The family reduced what was
 *     carried, and the kept marker is what lets a later move refuse to bring
 *     the wider consent of the other class back into force.
 *   - WITHDRAWING keeps the marker and writes its two columns exactly as it
 *     always did. It still cannot be refused and needs nothing new. The kept
 *     marker is what tells a later move that the family took a carried consent
 *     back (`RosterMove::consentComingBack`).
 *   - BOTH ANSWER `notes`: where consent for this adult still stands, so a
 *     family that meant "not at all" is not left receiving the class through
 *     another entry. The same class first (an adult is admitted to a class's
 *     story on ANY one of their current entries there, so withdrawing for one
 *     child leaves it open through a brother's or sister's), then the same
 *     child's other classes, current or closed. Sentences, built here.
 *
 * Tenant isolation is the guardrail, not hand-filtering: Group and
 * GroupMembership are both BelongsToMasjid, so another organization's group or
 * membership id is a 404 miss. See .claude/rules/tenant-scoping.md.
 */
class GroupConsentController extends Controller
{
    /**
     * PUT .../groups/{group_id}/members/{membership_id}/consent
     *
     * Records (or re-scopes) consent on a guardian edge. Idempotent: recording
     * `media` over an existing `feed` widens it, recording `feed` over `media`
     * narrows it, and both are ordinary corrections an office makes.
     */
    public function update(RecordGuardianConsentRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        // Consent belongs to a guardian edge and nowhere else. A leader or
        // member IS the person; consenting on their behalf would be recording
        // permission from somebody who was never asked.
        if (! $membership->isGuardian()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Consent is recorded against a guardian membership, not a participant.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // …AND ONLY AGAINST AN EDGE THIS ORGANISATION HAS STOOD BEHIND.
        //
        // A pending claim is an assertion a public form made — nobody has yet
        // decided that this adult is that child's guardian. Recording consent
        // against it banks a permission from a relationship the organisation has
        // not accepted, and it is stored on the row itself: the moment the claim
        // is confirmed, the class feed and the photograph bytes open in the same
        // instant as the records, with no second decision in between.
        //
        // Withdrawal (`destroy`) is deliberately NOT gated — see its docblock. A
        // refusal there would leave consent standing on a row the office was
        // trying to undo, which is this area's one unacceptable direction.
        if (! $membership->isConfirmed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This guardian entry is still an unconfirmed claim from a registration form. '
                    . 'Confirm it on the roster first — consent has to be recorded against a relationship '
                    . 'this organisation has stood behind.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $recorded = [
            'consent_scope' => $request->input('scope'),
            'consent_granted_at' => $request->filled('granted_at')
                ? $request->date('granted_at')
                : now(),
        ];

        // A consent the office records is this class's own from now on, so
        // "carried from another class" goes, even when nothing else changed.
        // The marker is not fillable, hence `forceFill`; the key is written
        // only once `migrate` has added its column, and before that there is
        // no marker to clear.
        //
        // UNLESS WHAT IS RECORDED IS LESS THAN THE OTHER CLASS STILL HOLDS
        // (the class story here, photographs there). That is a family
        // reducing a consent that was carried, on a copy that was marked or
        // on one they had withdrawn. With the marker gone nothing would tell
        // a later move that the wider consent of the other class must not
        // come back into force, and it would, the day the student went back.
        // So the marker stays, exactly as it does on a withdrawal.
        if (GroupMembership::consentCarryReady()) {
            $source = $membership->carriedConsentSource();
            $less = $source !== null && $source->consentRank() > GroupMembership::consentRankOf($recorded['consent_scope']);

            if (! $less) {
                $recorded[GroupMembership::CONSENT_CARRIED_FROM] = null;
            }
        }

        $membership->forceFill($recorded)->save();

        $saved = $membership->fresh()->load(['contact', 'guardianOf']);

        return response()->json([
            'status' => 'success',
            'data' => $this->serialised($saved),
            'notes' => $this->notes($group, $saved),
        ], Response::HTTP_OK);
    }

    /**
     * DELETE .../groups/{group_id}/members/{membership_id}/consent
     *
     * Withdraws consent. Both columns go back to null, which is the SAME state
     * as never having consented — because that is exactly what withdrawal
     * means here, and because "absence of a record means no consent" only works
     * if the absent state is reachable.
     *
     * TWO KEYS, AS ALWAYS, AND NEVER REFUSED. It does not touch the marker of a
     * carried consent, on purpose (see the class docblock), and it does not
     * depend on that column existing. The `notes` are read AFTER the write and
     * a failure in that read is swallowed: nothing may turn a withdrawal into
     * an error.
     */
    public function destroy($masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $membership->update([
            'consent_granted_at' => null,
            'consent_scope' => null,
        ]);

        $saved = $membership->fresh()->load(['contact', 'guardianOf']);

        return response()->json([
            'status' => 'success',
            'data' => $this->serialised($saved),
            'notes' => $this->notes($group, $saved),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../groups/{group_id}/members/{membership_id}/consent
     *
     * What is on the record for this edge. Deliberately explicit about the two
     * scopes rather than making the caller re-derive the hierarchy.
     *
     * ## THE READ NOW ANSWERS THE SAME QUESTION THE WRITE REFUSES
     *
     * `update()` above has always refused to record consent against a pending
     * claim. This method had no such gate and reported the two columns
     * whatever stood in them, so the pair said opposite things about one row:
     *
     *     GET  …/consent -> 200 {"scope":"media","covers_feed":true,
     *                            "covers_media":true}
     *     PUT  …/consent -> 422 "still an unconfirmed claim … Confirm it first"
     *
     * The gate itself now lives in `GroupMembership::hasConsent()`, so this
     * endpoint, `consentCovers()` and `App\Support\GroupAudience` cannot drift;
     * what belongs HERE is saying why the answer is empty.
     *
     * AND THE REASON IS PRINTED RATHER THAN LEFT AS A BLANK. `scope: null` on a
     * row whose `consent_scope` column reads `media` is the same defect one
     * screen over — "a blank is the thing the operator reads straight past"
     * (`GroupRosterTab.vue`). So the payload says outright that a record exists,
     * that it grants nothing, and what would have to happen first. It is safe to
     * say: this tree is `admin`-gated behind `permission:view contacts`, and the
     * only new bytes are a boolean and a sentence about this organisation's own
     * roster.
     */
    public function show($masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $effective = $membership->hasConsent();

        // A record on the row that grants nothing, because the row is a claim.
        $withheld = ! $effective
            && $membership->isPendingClaim()
            && $membership->consentColumnsAreSet();

        return response()->json([
            'status' => 'success',
            'data' => [
                'membership_id' => $membership->id,
                'role' => $membership->role,
                'guardian_of_contact_id' => $membership->guardian_of_contact_id,
                // Nulled with the scope rather than reported beside it. A date
                // is read as "consent was given on…", and this endpoint's answer
                // for an unconfirmed claim is that no consent is in force.
                'granted_at' => $effective
                    ? optional($membership->consent_granted_at)->toIso8601String()
                    : null,
                'scope' => $effective ? $membership->consent_scope : null,
                'covers_feed' => $membership->consentCovers(GroupMembership::CONSENT_FEED),
                'covers_media' => $membership->consentCovers(GroupMembership::CONSENT_MEDIA),
                'withheld_pending_confirmation' => $withheld,
                'withheld_reason' => $withheld
                    ? 'A consent record is on this entry, but the entry is still an unconfirmed claim '
                        . 'from a registration form, so it grants nothing. Confirm the guardian entry on '
                        . 'the roster and then record consent again — the earlier record was given about a '
                        . 'relationship this organisation has not stood behind.'
                    : null,
            ],
            'meta' => ['scopes' => GroupMembership::CONSENT_SCOPES],
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * The entry as both writes answer it: the roster row, and the one thing
     * about a carried consent the row cannot say by itself, which the roster
     * list computes for every row and the screen must not have to wait for:
     * `consent_less_than_carried_from`, true when this entry is marked, holds
     * consent, and holds less than the class it was carried from does. A read
     * of one row; before the marker column exists it is false.
     *
     * @return array<string, mixed>
     */
    private function serialised(GroupMembership $saved): array
    {
        return array_merge($saved->toArray(), [
            'consent_less_than_carried_from' => GroupMembership::consentCarryReady()
                && RosterMove::holdingLessThanCarried(collect([$saved]))->isNotEmpty(),
        ]);
    }

    /**
     * The notes of an answer, or none when they could not be read. The write
     * has already happened: a fault here is logged at WARNING (production
     * drops anything lower), by its class and never its message, and the
     * answer goes out without notes.
     *
     * @return list<string>
     */
    private function notes(Group $group, GroupMembership $entry): array
    {
        try {
            return $this->whereConsentStillStands($group, $entry);
        } catch (Throwable $e) {
            Log::warning('group.consent.notes_failed', [
                'membership' => (int) $entry->getKey(),
                'error' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * WHERE THIS ADULT'S CONSENT STILL STANDS, after the entry was written.
     *
     *   1. THE SAME CLASS FIRST: the adult's other current entries in this
     *      class, for any child, that open MORE than this entry now does.
     *      After a withdrawal that is every one of them with consent; after a
     *      record it is none unless the office narrowed.
     *   2. Then every other entry of the same adult for the SAME child, in any
     *      class of the organisation that still exists, that holds consent.
     *      A current one still stands; a closed one comes back into force if
     *      the child returns there.
     *
     * Ordinary reads, made after the write and never locked.
     *
     * @return list<string>
     */
    protected function whereConsentStillStands(Group $group, GroupMembership $entry): array
    {
        if (! $entry->isGuardian()) {
            return [];
        }

        $guardian = self::nameOf($entry->contact);
        $child = self::nameOf($entry->guardianOf);
        $notes = [];

        $sameClass = GroupMembership::query()
            ->where('group_id', $group->getKey())
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('contact_id', $entry->contact_id)
            ->whereKeyNot($entry->getKey())
            ->whereNull('left_on')
            ->with('guardianOf:id,first_name,last_name')
            ->orderBy('id')
            ->get();

        foreach ($sameClass as $other) {
            $opensMore = collect([GroupMembership::CONSENT_FEED, GroupMembership::CONSENT_MEDIA])
                ->contains(fn (string $disclosure): bool => $other->consentCovers($disclosure) && ! $entry->consentCovers($disclosure));

            if ($opensMore) {
                $notes[] = "{$guardian} still receives {$group->name}'s class story through their entry for "
                    .self::nameOf($other->guardianOf).' ('.RosterMovePlan::scopeWords((string) $other->consent_scope).'). '
                    .'Withdraw that too if the family meant the whole class.';
            }
        }

        // Tenant-scoped through the model, as every read in this controller:
        // it never names another organisation's class.
        $elsewhere = GroupMembership::query()
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('contact_id', $entry->contact_id)
            ->where('guardian_of_contact_id', $entry->guardian_of_contact_id)
            ->where('group_id', '!=', $group->getKey())
            ->orderBy('id')
            ->get()
            ->filter(fn (GroupMembership $other): bool => $other->hasConsent());

        if ($elsewhere->isEmpty()) {
            return $notes;
        }

        $classes = Group::query()->whereIn('id', $elsewhere->pluck('group_id')->unique()->values())->pluck('name', 'id');

        foreach ($elsewhere as $other) {
            $class = $classes->get($other->group_id);

            // A class that was deleted is not somewhere consent can be in force.
            if ($class === null) {
                continue;
            }

            $held = RosterMovePlan::consentWords((string) $other->consent_scope, $other->consent_granted_at?->toDateString());

            $notes[] = $other->left_on === null
                ? "Consent for {$guardian} about {$child} still stands in {$class} ({$held}). Withdraw it there too if "
                    .'the family meant both.'
                : "Consent for {$guardian} about {$child} is still on record in {$class} ({$held}) and comes back into "
                    ."force if {$child} returns there. Withdraw it there too if the family meant both.";
        }

        return $notes;
    }

    private static function nameOf(?Contact $contact): string
    {
        $name = trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''));

        return $name === '' ? 'This person' : $name;
    }
}
