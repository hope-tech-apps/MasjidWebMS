<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreGroupMembershipRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use Symfony\Component\HttpFoundation\Response;

/**
 * Roster management for a group: who is in it, and out of it.
 *
 * Tenant isolation is the guardrail, not hand-filtering: every Group, Contact and
 * GroupMembership lookup below runs through the BelongsToMasjid global scope
 * bound by the `tenant` middleware, so another organization's id — a group, a
 * contact, a membership — is a MISS (404), never a filtered-away row. The
 * {masjid_id} route parameter is never used as a query condition. See
 * .claude/rules/tenant-scoping.md.
 *
 * Guardianship is an explicit edge: a `guardian` membership also names the member
 * it is attached to (`guardian_of_contact_id`). The two invariants this
 * controller owns, on top of the shape checks in StoreGroupMembershipRequest:
 *   1. the ward must already hold a participant (leader/member) membership in
 *      THIS group — a guardian edge to someone who is not in the group grants
 *      access to a child nobody put here;
 *   2. no duplicate membership, which the DB cannot express because both MySQL
 *      and SQLite treat the NULL ward on a leader/member row as distinct.
 */
class GroupMembershipsController extends Controller
{
    /** The roster of one group, newest additions last. */
    public function index($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $memberships = $group->memberships()
            ->with(['contact', 'guardianOf'])
            ->orderBy('role')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $memberships,
        ], Response::HTTP_OK);
    }

    /**
     * Add a contact to the group.
     *
     * masjid_id is intentionally omitted from the create payload — the
     * BelongsToMasjid creating hook stamps it from the bound tenant.
     */
    public function store(StoreGroupMembershipRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        // Scoped lookup, so a contact from another organization is a 404 miss
        // rather than a membership quietly created across the tenant boundary.
        $contact = Contact::findOrFail($request->integer('contact_id'));

        $role = $request->input('role');
        $wardId = null;

        if ($role === GroupMembership::ROLE_GUARDIAN) {
            $ward = Contact::findOrFail($request->integer('guardian_of_contact_id'));
            $wardId = $ward->id;

            $wardIsInGroup = $group->memberships()
                ->participants()
                ->where('contact_id', $ward->id)
                ->exists();

            if (! $wardIsInGroup) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That member is not in this group, so nobody can be linked as their guardian here.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $duplicate = $group->memberships()
            ->where('contact_id', $contact->id)
            ->where('role', $role)
            ->when($wardId === null,
                fn ($q) => $q->whereNull('guardian_of_contact_id'),
                fn ($q) => $q->where('guardian_of_contact_id', $wardId),
            )
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'That person already holds this membership in this group.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $membership = GroupMembership::create([
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $wardId,
            'joined_at' => $request->input('joined_at'),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $membership->load(['contact', 'guardianOf']),
        ], Response::HTTP_CREATED);
    }

    /**
     * Remove a membership.
     *
     * Resolved through the group so a membership id from another group — or
     * another organization — is a 404. Deleting a participant also removes the
     * guardian edges that pointed at them in this group; that happens in
     * GroupMembership's `deleting` hook, so it holds for every caller, not just
     * this one.
     */
    public function destroy($masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);

        $membership = $group->memberships()->findOrFail($membership_id);
        $membership->delete();

        return response()->json([
            'status' => 'success',
            'data' => $membership,
        ], Response::HTTP_OK);
    }
}
