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
     */
    public function show($masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'membership_id' => $membership->id,
                'role' => $membership->role,
                'guardian_of_contact_id' => $membership->guardian_of_contact_id,
                'granted_at' => optional($membership->consent_granted_at)->toIso8601String(),
                'scope' => $membership->hasConsent() ? $membership->consent_scope : null,
                'covers_feed' => $membership->consentCovers(GroupMembership::CONSENT_FEED),
                'covers_media' => $membership->consentCovers(GroupMembership::CONSENT_MEDIA),
            ],
            'meta' => ['scopes' => GroupMembership::CONSENT_SCOPES],
        ], Response::HTTP_OK);
    }
}
