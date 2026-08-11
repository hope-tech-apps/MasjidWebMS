<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreBehaviorAwardRequest;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Errors;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behaviour awards — the recognition layer of the Classroom module
 * (PLAN T-013).
 *
 * ## THE PRIVACY RULE IS THE POINT OF THIS SLICE
 *
 * The product this replaces shows behaviour points publicly, and children being
 * shamed by a class-wide tally is its loudest and most-documented harm. Manara
 * inverts it, structurally rather than by configuration:
 *
 *   - an award is disclosed to the group's LEADERS, to the STUDENT, and to
 *     THAT student's own GUARDIANS. Another guardian in the same group is
 *     refused — at the endpoint (403) AND inside the listing query, so a
 *     forbidden award is never fetched, never counted in a paginator total, and
 *     never folded into a summary;
 *   - there is NO class-wide ranking endpoint, no rank field, and no
 *     comparison payload. Every aggregate this controller serves is per
 *     student, computed over rows the caller was already entitled to;
 *   - the decision itself lives in App\Support\GroupAudience
 *     (mayReceiveAwardsAbout / readableAwardsQuery), never inline here, so the
 *     single-award check and the listing constraint cannot drift apart — the
 *     same arrangement the feed and the messaging threads use.
 *
 * NOTHING HERE IS PAYWALLED. Awarding, listing, the per-student summary and
 * retention are all base product; there is no plan flag to add one to. See
 * .claude/rules/groups.md.
 *
 * ## The two gates, mirroring the feed
 *
 *   - WRITING (store / revoke) is `permission:manage contacts`, exactly like the
 *     roster endpoints beside it: the accountable administrator acts, and the
 *     award records WHICH account did (`awarded_by_user_id`,
 *     `revoked_by_user_id`). An admin who is not on the roster can therefore
 *     award and NOT read the record back — the feed's deliberate read/write
 *     asymmetry, unchanged here because giving recognition is administration.
 *   - READING additionally requires standing in the group, decided by
 *     GroupAudience. `view contacts` is held by every masjid admin, so gating a
 *     child's behaviour record on it alone would publish it to the whole
 *     tenant, which .claude/rules/groups.md obligation 4 forbids.
 *
 * Tenant isolation is not hand-rolled: the `tenant` middleware binds
 * TenantContext and BelongsToMasjid auto-scopes Group, GroupMembership,
 * BehaviorSkill and BehaviorAward — a foreign organization's id anywhere in the
 * chain is a MISS (404), never a filtered row. findOrFail stays OUTSIDE every
 * try/catch so the JSON renderer turns it into a clean 404. See
 * .claude/rules/tenant-scoping.md.
 */
class BehaviorAwardsController extends Controller
{
    public function __construct(private GroupAudience $audience)
    {
    }

    /**
     * GET .../groups/{group_id}/awards[?from=&to=&membership_id=&polarity=]
     *
     * The group's awards, newest first, PRE-FILTERED to what this caller may
     * read. A leader sees the group; anybody else sees only their own record
     * and their own wards' — which is why this same endpoint is safe to hand to
     * a parent, and why there is no separate "my child" route to keep in sync.
     *
     * `membership_id` narrows to one student. It is a filter over an already
     * constrained query, NOT an access decision: passing another family's
     * membership id yields an empty page rather than a leak, and the dedicated
     * per-student route below is the one that answers 403 instead.
     */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $query = $this->readableAwards($request->user(), $group);

        $awards = $this->applyFilters($query, $request)
            ->with($this->readEagerLoads())
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 25))
            ->through(fn (BehaviorAward $award) => $this->serialize($award));

        return response()->json([
            'status' => 'success',
            'data' => $awards,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../groups/{group_id}/members/{membership_id}/awards[?from=&to=]
     *
     * ONE student's record. The endpoint answers 403 for a caller who is not a
     * leader, that student, or that student's guardian — and the listing is
     * additionally constrained by the same rule, so the two can never disagree.
     * Both gates are deliberate: the 403 is honest to a parent who mistyped an
     * id, and the query constraint is what makes the honesty safe.
     */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $this->authorizeSubject($request->user(), $group, $membership);

        $awards = $this->applyFilters($this->readableAwards($request->user(), $group), $request)
            ->where('group_membership_id', $membership->id)
            ->with($this->readEagerLoads())
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 25))
            ->through(fn (BehaviorAward $award) => $this->serialize($award));

        return response()->json([
            'status' => 'success',
            'data' => $awards,
            'meta' => $this->meta() + ['student' => $this->student($membership)],
        ], Response::HTTP_OK);
    }

    /**
     * GET .../groups/{group_id}/members/{membership_id}/awards/summary[?from=&to=]
     *
     * Totals for ONE student, by polarity and by skill — the report a teacher
     * reads before a parents' evening and a parent reads about their own child.
     *
     * Computed from the SNAPSHOTTED `points` / `skill_label` / `skill_polarity`
     * on each award, never by re-reading the skills table: a school that
     * re-weighted "Participation" last month must not find last term's summary
     * silently restated. Skills that were renamed appear under both labels,
     * which is the truthful answer — those awards were given under different
     * words.
     *
     * Deliberately per student and never per class: there is no sibling
     * endpoint that ranks a group, because a ranking is the harm this module
     * exists to avoid.
     */
    public function summary(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $this->authorizeSubject($request->user(), $group, $membership);

        $base = $this->applyFilters($this->readableAwards($request->user(), $group), $request)
            ->where('group_membership_id', $membership->id);

        // Grouped in the database rather than fetched and summed in PHP, so a
        // student with a year of records still costs one row per skill. Both
        // grouped columns are selected, so MySQL's ONLY_FULL_GROUP_BY is happy
        // and SQLite behaves identically.
        $rows = $base->clone()
            ->selectRaw('skill_label, skill_polarity, COUNT(*) as awards_count, SUM(points) as points_total')
            ->groupBy('skill_label', 'skill_polarity')
            ->orderBy('skill_polarity')
            ->orderBy('skill_label')
            ->get();

        $bySkill = [];
        $byPolarity = array_fill_keys(BehaviorSkill::POLARITIES, ['awards' => 0, 'points' => 0]);

        foreach ($rows as $row) {
            // Degrade an unreadable stored polarity the same way the model does,
            // so a corrupt value lands in a real bucket instead of inventing a
            // third one in the payload.
            $polarity = in_array($row->skill_polarity, BehaviorSkill::POLARITIES, true)
                ? $row->skill_polarity
                : BehaviorSkill::POLARITY_POSITIVE;

            $awards = (int) $row->awards_count;
            $points = (int) $row->points_total;

            $bySkill[] = [
                'skill_label' => $row->skill_label,
                'polarity' => $polarity,
                'awards' => $awards,
                'points' => $points,
            ];

            $byPolarity[$polarity]['awards'] += $awards;
            $byPolarity[$polarity]['points'] += $points;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->student($membership),
                'range' => [
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                ],
                'totals' => [
                    'awards' => array_sum(array_column($byPolarity, 'awards')),
                    // The NET of the snapshotted values. Not a score and not
                    // comparable to anybody else's — nothing in this module ever
                    // puts two students' numbers side by side.
                    'points' => array_sum(array_column($byPolarity, 'points')),
                ],
                'by_polarity' => $byPolarity,
                'by_skill' => $bySkill,
            ],
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/awards
     *
     * Give one skill to ONE student. The values are SNAPSHOTTED onto the award
     * here — label, polarity and points as they stand right now — so a later
     * edit to the vocabulary describes the next term rather than rewriting this
     * child's October. Same reasoning as the fee-plan snapshots on
     * registrations.
     *
     * The subject must be a PARTICIPANT of THIS group: a guardian edge names a
     * relationship, not a person to recognise, and a membership from another
     * group (or tenant) is invisible to the scoped lookup. Both are 422 rather
     * than 404 — the id arrived in the payload, not the path, so no resource is
     * being addressed, and a 404 would confirm which ids exist elsewhere.
     *
     * masjid_id is intentionally absent from the payload: the BelongsToMasjid
     * creating hook stamps it from the bound tenant.
     */
    public function store(StoreBehaviorAwardRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $membership = $group->memberships()
            ->participants()
            ->find($request->integer('membership_id'));

        if ($membership === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'That id names no participant of this group, so nothing can be awarded to them.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Tenant-scoped, so another organization's skill is simply not found.
        $skill = BehaviorSkill::find($request->integer('behavior_skill_id'));

        if ($skill === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'That id names no behaviour skill in this organization.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A retired skill has been taken out of the vocabulary on purpose.
        // Refusing here rather than silently accepting keeps `is_active` an
        // actual decision instead of a UI hint the API ignores.
        if (! $skill->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'That behaviour skill is inactive; reactivate it before awarding it.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $award = BehaviorAward::create([
                'group_id' => $group->id,
                'group_membership_id' => $membership->id,
                'behavior_skill_id' => $skill->id,
                // The AUTHENTICATED account, never a client-supplied awarder.
                'awarded_by_user_id' => $request->user()?->id,
                // ---- the snapshot ----
                'skill_label' => $skill->label,
                'skill_polarity' => $skill->polarity(),
                'points' => $request->has('points')
                    ? $request->integer('points')
                    : (int) $skill->default_points,
                'note' => $request->input('note'),
                'awarded_at' => $request->filled('awarded_at') ? $request->date('awarded_at') : now(),
                'retained_until' => $request->input('retained_until'),
            ]);

            return response()->json([
                'status' => 'success',
                // The writer sees what they just wrote: they supplied it a
                // moment ago, so echoing it discloses nothing new.
                'data' => $this->serialize($award->load($this->readEagerLoads())),
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE .../groups/{group_id}/awards/{award_id} — revoke.
     *
     * A teacher tapped the wrong child, or marked something they immediately
     * regretted. The award leaves every listing and every total at once through
     * the ordinary soft-delete scope, and `revoked_by_user_id` records who made
     * the correction — a change to a child's record is itself accountable. The
     * row goes for good when the retention window closes.
     *
     * Administration, so `manage contacts` alone, with no read gate — the same
     * call as deleting a feed post. Idempotent: revoking an already-revoked
     * award reports it revoked rather than erroring, because the caller's
     * intent is already true.
     */
    public function destroy(Request $request, $masjid_id, $group_id, $award_id)
    {
        $group = Group::findOrFail($group_id);
        $award = $group->behaviorAwards()->withTrashed()->findOrFail($award_id);

        if (! $award->isRevoked()) {
            // Saved BEFORE the soft delete, and separately: runSoftDelete()
            // writes only deleted_at/updated_at, so a dirty attribute riding
            // along on delete() would be silently dropped.
            $award->forceFill(['revoked_by_user_id' => $request->user()?->id])->save();
            $award->delete();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $award->id,
                // deleted_at IS the revocation clock; the model instance carries
                // it whether it was already revoked or was revoked just now.
                'revoked_at' => optional($award->deleted_at)->toIso8601String(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The caller's readable slice of this group's awards, or a 403.
     *
     * 403, not 404, for the same reason as the feed and the threads: the group
     * itself is addressable by this admin (they can see it in the groups list
     * and manage its roster), so pretending it holds no records would be a lie.
     * 404 stays reserved for an id belonging to another organization.
     */
    private function readableAwards(?User $user, Group $group): Builder
    {
        $query = $this->audience->readableAwardsQuery($user, $group);

        if ($query === null) {
            abort(403, 'You are not entitled to this group\'s behaviour records.');
        }

        return $query;
    }

    /**
     * Refuse a student's record to a caller who is not a leader, that student,
     * or that student's guardian.
     *
     * THIS IS THE ENDPOINT HALF OF THE PRIVACY GUARANTEE; readableAwardsQuery()
     * is the listing half. Another guardian in the same group is exactly who
     * both halves exist to refuse.
     */
    private function authorizeSubject(?User $user, Group $group, GroupMembership $subject): void
    {
        if ($this->audience->mayReceiveAwardsAbout($user, $group, $subject)) {
            return;
        }

        abort(403, 'You are not entitled to this student\'s behaviour record.');
    }

    /**
     * Date-range and polarity filters, applied over an ALREADY constrained
     * query — never as a substitute for one. `from`/`to` read `awarded_at`,
     * which is when the recognition happened, not when it was typed in.
     */
    private function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->awardedBetween($request->query('from'), $request->query('to'))
            ->when(
                in_array($request->query('polarity'), BehaviorSkill::POLARITIES, true),
                fn (Builder $q) => $q->where('skill_polarity', $request->query('polarity'))
            )
            ->when(
                $request->filled('membership_id'),
                fn (Builder $q) => $q->where('group_membership_id', $request->integer('membership_id'))
            );
    }

    /** @return array<int,string> */
    private function readEagerLoads(): array
    {
        return [
            'membership.contact:id,first_name,last_name',
            'awardedBy:id,name',
        ];
    }

    /**
     * One award as an entitled reader sees it.
     *
     * The student is included: anyone entitled to see an award is entitled to
     * know whose it is — that is what the record IS, and the audience rule
     * already guaranteed they may know. The stored SNAPSHOT is served as the
     * award's own values, with `behavior_skill_id` alongside as provenance only;
     * a client must never re-read the skill for a label or a weight.
     *
     * @return array<string,mixed>
     */
    private function serialize(BehaviorAward $award): array
    {
        return [
            'id' => $award->id,
            'group_id' => $award->group_id,
            'student' => $award->membership ? $this->student($award->membership) : null,
            'skill_label' => $award->skill_label,
            'polarity' => $award->polarity(),
            'points' => (int) $award->points,
            'note' => $award->note,
            'awarded_at' => optional($award->awarded_at)->toIso8601String(),
            'awarded_by' => $award->awardedBy
                ? ['id' => $award->awardedBy->id, 'name' => $award->awardedBy->name]
                : null,
            // Provenance, not a value source: null once the vocabulary entry is
            // deleted, and the award reads exactly the same afterwards.
            'behavior_skill_id' => $award->behavior_skill_id,
            'revoked_at' => optional($award->deleted_at)->toIso8601String(),
            'retained_until' => optional($award->retained_until)->toDateString(),
            'created_at' => optional($award->created_at)->toIso8601String(),
        ];
    }

    /**
     * The student a record concerns, as the membership edge that names them.
     *
     * @return array<string,mixed>
     */
    private function student(GroupMembership $membership): array
    {
        $contact = $membership->contact;

        return [
            'membership_id' => $membership->id,
            'contact' => $contact ? [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
            ] : null,
        ];
    }

    /**
     * Vertical-aware labelling, same source as the other group controllers:
     * what a group is CALLED comes from the tenant's terminology pack, never a
     * hardcoded string. See .claude/rules/verticals.md.
     *
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'group_label' => $masjid?->term('groups') ?? 'Groups',
            'polarities' => BehaviorSkill::POLARITIES,
            'max_points' => (int) config('groups.behavior.max_points', 0),
            'max_note_length' => (int) config('groups.behavior.max_note_length', 0),
        ];
    }
}
