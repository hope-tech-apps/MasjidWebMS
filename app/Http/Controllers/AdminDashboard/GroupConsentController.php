<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\RecordGuardianConsentRequest;
use App\Models\Group;
use App\Models\GroupMembership;
use Symfony\Component\HttpFoundation\Response;

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

        $membership->update([
            'consent_scope' => $request->input('scope'),
            'consent_granted_at' => $request->filled('granted_at')
                ? $request->date('granted_at')
                : now(),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $membership->fresh()->load(['contact', 'guardianOf']),
        ], Response::HTTP_OK);
    }

    /**
     * DELETE .../groups/{group_id}/members/{membership_id}/consent
     *
     * Withdraws consent. Both columns go back to null, which is the SAME state
     * as never having consented — because that is exactly what withdrawal
     * means here, and because "absence of a record means no consent" only works
     * if the absent state is reachable.
     */
    public function destroy($masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $membership->update([
            'consent_granted_at' => null,
            'consent_scope' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $membership->fresh()->load(['contact', 'guardianOf']),
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
}
