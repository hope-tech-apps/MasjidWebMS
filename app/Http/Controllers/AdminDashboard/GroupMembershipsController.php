<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\ConfirmGroupMembershipsRequest;
use App\Http\Requests\Admin\Groups\StoreGroupMembershipRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Roster management for a group: who is in it, out of it, and on whose word.
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
 *
 * ## THIS CONTROLLER IS WHERE A ROSTER ROW BECOMES A GRANT
 *
 * A `group_memberships` row now records on whose authority it exists
 * (`GroupMembership::PROVENANCES`). There are exactly two writers of that column
 * in this application, and they are the two intents:
 *
 *   - `RegistrationService` writes `self_asserted` — a public form's claim, made
 *     with no session and no token. It lists a person and grants nothing.
 *   - THIS controller writes `confirmed`, with the acting staff member recorded,
 *     because everything that reaches it has come through `auth:sanctum` +
 *     `admin` + `tenant` + `permission:manage contacts`. That is the
 *     "authenticated staff act" the whole model is defined against.
 *
 * `confirm()` is the second half of that and the reason the model does not
 * over-refuse: a school that takes 200 camp signups gets 200 pending claims and
 * ONE button, on the roster screen, where the names and the signup that asserted
 * them are already in front of the person deciding.
 */
class GroupMembershipsController extends Controller
{
    /**
     * The roster of one group, newest additions last.
     *
     * `meta.pending_claims` is served beside the rows because a pending claim
     * that nobody is told about is a roster line the office reads as settled. It
     * is what the screen's banner counts, and it comes from the same query the
     * confirm verb below acts on rather than from a count computed in the SPA.
     */
    public function index($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $memberships = $group->memberships()
            ->with(['contact', 'guardianOf', 'confirmedBy:id,name', 'sourceRegistration:id,offering_id,contact_id'])
            ->orderBy('role')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $memberships,
            'meta' => [
                'pending_claims' => $memberships
                    ->filter(fn (GroupMembership $m): bool => $m->isPendingClaim())
                    ->count(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Add a contact to the group — or STAND BEHIND a claim already on it.
     *
     * masjid_id is intentionally omitted from the create payload — the
     * BelongsToMasjid creating hook stamps it from the bound tenant.
     *
     * ## Why a duplicate is no longer always a 422
     *
     * The duplicate refusal is the guarantee .claude/rules/groups.md says it is
     * ("that check is the guarantee, not the index") and it stays. But once a
     * public registration can put an UNCONFIRMED row on a roster, "that person
     * already holds this membership" became a refusal aimed at the office's own
     * remedy: an administrator typing in the guardian entry they meant to
     * establish would be told the row exists, while the row that exists grants
     * nothing and the screen they came from says the parent cannot sign in.
     * There is no other door — the confirm verb below is the only other one, and
     * an operator who does not know a claim is sitting there will not find it.
     *
     * So a duplicate that is a PENDING CLAIM is confirmed by this act and
     * answered 200; a duplicate that is already confirmed is still 422, because
     * then nothing is being asked for that is not already true. Confirming
     * through this path is the same decision, by the same authenticated person,
     * with the same actor recorded, as pressing Confirm on the roster.
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

            // A PENDING participant row still counts here. The child IS on the
            // roster — that is what the row says — and refusing to record their
            // guardian until the enrolment is confirmed would make the office
            // confirm in an order nothing on the screen asks for.
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
            ->first();

        if ($duplicate !== null) {
            if ($duplicate->isConfirmed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That person already holds this membership in this group.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $duplicate->confirmedByStaff($this->actor($request))->save();

            return response()->json([
                'status' => 'success',
                'message' => 'That entry was already on this roster as an unconfirmed claim from a '
                    . 'registration form. It is now confirmed by you.',
                'data' => $duplicate->load(['contact', 'guardianOf', 'confirmedBy:id,name']),
            ], Response::HTTP_OK);
        }

        $membership = new GroupMembership([
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $wardId,
            'joined_at' => $request->input('joined_at'),
        ]);

        $membership->confirmedByStaff($this->actor($request))->save();

        return response()->json([
            'status' => 'success',
            'data' => $membership->load(['contact', 'guardianOf', 'confirmedBy:id,name']),
        ], Response::HTTP_CREATED);
    }

    /**
     * Stand behind the pending claims on this roster — THE BULK AFFORDANCE.
     *
     * An empty body means every pending claim in this group, which is the shape
     * a camp intake actually has: one program, one class list, one person who
     * runs it. `membership_ids` narrows it to named rows for the roster where
     * one line looks wrong.
     *
     * ## What this refuses, and what it deliberately does not
     *
     * It confirms ONLY rows that are pending, only in THIS group, and only for
     * this tenant — a foreign or already-confirmed id is skipped rather than
     * refused, so a stale screen re-submitting a list somebody else just
     * confirmed is a no-op with an honest count instead of a 422 an operator
     * cannot act on.
     *
     * There is no bulk REJECT here on purpose. Removing a roster row is
     * `destroy()`, which already exists, already cascades the guardian edges
     * pointing at the person, and is one verb rather than two names for it —
     * and a bulk delete over children's roster rows, reached from the same
     * screen as a bulk confirm, is one mis-click away from destroying a term's
     * behaviour and ḥifẓ history.
     */
    public function confirm(ConfirmGroupMembershipsRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);
        $actor = $this->actor($request);

        // The named set, deduplicated — the operator's agreement is to a list of
        // rows they read, so this is the only set considered. Anything named
        // that is not a pending claim of this group (already confirmed by a
        // colleague, since removed, another group's, another tenant's) is
        // SKIPPED and counted, never refused: a stale screen re-submitting a
        // list must be an honest no-op rather than a 422 an operator cannot act
        // on. And nothing NOT named is touched, whatever arrives mid-dialog.
        $ids = array_values(array_unique(array_map('intval', (array) $request->input('membership_ids', []))));

        $pending = $group->memberships()
            ->pendingClaims()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        foreach ($pending as $membership) {
            $membership->confirmedByStaff($actor)->save();
        }

        $confirmed = $pending->count();
        $skipped = count($ids) - $confirmed;

        return response()->json([
            'status' => 'success',
            'message' => $confirmed === 0
                ? 'None of those roster entries was still waiting to be confirmed.'
                : sprintf(
                    '%d roster %s confirmed%s. The guardians among them can now be given parent portal sign-in.',
                    $confirmed,
                    $confirmed === 1 ? 'entry' : 'entries',
                    $skipped > 0
                        ? sprintf(' and %d skipped, having already been confirmed or removed', $skipped)
                        : '',
                ),
            'data' => [
                'confirmed' => $confirmed,
                // Said out loud, because "8 confirmed" on a list of 10 is the
                // shape the previous defect hid inside.
                'skipped' => $skipped,
                'pending_claims' => $group->memberships()->pendingClaims()->count(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Remove a membership.
     *
     * Resolved through the group so a membership id from another group — or
     * another organization — is a 404. Deleting a participant also removes the
     * guardian edges that pointed at them in this group; that happens in
     * GroupMembership's `deleting` hook, so it holds for every caller, not just
     * this one.
     *
     * This is also how a claim is REJECTED. A pending row and a confirmed row
     * are removed by the same verb, because "take this person off the roster" is
     * one act however the row got there, and a second verb for it would be a
     * second place the guardian-edge cascade has to be remembered.
     */
    public function destroy($masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);

        $membership = $group->memberships()->findOrFail($membership_id);

        // WHAT THE CASCADE IS ABOUT TO TAKE, counted BEFORE it runs.
        //
        // Removing a participant also removes every guardian edge pointing at
        // them in this group (the `deleting` hook). Some of those edges were
        // CONFIRMED by a staff member, and confirming is the act that opens a
        // child's records — so this verb can silently undo a decision somebody
        // deliberately made, and used to report only the row the operator named.
        // Measured: an office confirmed a guardian edge, enabled that parent's
        // sign-in, then tidied away the child's still-pending enrolment line —
        // and the confirmed guardianship went with it, with nothing said, the
        // parent now ineligible, and a live credential that opens nothing.
        //
        // The credential is deliberately NOT revoked here. A roster edit must
        // never burn one — that was an earlier round's regression, reached from
        // an anonymous POST — so the office is TOLD instead, and can decide.
        $cascadingEdges = in_array($membership->role, GroupMembership::PARTICIPANT_ROLES, true)
            ? GroupMembership::query()
                ->where('group_id', $membership->group_id)
                ->where('guardian_of_contact_id', $membership->contact_id)
                ->get()
            : collect();

        $confirmedEdges = $cascadingEdges->filter->isConfirmed();

        // Guardians who, once this cascade runs, hold a live parent-portal
        // sign-in and no ward left anywhere in the organisation.
        $strandedLogins = $confirmedEdges
            ->pluck('contact_id')
            ->unique()
            ->filter(function ($contactId) use ($membership, $cascadingEdges): bool {
                $contact = Contact::query()->whereKey($contactId)->first();

                if (! $contact || ! $contact->familyLoginIsActive()) {
                    return false;
                }

                return ! GroupMembership::query()
                    ->where('masjid_id', $membership->masjid_id)
                    ->where('contact_id', $contactId)
                    ->where('role', GroupMembership::ROLE_GUARDIAN)
                    ->whereNotNull('guardian_of_contact_id')
                    ->whereNotIn('id', $cascadingEdges->pluck('id')->all())
                    ->exists();
            })
            ->count();

        $membership->delete();

        $removed = $cascadingEdges->count();
        $confirmedRemoved = $confirmedEdges->count();

        return response()->json([
            'status' => 'success',
            'message' => $removed === 0
                ? 'Removed from the roster.'
                : sprintf(
                    'Removed from the roster, and with it %d guardian %s%s.%s',
                    $removed,
                    $removed === 1 ? 'entry' : 'entries',
                    $confirmedRemoved > 0
                        ? sprintf(' (%d of them confirmed by this organisation)', $confirmedRemoved)
                        : '',
                    $strandedLogins > 0
                        ? sprintf(
                            ' %d parent portal sign-in%s now open nothing — revoke %s if that was not intended.',
                            $strandedLogins,
                            $strandedLogins === 1 ? '' : 's',
                            $strandedLogins === 1 ? 'it' : 'them',
                        )
                        : '',
                ),
            'data' => [
                'membership' => $membership,
                'cascade' => [
                    'guardian_edges_removed' => $removed,
                    'confirmed_guardian_edges_removed' => $confirmedRemoved,
                    'family_logins_left_without_a_ward' => $strandedLogins,
                ],
            ],
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * The staff member acting, or null.
     *
     * Narrowed with `instanceof` rather than trusted: this tree is `admin`-gated
     * so the principal is a User in practice, but `confirmed_by_user_id` is a
     * `users` foreign key, and a wrong principal type must produce "no actor
     * recorded" rather than an id from another table. The same call
     * ContactFamilyLoginController makes for the same reason.
     */
    private function actor(Request $request): ?User
    {
        $principal = $request->user();

        return $principal instanceof User ? $principal : null;
    }
}
