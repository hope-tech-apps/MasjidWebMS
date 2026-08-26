<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\ConfirmGroupMembershipsRequest;
use App\Http\Requests\Admin\Groups\StoreGroupMembershipRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\RosterClaimIdentity;
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
 *
 * ## AND THE SCREEN HAS TO ACTUALLY CARRY THEM
 *
 * That last sentence was, for two rounds, a claim about a screen nobody had
 * measured. It was false. `GroupRosterTab.vue` contained `source_registration`
 * zero times, `confirmed_by` zero times, and rendered no address on either
 * roster table; `index()` eager-loaded the source registration and nothing drew
 * it. So two guardian claims over one child — the mother's, and a stranger's who
 * typed her name with his own email — rendered as the same two strings, and the
 * bulk button confirmed both.
 *
 * `index()` now serves the bytes that separate them, per row, under `claim`
 * (App\Support\RosterClaimIdentity), and `confirm()` no longer trusts an id on
 * its own: the caller echoes back what it drew, and the shape the operator
 * CANNOT read — two guardian rows over one ward under the same displayed name —
 * is refused a place in the sweep and has to be decided one row at a time.
 */
class GroupMembershipsController extends Controller
{
    /**
     * THE PEOPLE ON A ROSTER PAYLOAD, NARROWED TO THE COLUMNS A SCREEN NAMES.
     *
     * `index()` argued this and applied it; `store()` served
     * `->load(['contact', …])` twenty lines further down and shipped
     * `login_email`, `login_enabled_at`, `login_revoked_at`, `last_login_at`,
     * the CRM notes and all four SMS-consent fields on both of its responses.
     * Same audience and the same tenant, so it was never an escalation — but the
     * credential columns are the ones .claude/rules/credentials.md keeps out of
     * request bodies, and a roster verb is not where they should leak out
     * instead.
     *
     * A CONSTANT AND NOT A COPY, so the next endpoint cannot half-apply it
     * again. `email` and `phone` are LOAD-BEARING rather than decoration: they
     * are the only rendered bytes that separate two claims over one child made
     * under one name.
     */
    private const PERSON_COLUMNS = [
        'contact:id,first_name,last_name,email,phone,'.Contact::AVATAR_COLUMNS,
        'guardianOf:id,first_name,last_name,email',
        'confirmedBy:id,name',
    ];


    /**
     * The roster of one group, newest additions last.
     *
     * `meta.pending_claims` is served beside the rows because a pending claim
     * that nobody is told about is a roster line the office reads as settled. It
     * is what the screen's banner counts, and it comes from the same query the
     * confirm verb below acts on rather than from a count computed in the SPA.
     *
     * ## `claim` — WHY EVERY ROW NOW CARRIES ONE
     *
     * A roster row used to serialise as its own columns plus three related
     * people. That is enough to LIST a roster and not enough to DECIDE one, and
     * the difference is what F9 was: the payload already carried the contact's
     * address and the asserting registration, the screen drew neither, and the
     * operator was asked to vouch for a relationship they could not distinguish
     * from another one on the same page.
     *
     * `claim` is the decision-bearing half, computed once here so the screen and
     * the confirm verb read one definition:
     *
     *   - `fingerprint` — what the caller echoes back to prove this row has not
     *     moved since it was drawn. Null on a confirmed row, which has nothing
     *     left to confirm.
     *   - `contested` + `rival_claim_ids` — this guardian claim shares its ward
     *     AND its displayed name with another row. The bulk sweep refuses these.
     *   - `origin` — which signup asserted the claim and who paid, INCLUDING an
     *     explicit state for each way that evidence can be missing. A merge
     *     nulls `registrations.contact_id`, and a screen that renders that as
     *     blank is F9 again with a different cause.
     *
     * The related people are also narrowed to the columns the screen names.
     * `with('contact')` served every column of every contact on the roster —
     * `login_email`, `login_enabled_at`, `login_revoked_at`, `last_login_at`,
     * the SMS-consent evidence and the CRM notes — to a listing that renders a
     * name and an address. Nothing read them, a 200-child roster carried them
     * 400 times over, and the credential columns in particular are the ones
     * .claude/rules/credentials.md keeps out of request bodies; there is no
     * reason for a roster listing to be where they leak out instead.
     */
    public function index($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $memberships = $group->memberships()
            ->with([
                ...self::PERSON_COLUMNS,
                // The claim's EVIDENCE, which only the listing renders.
                'sourceRegistration:id,offering_id,contact_id',
                'sourceRegistration.contact:id,first_name,last_name,email,'.Contact::AVATAR_COLUMNS,
            ])
            ->orderBy('role')
            ->orderBy('id')
            ->get();

        $contested = RosterClaimIdentity::contestedClaimIds($memberships);

        return response()->json([
            'status' => 'success',
            'data' => $memberships->map(function (GroupMembership $membership) use ($memberships, $contested): array {
                $isContested = in_array((int) $membership->getKey(), $contested, true);

                return array_merge($membership->toArray(), [
                    'claim' => [
                        'fingerprint' => $membership->isConfirmed()
                            ? null
                            : RosterClaimIdentity::fingerprint($membership),
                        'contested' => $isContested,
                        'rival_claim_ids' => $isContested
                            ? RosterClaimIdentity::rivalClaimIds($membership, $memberships)
                            : [],
                        'origin' => RosterClaimIdentity::origin($membership),
                    ],
                ]);
            })->values(),
            'meta' => [
                'pending_claims' => $memberships
                    ->filter(fn (GroupMembership $m): bool => $m->isPendingClaim())
                    ->count(),
                // Counted for the banner, so "6 waiting" never reads as "6 the
                // button will take care of".
                'contested_claims' => count($contested),
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

            // SAID, BECAUSE THIS IS THE OTHER DOOR ONTO THE SAME GRANT.
            //
            // `confirm()` refuses to sweep a guardian claim that shares its ward
            // and its displayed name with another row; typing the same entry in
            // here reaches the identical write. That is not a hole to plug — an
            // administrator who searched the directory, picked ONE contact out of
            // a list that shows each one's address, and named the ward has made
            // exactly the individual, addressed decision the contested path asks
            // for. What they have NOT necessarily been told is that a second
            // adult of the same name is also claiming this child, which is the
            // fact that makes the decision worth making twice.
            $rivals = $this->rivalsOver($group, $duplicate);

            $duplicate->confirmedByStaff($this->actor($request))->save();

            return response()->json([
                'status' => 'success',
                'message' => 'That entry was already on this roster as an unconfirmed claim from a '
                    . 'registration form. It is now confirmed by you.'
                    . ($rivals === 0 ? '' : sprintf(
                        ' Note: %d other roster %s claim%s to be a guardian of the same person under the same '
                            . 'name. Check the addresses on the roster and remove any that should not be there.',
                        $rivals,
                        $rivals === 1 ? 'entry' : 'entries',
                        $rivals === 1 ? 's' : '',
                    )),
                'data' => $duplicate->load(self::PERSON_COLUMNS),
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
            'data' => $membership->load(self::PERSON_COLUMNS),
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
     *
     * ## THE ID WAS NEVER THE THING THE OPERATOR AGREED TO
     *
     * Round five bound the agreement to a list of ids. That is airtight against
     * a row INSERTED after the draw — a registration arriving while the dialog
     * is open is left alone — and it does nothing about the two shapes below,
     * both of which reach the same button.
     *
     * 1. THE ROW MUTATED AND THE ID DID NOT. `RosterMergeService` re-points
     *    `contact_id` on a pending claim; the id is stable; the operator
     *    confirms a relationship they never read. So the caller must now echo
     *    back a per-row FINGERPRINT of what it drew
     *    (`RosterClaimIdentity::fingerprint`). A row that no longer matches its
     *    description is SKIPPED and reported — the vocabulary `skipped` already
     *    established — rather than confirmed under a stale reading. It also
     *    covers the case where the row itself is untouched and its EVIDENCE
     *    degraded: a merge nulls the source registration's payer.
     *
     * 2. THE OPERATOR COULD NOT TELL TWO ROWS APART IN THE FIRST PLACE. Two
     *    guardian claims over one child under one displayed name — the mother's
     *    and a stranger's — are not made distinguishable by naming their ids,
     *    because the ids are not what the operator read. Those are lifted out of
     *    the sweep entirely and must be named a second time, in
     *    `contested_membership_ids`, which the SPA sets only from a dialog that
     *    puts both claimants' addresses side by side. A caller that does not
     *    know the shape exists — including a future rewrite of the client, and
     *    including a naive loop that posts one id at a time — skips them, which
     *    is the direction this has to fail in.
     *
     * Neither costs the 200-signup intake a second click: the ids, the
     * fingerprints and the (usually empty) contested list all ride the one POST
     * the one button already sends.
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

        // BELT AND BRACES, and not redundant. `ConfirmGroupMembershipsRequest`
        // makes `membership_ids` required, so this is unreachable through the
        // route today — but the defect being fixed here WAS "an absent list
        // means everything", and if that rule is ever relaxed the query below
        // would silently go back to confirming the whole roster. A mutation test
        // caught exactly that: replacing the unconditional `whereIn` with a
        // conditional one changed nothing, because the request happened to
        // guarantee the condition. The refusal belongs in both places, because
        // only one of them is about disclosure.
        if ($ids === []) {
            return response()->json([
                'status' => 'error',
                'message' => 'Name the roster entries to confirm. Confirming grants access to a child\'s '
                    . 'records, so it applies only to the entries you were shown.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $described = $this->describedRows($request);

        // THE SAME BELT AND BRACES, one field along. The request makes a
        // fingerprint per named id required; if that rule is ever relaxed, an
        // undescribed row must still not be confirmed on the strength of its id.
        $undescribed = array_values(array_diff($ids, array_keys($described)));

        if ($undescribed !== []) {
            return response()->json([
                'status' => 'error',
                'message' => 'Say what you were shown for every entry. Confirming grants access to a child\'s '
                    . 'records, and a roster row can be re-pointed at a different child between the moment it '
                    . 'is drawn and the moment it is agreed to.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Acknowledged contests: rows the operator decided ONE AT A TIME, having
        // been shown the rival claim beside this one. Naming a row here that is
        // not contested is harmless and deliberately not an error — the client
        // learns a row is contested from a payload that may be a moment old, and
        // a rival removed in between must not turn an ordinary confirm into a
        // 422.
        $acknowledged = array_values(array_unique(array_map(
            'intval',
            (array) $request->input('contested_membership_ids', [])
        )));

        // THE WHOLE ROSTER, not just the named rows: contest is a property of a
        // row's NEIGHBOURS, so a rival that is confirmed, or that was never
        // named in this request, still has to be visible to the check. Loaded
        // with the same relations `index()` serves, because the fingerprint is
        // computed from them and a fingerprint computed over unloaded relations
        // is a check that passes by accident.
        $roster = $group->memberships()
            ->with([
                'contact:id,first_name,last_name,email,phone,'.Contact::AVATAR_COLUMNS,
                'sourceRegistration:id,offering_id,contact_id',
                'sourceRegistration.contact:id,first_name,last_name,email,'.Contact::AVATAR_COLUMNS,
            ])
            ->orderBy('id')
            ->get();

        $contested = RosterClaimIdentity::contestedClaimIds($roster);

        $confirmable = [];
        $changedSinceShown = [];
        $needsIndividualDecision = [];

        foreach ($roster as $membership) {
            $id = (int) $membership->getKey();

            if (! in_array($id, $ids, true) || $membership->isConfirmed()) {
                continue;
            }

            if (! hash_equals(RosterClaimIdentity::fingerprint($membership), $described[$id])) {
                $changedSinceShown[] = $id;

                continue;
            }

            if (in_array($id, $contested, true) && ! in_array($id, $acknowledged, true)) {
                $needsIndividualDecision[] = $id;

                continue;
            }

            $confirmable[] = $membership;
        }

        foreach ($confirmable as $membership) {
            $membership->confirmedByStaff($actor)->save();
        }

        $confirmed = count($confirmable);
        $skipped = count($ids) - $confirmed;

        return response()->json([
            'status' => 'success',
            'message' => $this->confirmMessage($confirmed, $skipped, $changedSinceShown, $needsIndividualDecision),
            'data' => [
                'confirmed' => $confirmed,
                // Said out loud, because "8 confirmed" on a list of 10 is the
                // shape the previous defect hid inside. The breakdown is added
                // BESIDE it rather than replacing it: the three reasons a row is
                // skipped call for three different acts from the office, and
                // "skipped" alone cannot tell an operator which.
                'skipped' => $skipped,
                'skipped_detail' => [
                    'already_settled' => $skipped - count($changedSinceShown) - count($needsIndividualDecision),
                    'changed_since_shown' => count($changedSinceShown),
                    'needs_an_individual_decision' => count($needsIndividualDecision),
                ],
                'changed_since_shown' => $changedSinceShown,
                'needs_an_individual_decision' => $needsIndividualDecision,
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
     * How many OTHER roster rows claim to be a guardian of the same person
     * under the same displayed name.
     *
     * Reuses the contest definition rather than restating it, so "which rows an
     * operator cannot tell apart" has one answer in this application.
     */
    private function rivalsOver(Group $group, GroupMembership $membership): int
    {
        if (! $membership->isGuardian() || $membership->guardian_of_contact_id === null) {
            return 0;
        }

        $roster = $group->memberships()
            ->where('guardian_of_contact_id', $membership->guardian_of_contact_id)
            ->with('contact:id,first_name,last_name,email,phone,'.Contact::AVATAR_COLUMNS)
            ->get();

        return count(RosterClaimIdentity::rivalClaimIds(
            $roster->firstWhere('id', $membership->getKey()) ?? $membership,
            $roster,
        ));
    }

    /**
     * What the caller says it was shown, keyed by membership id.
     *
     * Non-string values are dropped rather than cast. A fingerprint is compared
     * with `hash_equals`, which requires two strings, and an array or a null
     * arriving under an id must read as "this row was not described" — the
     * branch that refuses — rather than as an empty description that could
     * coincide with a computed one.
     *
     * @return array<int, string>
     */
    private function describedRows(Request $request): array
    {
        $described = [];

        foreach ((array) $request->input('fingerprints', []) as $id => $fingerprint) {
            if (is_string($fingerprint) && $fingerprint !== '') {
                $described[(int) $id] = $fingerprint;
            }
        }

        return $described;
    }

    /**
     * What the office is told, in the order it has to act on it.
     *
     * The two new skip reasons are NOT folded into "already confirmed or
     * removed": one of them means a row changed under the operator and is still
     * waiting, the other means a row needs a decision this button is not allowed
     * to make. Both are things to go and do; the settled ones are not.
     *
     * @param  array<int, int>  $changedSinceShown
     * @param  array<int, int>  $needsIndividualDecision
     */
    private function confirmMessage(
        int $confirmed,
        int $skipped,
        array $changedSinceShown,
        array $needsIndividualDecision,
    ): string {
        $settled = $skipped - count($changedSinceShown) - count($needsIndividualDecision);

        $notes = [];

        if ($settled > 0) {
            $notes[] = sprintf(
                '%d had already been confirmed or removed',
                $settled,
            );
        }

        if ($changedSinceShown !== []) {
            $notes[] = sprintf(
                '%d changed after the roster was drawn and %s left untouched — open the roster again and read %s',
                count($changedSinceShown),
                count($changedSinceShown) === 1 ? 'was' : 'were',
                count($changedSinceShown) === 1 ? 'it' : 'them',
            );
        }

        if ($needsIndividualDecision !== []) {
            $notes[] = sprintf(
                '%d %s more than one guardian claim over the same child under the same name, which has to be '
                    . 'decided one entry at a time',
                count($needsIndividualDecision),
                count($needsIndividualDecision) === 1 ? 'is' : 'are',
            );
        }

        $tail = $notes === [] ? '' : ' Skipped: ' . implode('; ', $notes) . '.';

        if ($confirmed === 0) {
            return 'Nothing was confirmed.' . ($tail === ''
                ? ' None of those roster entries was still waiting to be confirmed.'
                : $tail);
        }

        return sprintf(
            '%d roster %s confirmed. The guardians among them can now be given parent portal sign-in.%s',
            $confirmed,
            $confirmed === 1 ? 'entry' : 'entries',
            $tail,
        );
    }

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
