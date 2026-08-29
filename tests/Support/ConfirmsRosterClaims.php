<?php

namespace Tests\Support;

use App\Models\GroupMembership;
use App\Support\RosterClaimIdentity;

/**
 * The confirm endpoint's wire shape, for tests whose subject is something else.
 *
 * `POST …/members/confirm` requires the caller to echo back, per named id, the
 * fingerprint the roster listing served with that row — the guard that makes a
 * row MUTATED between the draw and the click a skip rather than a silent
 * confirmation. Building that body is three lines of noise in a test about
 * tenant scoping or the provenance lifecycle, so it lives here once.
 *
 * WHAT THIS DELIBERATELY DOES NOT PROVE. It computes the fingerprint from the
 * same live rows the endpoint will compare against, so it always matches — it
 * makes a request WELL-FORMED and it cannot demonstrate that the guard works.
 * That is `RosterClaimIdentityTest`'s job, and it does it the only way that
 * counts: by drawing the roster through the API and sending back what the
 * payload actually said.
 */
trait ConfirmsRosterClaims
{
    /**
     * @param  array<int, int>  $membershipIds
     * @param  array<int, int>  $decidedIndividually  contested rows the operator resolved one at a time
     * @return array<string, mixed>
     */
    protected function confirmBody(array $membershipIds, array $decidedIndividually = []): array
    {
        $rows = GroupMembership::withoutMasjidScope()
            ->whereIn('id', $membershipIds)
            ->with([
                'contact:id,first_name,last_name,email,phone',
                'sourceRegistration:id,offering_id,contact_id',
                'sourceRegistration.contact:id,first_name,last_name,email',
            ])
            ->get();

        $fingerprints = [];

        foreach ($membershipIds as $id) {
            $row = $rows->firstWhere('id', $id);

            // An id naming no row still has to be DESCRIBED, or the request is
            // malformed rather than a no-op — and "a stale screen naming a row
            // somebody else removed is an honest no-op" is a property under test
            // in several of these files.
            $fingerprints[(string) $id] = $row === null
                ? 'this-row-is-gone'
                : RosterClaimIdentity::fingerprint($row);
        }

        $body = [
            'membership_ids' => array_values($membershipIds),
            'fingerprints' => $fingerprints,
        ];

        if ($decidedIndividually !== []) {
            $body['contested_membership_ids'] = array_values($decidedIndividually);
        }

        return $body;
    }
}
