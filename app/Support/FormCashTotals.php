<?php

namespace App\Support;

use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Who is holding the festival's cash, and how much (DECISIONS.md 2026-09-11; festival
 * brief, blocker 2): the figures the team counts the physical cash against on the night
 * and the morning after.
 *
 * ONE GROUP BY over the responses screen's own shared query, so the panel, the list and
 * the CSV cannot disagree about which rows are in scope: whatever the list is filtered
 * to, these are the totals of those rows.
 *
 * ## Nothing taken is ever dropped
 *
 * A cancelled cash row is NOT left out. Cancelling is how a comp or a duplicate is dealt
 * with, and it takes the row out of what its holder owes, so it gets its own column
 * ("taken, then cancelled") instead of vanishing: `taken_minor` (owed + cancelled) does
 * not move when a row is cancelled. Beside each code sits its lifetime `use_count`, and
 * every code on the form is listed whether it has taken anything or not — a code used
 * more often than it has rows here is a gap someone should be able to see.
 *
 * Cash is put against whoever holds it: the code's holder for an entry made at the gate,
 * the admin who pressed "Take cash" for one taken at the table (marked_paid_by_user_id).
 * A cash row with neither — none is written today — is reported as unattributed rather
 * than lost from the total.
 *
 * ## reorder() before groupBy
 *
 * The shared query sorts. MySQL's ONLY_FULL_GROUP_BY rejects an ORDER BY on a column that
 * is not grouped, and SQLite, which the suite runs on, never complains — so a forgotten
 * reorder() passes every test and fails in production. Pinned by
 * tests/Feature/FormResponsesMoneyAdminTest.php.
 */
final class FormCashTotals
{
    /**
     * @param  EloquentBuilder|Relation  $responses  the shared, filtered responses query (it is not modified)
     * @param  Collection<int,FormStaffCode>  $codes  every code issued on the form
     * @return array{currency: string, holders: array<int,array<string,mixed>>, totals: array<string,int>, other_paid: array<string,array<string,int>>}
     */
    public static function for(EloquentBuilder|Relation $responses, Collection $codes): array
    {
        $base = clone $responses->toBase();

        $holders = self::holders(
            self::sums($base, ['staff_code_id', 'marked_paid_by_user_id'], [FormResponse::METHOD_CASH]),
            $codes
        );

        // For context beside the cash: what was paid some other way, over the same rows.
        $other = self::sums($base, ['payment_method'], [FormResponse::METHOD_ONLINE, FormResponse::METHOD_EXTERNAL])
            ->keyBy('payment_method');

        return [
            // A paying form is USD (StoreFormRequest::crossCheck()), and every figure is cents.
            'currency' => 'usd',
            'holders' => $holders,
            'totals' => self::total($holders),
            'other_paid' => [
                FormResponse::METHOD_ONLINE => self::figures($other->get(FormResponse::METHOD_ONLINE), 'total'),
                FormResponse::METHOD_EXTERNAL => self::figures($other->get(FormResponse::METHOD_EXTERNAL), 'total'),
            ],
        ];
    }

    /**
     * The paid rows of the given methods, summed per group, with cancelled rows counted in
     * their own columns. Portable SQL: CASE inside SUM, on both drivers.
     *
     * @param  array<int,string>  $groupBy
     * @param  array<int,string>  $methods
     * @return Collection<int,object>
     */
    private static function sums(Builder $base, array $groupBy, array $methods): Collection
    {
        $query = clone $base;
        $grammar = $query->getGrammar();
        [$status, $entries, $total] = [$grammar->wrap('status'), $grammar->wrap('entry_count'), $grammar->wrap('total_minor')];

        return $query
            ->reorder()
            ->where('payment_status', FormResponse::PAYMENT_PAID)
            ->whereIn('payment_method', $methods)
            ->groupBy($groupBy)
            ->select($groupBy)
            ->selectRaw(
                "SUM(CASE WHEN {$status} = ? THEN 0 ELSE 1 END) AS submissions, "
                . "SUM(CASE WHEN {$status} = ? THEN 0 ELSE {$entries} END) AS people, "
                . "SUM(CASE WHEN {$status} = ? THEN 0 ELSE {$total} END) AS total_minor, "
                . "SUM(CASE WHEN {$status} = ? THEN 1 ELSE 0 END) AS cancelled_submissions, "
                . "SUM(CASE WHEN {$status} = ? THEN {$total} ELSE 0 END) AS cancelled_total_minor",
                array_fill(0, 5, FormResponse::STATUS_CANCELLED)
            )
            ->get();
    }

    /**
     * One line per holder: every code on the form first (by name), then the admins who
     * took cash at the table, then anything unattributed.
     *
     * @param  Collection<int,object>  $rows
     * @param  Collection<int,FormStaffCode>  $codes
     * @return array<int,array<string,mixed>>
     */
    private static function holders(Collection $rows, Collection $codes): array
    {
        $holders = [];

        foreach ($codes as $code) {
            $holders['code:' . $code->id] = self::holder('code', (int) $code->id, null, (string) $code->holder_name, $code);
        }

        // withTrashed: a removed admin still owes the cash they took.
        $names = User::withTrashed()
            ->whereIn('id', $rows->pluck('marked_paid_by_user_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        foreach ($rows as $row) {
            $codeId = $row->staff_code_id !== null ? (int) $row->staff_code_id : null;
            $userId = $row->marked_paid_by_user_id !== null ? (int) $row->marked_paid_by_user_id : null;

            $key = match (true) {
                $codeId !== null => 'code:' . $codeId,
                $userId !== null => 'admin:' . $userId,
                default => 'unattributed',
            };

            $holders[$key] ??= match (true) {
                // A code this form's list does not hold: still somebody's cash.
                $codeId !== null => self::holder('code', $codeId, null, 'Code #' . $codeId, null),
                $userId !== null => self::holder('admin', null, $userId, (string) ($names[$userId] ?? 'User #' . $userId), null),
                default => self::holder('unattributed', null, null, 'Not attributed', null),
            };

            foreach (self::figures($row) as $figure => $value) {
                $holders[$key][$figure] += $value;
            }
        }

        $rank = ['code' => 0, 'admin' => 1, 'unattributed' => 2];

        return collect($holders)
            ->sortBy([
                fn (array $a, array $b) => $rank[$a['kind']] <=> $rank[$b['kind']],
                fn (array $a, array $b) => strcasecmp($a['holder_name'], $b['holder_name']),
            ])
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private static function holder(string $kind, ?int $codeId, ?int $userId, string $name, ?FormStaffCode $code): array
    {
        return [
            'kind' => $kind,
            'staff_code_id' => $codeId,
            'user_id' => $userId,
            'holder_name' => $name,
            'code_hint' => $code?->code_hint,
            'revoked' => $code?->isRevoked() ?? false,
            'use_count' => $code !== null ? (int) $code->use_count : null,
        ] + self::figures(null);
    }

    /**
     * One group's sums as integers (MySQL hands SUM back as a decimal string). $noun names
     * the money: a holder's is cash, the other methods' a total.
     *
     * @return array<string,int>
     */
    private static function figures(?object $row, string $noun = 'cash'): array
    {
        $kept = (int) ($row?->total_minor ?? 0);
        $cancelled = (int) ($row?->cancelled_total_minor ?? 0);

        return [
            'submissions' => (int) ($row?->submissions ?? 0),
            'people' => (int) ($row?->people ?? 0),
            "{$noun}_minor" => $kept,
            'cancelled_submissions' => (int) ($row?->cancelled_submissions ?? 0),
            "cancelled_{$noun}_minor" => $cancelled,
            'taken_minor' => $kept + $cancelled,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $holders
     * @return array<string,int>
     */
    private static function total(array $holders): array
    {
        $totals = self::figures(null);

        foreach ($holders as $holder) {
            foreach (array_keys($totals) as $figure) {
                $totals[$figure] += (int) $holder[$figure];
            }
        }

        return $totals;
    }
}
