<?php

namespace App\Support;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\BehaviorWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\PrizeLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The office's reconciliation view of the class store (T-003.4): for each class, what was
 * minted, spent, given back, paid out and written off, what is still held, and whether the
 * ledger still agrees with the points it came from.
 *
 * ## CLASS TOTALS ONLY. NO CHILD IS NAMED.
 *
 * The office administers the store but does not stand in a class, so it reads TOTALS, never a
 * child's balance: no name, no roster id, no per-student row, no list of who holds what.
 * (GroupAudience::mayReceiveClassStoreTotals is the decision; an office-run school-wide
 * store, which would let an administrator read every child's balance, is deliberately not
 * built.) The per-student grouping below happens in the database and collapses to counts
 * before anything leaves this class.
 *
 * ## What "reconciles" means
 *
 * `expected_minted` is recomputed from the awards themselves: for each of the last `$weeks`
 * closed weeks since the store began (`bucks_from`), `floor(positive points / points_per_buck)`
 * for each child, summed. `minted` is what the ledger holds for the same weeks. They differ
 * only for reasons the rules allow: a late change to a week older than the two-week
 * adjustment window, a clawback clamped at a zero balance, a child who left the class, or a
 * change of rate (a week keeps the rate it was minted at, and `expected_minted` uses today's). A
 * difference is therefore a question for the office, not an error, and the view says so.
 * `negative_balances` counts children whose ledger sums below zero: it should always be 0.
 *
 * ## A small class shows no figures at all
 *
 * In a class of one, "held now" IS that child's balance; in a class of two, with one child
 * holding, the same. So a class with fewer current students than
 * `groups.bucks.reconciliation_min_class_size` (default 5) is listed by name with
 * `suppressed: true` and NO figures, and is left out of the school totals as well, because a
 * total that included it would give its figures back by subtraction. Its teachers still see
 * it on their own screen; the office sees that it exists and why it shows nothing.
 *
 * ## Neither does a class that Bucks moved into or out of with a student
 *
 * A move carries ONE child's whole balance between two classes (ClassStore::carryBalance), and
 * the office knows exactly who was moved. So a class that holds at least one row of a transfer
 * kind is treated exactly as a class that is too small: listed by name, `suppressed: true`, no
 * figures, and left out of the school totals. Both classes stop showing in the same response in
 * which the pair first exists, so no figure, sum or difference, in one response or across a
 * before-and-after pair, equals the moved child's balance; and two year-end write-offs that
 * differ only by that child are never both shown. The arithmetic of a shown class is untouched:
 * a shown class holds no transfer row. The payload gives no reason for `suppressed`.
 *
 * What it costs: the class left stays hidden until its transfer rows are purged (about a year
 * after the last move out), and the class entered for as long as a moved child's ledger exists
 * there. A school that moves its classes up before the year's Bucks end therefore loses this
 * view for those classes. And it leaves one bit: a class that stops being shown after a single
 * move tells the office that child held more than nothing.
 */
final class ClassStoreReconciliation
{
    public const DEFAULT_WEEKS = 8;
    public const MAX_WEEKS = 26;

    /**
     * @param  Collection<int,Group>  $groups
     * @return array<string,mixed>
     */
    public static function forSchool(Masjid $masjid, Collection $groups, int $weeks = self::DEFAULT_WEEKS): array
    {
        $weeks = max(1, min(self::MAX_WEEKS, $weeks));
        $tz = SchoolPointsWeek::timezone((int) $masjid->id);
        $settings = ClassStoreSettings::for((int) $masjid->id);
        $window = self::window($tz, $settings['bucks_from'], $weeks);
        $from = $settings['bucks_from'] === null ? null : BucksMinter::startInstant($settings['bucks_from'], $tz);
        $minSize = self::minClassSize();

        // Current students per class, counted in the database; a class below the minimum shows nothing.
        $sizes = GroupMembership::query()
            ->whereIn('group_id', $groups->pluck('id')->all())
            ->participants()->current()
            ->groupBy('group_id')
            ->selectRaw('group_id, COUNT(*) as students')
            ->pluck('students', 'group_id')
            ->map(fn ($v): int => (int) $v);

        // Classes that Bucks moved into or out of with a student: one query, class ids only.
        $carried = PrizeLedgerEntry::query()
            ->whereIn('group_id', $groups->pluck('id')->all())
            ->whereIn('kind', PrizeLedgerEntry::TRANSFER_KINDS)
            ->distinct()
            ->pluck('group_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        [$shown, $small] = $groups->partition(
            fn (Group $g) => (int) ($sizes[$g->id] ?? 0) >= $minSize && ! $carried->has((int) $g->id)
        );
        $groups = $shown->values();
        $groupIds = $groups->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $byKind = PrizeLedgerEntry::query()
            ->whereIn('group_id', $groupIds)
            ->groupBy('group_id', 'kind')
            ->selectRaw('group_id, kind, SUM(amount) as bucks')
            ->get()
            ->groupBy('group_id');

        // Per child, in the database, and only ever counted: nothing below keeps a child.
        $holdings = PrizeLedgerEntry::query()
            ->whereIn('group_id', $groupIds)
            ->groupBy('group_id', 'group_membership_id')
            ->selectRaw('group_id, group_membership_id, SUM(amount) as bucks')
            ->get()
            ->groupBy('group_id');

        $classes = $groups->map(function (Group $group) use ($byKind, $holdings, $window, $settings, $from): array {
            $kinds = collect($byKind->get($group->id, []))->mapWithKeys(fn ($r) => [$r->kind => (int) $r->bucks]);
            $students = collect($holdings->get($group->id, []))->map(fn ($r): int => (int) $r->bucks);

            $minted = (int) ($kinds[PrizeLedgerEntry::KIND_EARNED] ?? 0) + (int) ($kinds[PrizeLedgerEntry::KIND_ADJUSTED] ?? 0);
            $inWindow = $window === [] ? 0 : self::mintedIn($group, $window);
            $expected = $window === [] ? 0 : self::expectedFromPoints($group, $window, $settings['points_per_buck'], $from);

            return [
                'group_id' => (int) $group->id,
                'name' => $group->name,
                'minted' => $minted,
                'redeemed' => -(int) ($kinds[PrizeLedgerEntry::KIND_REDEEMED] ?? 0),
                'reversed' => (int) ($kinds[PrizeLedgerEntry::KIND_REVERSAL] ?? 0),
                'cashed_out' => -(int) ($kinds[PrizeLedgerEntry::KIND_CASHED_OUT] ?? 0),
                'expired' => -(int) ($kinds[PrizeLedgerEntry::KIND_EXPIRED] ?? 0),
                'outstanding' => (int) $students->sum(),
                'children_holding' => $students->filter(fn (int $b) => $b > 0)->count(),
                'negative_balances' => $students->filter(fn (int $b) => $b < 0)->count(),
                'window_minted' => $inWindow,
                'window_expected' => $expected,
                'window_difference' => $inWindow - $expected,
                'weeks_converted' => $window === [] ? 0 : self::convertedWeeks((int) $group->id, $window),
            ];
        })->values();

        $sum = fn (string $field): int => (int) $classes->sum($field);

        // Listed, named, and nothing else: no figure, and none of it in the totals below.
        $suppressed = $small->map(fn (Group $group): array => [
            'group_id' => (int) $group->id,
            'name' => $group->name,
            'suppressed' => true,
        ])->values();

        return [
            'settings' => [
                'points_per_buck' => $settings['points_per_buck'],
                'paper_bucks_enabled' => $settings['paper_bucks_enabled'],
                'bucks_from' => $settings['bucks_from'],
            ],
            'timezone' => $tz,
            'window' => [
                'weeks' => count($window),
                'requested_weeks' => $weeks,
                'from' => $window === [] ? null : end($window)->startDate(),
                'to' => $window === [] ? null : $window[0]->lastDate(),
            ],
            // In the caller's display order: the shown classes, then the ones that show nothing.
            'classes' => $classes->map(fn (array $c): array => $c + ['suppressed' => false])->concat($suppressed)->values(),
            'min_class_size' => $minSize,
            'suppressed_classes' => $suppressed->count(),
            'totals' => [
                'minted' => $sum('minted'),
                'redeemed' => $sum('redeemed'),
                'reversed' => $sum('reversed'),
                'cashed_out' => $sum('cashed_out'),
                'expired' => $sum('expired'),
                'outstanding' => $sum('outstanding'),
                'children_holding' => $sum('children_holding'),
                'negative_balances' => $sum('negative_balances'),
                'window_minted' => $sum('window_minted'),
                'window_expected' => $sum('window_expected'),
                'window_difference' => $sum('window_difference'),
            ],
        ];
    }

    /** The fewest current students a class needs for its figures to be shown (never below 1). */
    public static function minClassSize(): int
    {
        return max(1, (int) config('groups.bucks.reconciliation_min_class_size', 5));
    }

    /**
     * The closed weeks in view, newest first: at most `$weeks`, never before the week that
     * holds `bucks_from`, and none at all while the store has not started.
     *
     * @return list<PointsWeek>
     */
    private static function window(string $tz, ?string $bucksFrom, int $weeks): array
    {
        if ($bucksFrom === null) {
            return [];
        }

        $first = PointsWeek::startingOn($bucksFrom, $tz);

        if ($first === null) {
            return [];
        }

        $out = [];
        $week = PointsWeek::containing(CarbonImmutable::instance(Date::now()), $tz)->previous();

        while (count($out) < $weeks && $week->startDate() >= $first->startDate()) {
            $out[] = $week;
            $week = $week->previous();
        }

        return $out;
    }

    /** @param  list<PointsWeek>  $window */
    private static function mintedIn(Group $group, array $window): int
    {
        return (int) PrizeLedgerEntry::query()
            ->where('group_id', $group->id)
            ->whereIn('kind', PrizeLedgerEntry::MINTED_KINDS)
            ->whereIn('week_start', array_map(fn (PointsWeek $w) => $w->startDate(), $window))
            ->sum('amount');
    }

    /** @param  list<PointsWeek>  $window */
    private static function convertedWeeks(int $groupId, array $window): int
    {
        return (int) BehaviorWeek::query()
            ->where('group_id', $groupId)
            ->whereIn('week_start', array_map(fn (PointsWeek $w) => $w->startDate(), $window))
            ->whereNotNull('prizes_converted_at')
            ->count();
    }

    /**
     * What the awards say the window should have minted: the SAME definition BucksMinter uses
     * (positive points only, whole bucks per child per week), recomputed from the awards.
     *
     * @param  list<PointsWeek>  $window
     */
    private static function expectedFromPoints(Group $group, array $window, int $rate, ?CarbonImmutable $from = null): int
    {
        $newest = $window[0];
        $oldest = end($window);
        $starts = array_map(fn (PointsWeek $w) => $w->startDate(), $window);

        $eligible = GroupMembership::query()->where('group_id', $group->id)->participants()->current()->pluck('id')
            ->merge(PrizeLedgerEntry::query()->where('group_id', $group->id)->whereIn('kind', PrizeLedgerEntry::MINTED_KINDS)
                ->whereIn('week_start', $starts)->pluck('group_membership_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->flip();

        $tz = $newest->timezone();
        $endsOn = $group->ends_on?->toDateString();
        $sums = [];

        BehaviorAward::query()
            ->where('group_id', $group->id)
            ->where('skill_polarity', '<>', BehaviorSkill::POLARITY_NEGATIVE)
            ->where('points', '>', 0)
            // bucks_from is a day: nothing before its midnight mints, even inside its week.
            ->awardedWithin($from !== null && $from->gt($oldest->startUtc()) ? $from : $oldest->startUtc(), $newest->endUtc())
            ->get(['group_membership_id', 'points', 'awarded_at'])
            ->each(function (BehaviorAward $a) use (&$sums, $tz, $eligible, $endsOn): void {
                if (! $eligible->has((int) $a->group_membership_id)) {
                    return;
                }

                $weekStart = PointsWeek::containing(CarbonImmutable::instance($a->awarded_at), $tz)->startDate();

                // A week that opens after the class's last day is not minted (BucksMinter).
                if ($endsOn !== null && $weekStart > $endsOn) {
                    return;
                }

                $key = (int) $a->group_membership_id.'|'.$weekStart;
                $sums[$key] = ($sums[$key] ?? 0) + (int) $a->points;
            });

        return (int) collect($sums)->sum(fn (int $points) => intdiv($points, max(1, $rate)));
    }
}
