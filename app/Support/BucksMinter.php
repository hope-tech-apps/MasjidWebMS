<?php

namespace App\Support;

use App\Models\BehaviorSkill;
use App\Models\BehaviorWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\PrizeLedgerEntry;
use Carbon\CarbonImmutable;

/**
 * Turns a class's closed points weeks into Manara Bucks (T-003.4, owner B6, R1 and R2).
 *
 * ## What earns a buck
 *
 * POSITIVE points only. A week's bucks for one child are `floor(P / points_per_buck)` where
 * `P` is the sum of the child's live awards that week whose skill is not negative AND whose
 * points are above zero. Negatives never take bucks away (R2): a negative award is not in
 * `P` and nothing subtracts for it. Nor does a positive skill a teacher gave with a
 * negative override (a docking): "points above zero" excludes it, so a docking cannot mint
 * (the tempting `ABS(points)` would). A revoked award is not live (the soft-delete scope), so
 * revoking is a late change like any other.
 *
 * Whole bucks per child per week: at one point a buck (the default) nothing is lost; at a
 * dearer rate the remainder of a week is not carried over. Said in DECISIONS.md.
 *
 * ## When: the week boundary, once, plus a two-week window of late changes
 *
 * A week is Sunday 00:00 to Sunday 00:00 on the SCHOOL's clock (PointsWeek), and it is CLOSED
 * once it has ended. Each hourly run:
 *   - MINTS every closed week from `bucks_from` that has no `earned` row for a child (one
 *     `earned` row per child and week, `dedupe_key = earned:{membership}:{week_start}`);
 *   - RE-READS only the last two closed weeks (`ADJUST_WEEKS`), and where a child's points
 *     have since changed (a late award, a revocation), writes an `adjusted` DELTA, clamped so
 *     the balance never goes below zero. Older weeks are frozen: a term-old correction to
 *     points does not silently reach into a balance a child already spent.
 *
 * `week_basis` records what the week's points came to, as accounted after each row. A
 * clamped clawback is therefore taken ONCE: the next run compares against the basis, not
 * against what was actually credited, so a shortfall is never collected later out of a
 * different week's earnings.
 *
 * Nothing retroactive by surprise: a school's `bucks_from` is set to the start of the week in
 * progress the first time the store is found switched on, so a term of history is not paid out
 * on the day a SuperAdmin flips the grant. A SuperAdmin may move it earlier on purpose.
 *
 * Runs with no tenant bound (a console run), so every query names the class explicitly.
 */
final class BucksMinter
{
    /** How many of the most recent closed weeks a late change may still reach. */
    public const ADJUST_WEEKS = 2;

    /** The furthest back one run will mint, so a `bucks_from` set years ago cannot become years of queries. */
    public const MAX_CATCH_UP_WEEKS = 60;

    /**
     * @return array{weeks:int,minted_students:int,minted_bucks:int,adjusted_students:int,adjusted_bucks:int}
     */
    public static function forClass(Masjid $masjid, Group $group, CarbonImmutable $now, string $bucksFrom, int $rate, bool $dry = false): array
    {
        $out = ['weeks' => 0, 'minted_students' => 0, 'minted_bucks' => 0, 'adjusted_students' => 0, 'adjusted_bucks' => 0];

        $tz = SchoolPointsWeek::timezone((int) $masjid->id);
        $first = PointsWeek::startingOn($bucksFrom, $tz);

        if ($first === null) {
            return $out;
        }

        // Closed weeks, newest first: the week before the one in progress, and back.
        $weeks = [];
        $week = PointsWeek::containing($now, $tz)->previous();

        while (count($weeks) < self::MAX_CATCH_UP_WEEKS && $week->startDate() >= $first->startDate()) {
            $weeks[] = $week;
            $week = $week->previous();
        }

        foreach (array_reverse($weeks, true) as $index => $closed) {
            $inWindow = $index < self::ADJUST_WEEKS;

            if (! $inWindow && BehaviorWeek::prizesConverted((int) $group->id, $closed->startDate())) {
                continue;
            }

            $out['weeks']++;
            $one = self::week($group, $closed, $rate, $dry, $inWindow);

            foreach (['minted_students', 'minted_bucks', 'adjusted_students', 'adjusted_bucks'] as $field) {
                $out[$field] += $one[$field];
            }

            if (! $dry) {
                BehaviorWeek::markPrizesConverted((int) $masjid->id, (int) $group->id, $closed->startDate());
            }
        }

        return $out;
    }

    /**
     * One class's one closed week.
     *
     * @return array{minted_students:int,minted_bucks:int,adjusted_students:int,adjusted_bucks:int}
     */
    private static function week(Group $group, PointsWeek $week, int $rate, bool $dry, bool $adjust): array
    {
        $out = ['minted_students' => 0, 'minted_bucks' => 0, 'adjusted_students' => 0, 'adjusted_bucks' => 0];
        $start = $week->startDate();

        // Positive points only, by the definition above. Grouped in the database.
        $points = $group->behaviorAwards()
            ->where('skill_polarity', '<>', BehaviorSkill::POLARITY_NEGATIVE)
            ->where('points', '>', 0)
            ->awardedWithin($week->startUtc(), $week->endUtc())
            ->selectRaw('group_membership_id, SUM(points) as pts')
            ->groupBy('group_membership_id')
            ->pluck('pts', 'group_membership_id')
            ->map(fn ($v): int => (int) $v)
            ->all();

        // Children who already have a row for this week must be re-read even when their
        // points have gone to nothing (every award revoked): that is what an adjustment is.
        $latest = PrizeLedgerEntry::withoutMasjidScope()
            ->where('group_id', $group->id)
            ->where('week_start', $start)
            ->whereIn('kind', PrizeLedgerEntry::MINTED_KINDS)
            ->orderBy('id')
            ->get(['id', 'group_membership_id', 'kind', 'week_basis'])
            ->groupBy('group_membership_id');

        $current = GroupMembership::withoutMasjidScope()
            ->where('group_id', $group->id)
            ->participants()->current()
            ->pluck('id')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $students = array_values(array_unique(array_merge(
            array_values(array_intersect(array_map('intval', array_keys($points)), $current)),
            $latest->keys()->map(fn ($v): int => (int) $v)->all(),
        )));

        foreach ($students as $membershipId) {
            $expected = intdiv($points[$membershipId] ?? 0, max(1, $rate));
            $rows = $latest->get($membershipId, collect());
            $hasEarned = $rows->contains(fn ($r) => $r->kind === PrizeLedgerEntry::KIND_EARNED);

            if (! $hasEarned) {
                // A child who has left is not minted for; one who is here and earned is.
                if ($expected < 1 || ! in_array($membershipId, $current, true)) {
                    continue;
                }

                if ($dry) {
                    $out['minted_students']++;
                    $out['minted_bucks'] += $expected;

                    continue;
                }

                $entry = ClassStore::appendForSystem($group, $membershipId, fn (int $balance): array => [
                    'kind' => PrizeLedgerEntry::KIND_EARNED,
                    'amount' => $expected,
                    'week_start' => $start,
                    'week_basis' => $expected,
                    'dedupe_key' => 'earned:'.$membershipId.':'.$start,
                ]);

                if ($entry !== null) {
                    $out['minted_students']++;
                    $out['minted_bucks'] += $expected;
                }

                continue;
            }

            if (! $adjust) {
                continue;
            }

            $basis = (int) ($rows->last()->week_basis ?? 0);

            if ($expected === $basis) {
                continue;
            }

            if ($dry) {
                $out['adjusted_students']++;
                $out['adjusted_bucks'] += $expected - $basis;

                continue;
            }

            $written = 0;

            $entry = ClassStore::appendForSystem($group, $membershipId, function (int $balance) use ($membershipId, $start, $expected, $basis, &$written): ?array {
                $delta = $expected - $basis;

                // Never below zero: a clawback takes what the child still holds and no more.
                // The basis moves to `expected` either way, so the rest is forgiven once.
                if ($delta < 0) {
                    $delta = -min(-$delta, max(0, $balance));
                }

                $written = $delta;

                // The next free sequence number for this child and week. A concurrent run
                // computes the same number, and the unique key lets only one of them write.
                $n = PrizeLedgerEntry::withoutMasjidScope()
                    ->where('group_membership_id', $membershipId)
                    ->where('week_start', $start)
                    ->where('kind', PrizeLedgerEntry::KIND_ADJUSTED)
                    ->count() + 1;

                return [
                    'kind' => PrizeLedgerEntry::KIND_ADJUSTED,
                    'amount' => $delta,
                    'week_start' => $start,
                    'week_basis' => $expected,
                    'dedupe_key' => 'adjusted:'.$membershipId.':'.$start.':'.$n,
                ];
            });

            if ($entry !== null) {
                $out['adjusted_students']++;
                $out['adjusted_bucks'] += $written;
            }
        }

        return $out;
    }
}
