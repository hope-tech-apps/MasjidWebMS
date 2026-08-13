<?php

namespace App\Http\Controllers\Family;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\GroupAudience;
use Symfony\Component\HttpFoundation\Response;

/**
 * The parent's own list of groups, and the children they hold in each (T-015e).
 *
 * This is the entry point of the whole family surface: every other endpoint is
 * addressed by a group id and a MEMBERSHIP id, and this is where a parent
 * discovers both. Before T-015e there was no way to discover either, which is
 * why an authenticated parent could see nothing at all.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DISCLOSED HERE, AND WHAT IS NOT
 * ---------------------------------------------------------------------------
 *
 * Disclosed: the groups this contact holds a membership row in, their names and
 * kinds, and this parent's OWN wards inside them.
 *
 * NOT disclosed, and it must stay that way: the roster. There is no list of the
 * group's members, no count of them, no leader's name and no other family's
 * child anywhere in this payload. A class list is a list of children, and
 * .claude/rules/groups.md obligation 4 makes least disclosure the default for
 * every new group-scoped read — a parent needs to know their own child is in
 * "Grade 3", not who else is.
 *
 * The listing query itself is the guarantee, not a filter applied afterwards:
 * `whereHas('memberships', contact_id = me)` means a group this parent has no
 * row in is never fetched, so it cannot leak through a count, a paginator total
 * or a stray serializer.
 */
class GroupsController extends FamilyController
{
    /**
     * GET /api/family/masjids/{masjid_id}/groups
     *
     * Every group this parent stands in — as a guardian, or as a participant in
     * their own right (a masjid volunteer team is the same primitive as a
     * classroom).
     */
    public function index()
    {
        $contact = $this->contact();

        $groups = Group::query()
            ->whereHas('memberships', fn ($q) => $q->where('contact_id', $contact->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        // The QUERY above is still the guarantee against every group this
        // contact holds no row in — a group they are not on is never fetched, so
        // it cannot leak through a count or a paginator total. This narrows
        // WITHIN that already-safe set, to the rows the credential speaks
        // through, using the same call `show()` refuses with: a listing that
        // offered a group whose `show()` answers 403 is the two halves
        // disagreeing about one caller.
        $groups = $groups->filter(fn (Group $group) => $this->hasStanding($group))->values();

        return response()->json([
            'status' => 'success',
            'data' => $groups->map(fn (Group $group) => $this->serialize($group))->values()->all(),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * GET /api/family/masjids/{masjid_id}/groups/{group_id}
     *
     * 403 rather than 404 when the parent has no standing: the group exists in
     * their organisation and they simply are not in it, which is the same call
     * the staff feed makes. Another organisation's id never reaches this line —
     * the tenant scope has already turned it into a 404.
     */
    public function show($masjid_id, $group_id)
    {
        $group = $this->group($group_id);

        if (! $this->hasStanding($group)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this group.');
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($group),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * Is this parent in this group at all?
     *
     * Still not `GroupAudience::mayReceive()`, because those are different
     * questions and conflating them would be a bug in both directions.
     * `mayReceive(feed)` is false for a guardian who has never consented — but
     * such a parent IS in the group and must still be able to see that their
     * child is enrolled and to reach the participant thread about them, which
     * consent deliberately does not gate (.claude/rules/groups.md, "consent
     * gates broadcasts, not conversations").
     *
     * It IS now `GroupAudience::membershipsFor()` rather than a `where` on the
     * table. The direct query was a second definition of standing living outside
     * the class that owns it, and it disagreed the moment the credential rule
     * changed: a parent-portal credential speaks through its holder's GUARDIAN
     * EDGES and not through their own participant rows, so a parent who is
     * merely enrolled in the adult ḥalaqa gets no portal standing there — while
     * this method went on answering true, listing the group and serializing
     * their own membership id beside a feed and a record surface that all 403.
     */
    private function hasStanding(Group $group): bool
    {
        return $this->standingRows($group)->isNotEmpty();
    }

    /**
     * The rows this parent's credential speaks through in `$group` — the ONE
     * definition, borrowed rather than re-stated. @see GroupAudience::membershipsFor
     *
     * @return \Illuminate\Support\Collection<int,GroupMembership>
     */
    private function standingRows(Group $group): \Illuminate\Support\Collection
    {
        return $this->audience->membershipsFor($this->contact(), $group);
    }

    /**
     * One group as its parent sees it.
     *
     * @return array<string,mixed>
     */
    private function serialize(Group $group): array
    {
        $contact = $this->contact();

        // The same narrowed set `hasStanding()` answers from, so what a parent
        // is shown about a group and whether they may open it at all are one
        // decision. `own_membership` below therefore serializes as null for a
        // family credential: the holder's own participant row is not something
        // this credential speaks through, and serving its id beside endpoints
        // that all refuse it is how a later slice talks itself into opening one.
        $mine = $this->standingRows($group);

        // The wards this parent names IN THIS GROUP. Per-group on purpose: a
        // guardian edge says guardian-of-WHOM-in-WHICH-group, so consent and
        // access cannot leak sideways to the same parent's other children or
        // their other groups (.claude/rules/groups.md).
        $wardContactIds = $mine
            ->filter(fn (GroupMembership $m) => $m->isGuardian())
            ->pluck('guardian_of_contact_id')
            ->filter()
            ->unique()
            ->values();

        // Resolved to the WARD'S OWN participant membership, because that is the
        // id every per-child endpoint is addressed by — an award, a ḥifẓ entry
        // and a participant thread all name a `group_memberships` row, never a
        // contact. A ward whose participant row was removed from the roster
        // simply does not appear, which is the correct least-disclosure outcome:
        // their records went with the row.
        $children = $wardContactIds->isEmpty()
            ? collect()
            : $group->memberships()
                ->participants()
                ->whereIn('contact_id', $wardContactIds)
                ->with('contact:id,first_name,last_name')
                ->get();

        $ownParticipant = $mine->first(
            fn (GroupMembership $m) => in_array($m->role, GroupMembership::PARTICIPANT_ROLES, true)
        );

        return [
            'id' => (int) $group->id,
            'name' => $group->name,
            'kind' => $group->kind(),
            'description' => $group->description,
            'is_active' => (bool) $group->is_active,
            'starts_on' => optional($group->starts_on)->toDateString(),
            'ends_on' => optional($group->ends_on)->toDateString(),

            // This parent's own footing, so a client can render the right screen
            // without guessing from the presence of children.
            'is_guardian' => $mine->contains(fn (GroupMembership $m) => $m->isGuardian()),
            'own_membership' => $ownParticipant ? $this->student($ownParticipant) : null,

            'children' => $children
                ->map(fn (GroupMembership $m) => $this->student($m))
                ->values()
                ->all(),

            // Stated rather than inferred from an empty feed. A parent who has
            // not consented is not shown an empty class story and left to
            // conclude the teacher posts nothing — the same honesty
            // `media_withheld` provides on the feed itself.
            'may_receive_feed' => $this->audience->mayReceive(
                $contact, $group, GroupAudience::DISCLOSURE_FEED
            ),
            'may_receive_media' => $this->audience->mayReceive(
                $contact, $group, GroupAudience::DISCLOSURE_MEDIA
            ),
        ];
    }
}
