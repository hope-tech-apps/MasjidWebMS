<?php

namespace App\Http\Controllers\Family;

use App\Models\Contact;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Group;
use App\Support\PointsWeek;
use App\Support\SchoolPointsWeek;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent's view of their own child's behaviour record (T-013 surface, T-015e
 * audience).
 *
 * ---------------------------------------------------------------------------
 * TWO GATES, BOTH REQUIRED — this is not belt-and-braces, it is the design
 * ---------------------------------------------------------------------------
 *
 * 1. The ENDPOINT asks `mayReceiveAwardsAbout()` about the named membership, so
 *    a parent who addresses another family's child gets an honest 403 rather
 *    than a confusing empty page.
 * 2. The QUERY is `readableAwardsQuery()`, which constrains to this caller's own
 *    wards before a single row is fetched — so a forbidden award cannot surface
 *    in a page, in a paginator total, or inside the summary's `SUM(points)`.
 *
 * .claude/rules/groups.md is explicit that both halves are mandatory: "the 403
 * is honest to a parent who mistyped an id, and the constraint is what makes the
 * honesty safe". Dropping either one is a privacy regression that no status-code
 * test would catch.
 *
 * ---------------------------------------------------------------------------
 * There is no leaderboard, and there is no group-wide endpoint here
 * ---------------------------------------------------------------------------
 *
 * Every route below is addressed by ONE membership id. A parent cannot ask for
 * "the group's awards", not because the query would leak (it would not — the
 * constraint holds) but because the shape of the API should not suggest that
 * comparing children is a thing this product does. Points are private, there is
 * no rank column, and nothing here puts two students' numbers side by side.
 *
 * CONSENT IS NOT CONSULTED, deliberately: the feed/media scopes gate BROADCASTS
 * of a child's data, and a parent reading their own child's record is not a
 * broadcast. Requiring feed consent here would lock a parent out of the record
 * most obviously theirs.
 */
class BehaviorAwardsController extends FamilyController
{
    /**
     * GET .../groups/{group_id}/members/{membership_id}/awards
     */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);

        $week = $this->requestedWeek($request);

        $awards = $this->readable($group)
            ->where('group_membership_id', $membership->id)
            ->when(
                $week !== null,
                fn (Builder $q) => $q->awardedWithin($week->startUtc(), $week->endUtc()),
                fn (Builder $q) => $q->awardedBetween($request->query('from'), $request->query('to'))
            )
            ->with(['membership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS, 'awardedBy:id,name'])
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25))
            ->through(fn (BehaviorAward $award) => $this->serialize($award));

        return response()->json([
            'status' => 'success',
            'data' => $awards,
            'meta' => $this->meta(['student' => $this->student($membership)] + $this->weekMeta($week)),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../members/{membership_id}/awards/summary
     *
     * Aggregated in the database over the ALREADY-CONSTRAINED query, so a
     * student with a year of records costs one row per skill and the totals are
     * arithmetically incapable of including anybody else's child.
     */
    public function summary(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);

        // `?week=` (T-003.2) names one points week by INSTANT and supersedes
        // from/to. It narrows the SAME audience-constrained query, so a week is
        // arithmetically incapable of including another family's child.
        $week = $this->requestedWeek($request);

        $base = $this->readable($group)
            ->where('group_membership_id', $membership->id)
            ->when(
                $week !== null,
                fn (Builder $q) => $q->awardedWithin($week->startUtc(), $week->endUtc()),
                fn (Builder $q) => $q->awardedBetween($request->query('from'), $request->query('to'))
            );

        // Both grouped columns are selected, so MySQL's ONLY_FULL_GROUP_BY is
        // satisfied and SQLite behaves identically.
        $rows = $base->clone()
            ->selectRaw('skill_label, skill_polarity, COUNT(*) as awards_count, SUM('.BehaviorAward::signedPointsSql().') as points_total')
            ->groupBy('skill_label', 'skill_polarity');

        // Positives first (T-003.1): the polarity column sorts alphabetically, so a
        // plain orderBy put negatives on top. Ordered by the SNAPSHOT columns.
        $rows = BehaviorSkill::orderInPickerOrder($rows, 'skill_polarity', 'skill_label')->get();

        $bySkill = [];
        $byPolarity = array_fill_keys(BehaviorSkill::POLARITIES, ['awards' => 0, 'points' => 0]);

        foreach ($rows as $row) {
            // Degrade an unreadable stored polarity the way the model does, so a
            // corrupt value lands in a real bucket instead of inventing a third
            // one in a parent's payload.
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
                'range' => ['from' => $request->query('from'), 'to' => $request->query('to')],
                // The week these figures are for, when one was asked for; null
                // for the whole record, as before (T-003.2).
                'week' => $week !== null ? SchoolPointsWeek::payload($week, app(TenantContext::class)->get()) : null,
                'totals' => [
                    'awards' => array_sum(array_column($byPolarity, 'awards')),
                    // The NET of the snapshotted values. Not a score, and not
                    // comparable to anybody else's — nothing in this module ever
                    // puts two children's numbers side by side.
                    'points' => array_sum(array_column($byPolarity, 'points')),
                ],
                'by_polarity' => $byPolarity,
                'by_skill' => $bySkill,
            ],
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /** The week `?week=` names on this school's clock, or null. 422 for a value that is no date. */
    private function requestedWeek(Request $request): ?PointsWeek
    {
        return SchoolPointsWeek::fromRequest($request, app(TenantContext::class)->get());
    }

    /** @return array<string,mixed> */
    private function weekMeta(?PointsWeek $week): array
    {
        return $week === null ? [] : ['week' => SchoolPointsWeek::payload($week, app(TenantContext::class)->get())];
    }

    /**
     * The awards this parent may read in this group, as a constrained query.
     *
     * Null would mean no standing in the group at all — unreachable from here,
     * because `subject()` has already 403'd such a caller. Handled anyway: an
     * unconstrained fallback is the one failure mode this surface must never
     * have, and "unreachable" is a claim about today's call order.
     */
    private function readable(Group $group): Builder
    {
        $query = $this->audience->readableAwardsQuery($this->contact(), $group);

        if ($query === null) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this group\'s behaviour records.');
        }

        return $query;
    }

    /**
     * One award as a parent sees it.
     *
     * The teacher's NOTE is included on purpose — it is the substance of the
     * record and the reason a parent looks. What is dropped is
     * `behavior_skill_id` (provenance for staff, meaningless to a family) and
     * `retained_until` (an internal retention clock).
     *
     * @return array<string,mixed>
     */
    private function serialize(BehaviorAward $award): array
    {
        return [
            'id' => (int) $award->id,
            'student' => $award->membership ? $this->student($award->membership) : null,
            // The SNAPSHOT, never the live skill: renaming or re-weighting a
            // skill describes next term, it does not retroactively restate what
            // a child was told in October.
            'skill_label' => $award->skill_label,
            'polarity' => $award->polarity(),
            'points' => (int) $award->points,
            'note' => $award->note,
            'awarded_at' => optional($award->awarded_at)->toIso8601String(),
            'awarded_by' => $award->awardedBy ? ['name' => $award->awardedBy->name] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function meta(array $extra = []): array
    {
        return parent::meta($extra + ['polarities' => BehaviorSkill::POLARITIES]);
    }
}
