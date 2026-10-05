<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Masjid;
use App\Models\PrizeLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manara Bucks end with the class and with the school year (T-003.4, R5).
 *
 * ## The cutoffs
 *
 * A balance is written off at each of:
 *   - the day after a class's `ends_on`, and
 *   - the day after the last day of each of the school's calendar years (SchoolCalendar),
 * once that day is `expiry_grace_days` old on the SCHOOL's clock (below). A class with no end date in a school
 * with no calendar never expires: nothing is invented for it.
 *
 * ## An expiry is a SET, and it can be run again for the same cutoff
 *
 * The write-off is a single `expired` row per child and pass (a negative amount, so the
 * balance reads zero afterwards). It takes what the child holds that was minted BEFORE the
 * cutoff: `balance - (minted from weeks starting on or after the cutoff)`, clamped to
 * `[0, balance]`, so a new year's first earnings are never swept away by the last year's
 * expiry and a child who spent more than they held is never pushed negative.
 *
 * The cutoff is due the day after the class or the year, but the week that CONTAINS it is
 * only minted once it has closed (BucksMinter mints closed weeks), and a late award reaches
 * the two weeks before that. So bucks for a pre-cutoff week can arrive AFTER the first
 * write-off. Each run therefore works the sum out again and writes a further row for what is
 * left over: `dedupe_key = expired:{membership}:{cutoff}:{n}`, with `n` counting this
 * cutoff's earlier rows, taken under the student's lock so two overlapping runs cannot both
 * write it. A run that finds nothing left writes nothing, so the hourly sweep settles at once
 * (the amount is `balance - later`, and after a write-off it is zero). A tiny window remains
 * between the hourly mint (:10) and this sweep (:40) in which those bucks show; they are gone
 * by the next expiry run.
 *
 * ## A balance carried from another class
 *
 * When a student is moved, their balance follows them as a `transfer_out` on the row they left
 * and a `transfer_in` on the row in the new class (ClassStore::carryBalance). A carried amount
 * has no week, so both rows carry `counts_from`: the newest week the old row had been minted
 * for, never later than the day the move was run. "Minted from weeks starting on or after the
 * cutoff" therefore also counts BOTH transfer kinds whose `counts_from` is on or after the
 * cutoff, each with its own sign:
 *
 *   - the `transfer_in` adds its amount, so a cutoff older than the move (last June's, for a
 *     student moved in October) takes nothing of a balance that had been minted since it, while
 *     a cutoff after the move takes the carried amount like any other;
 *   - the `transfer_out` takes its amount back out on the row that was left, so when a write-off
 *     is later given back onto that row (below), the cutoff that replaces it treats what is
 *     there exactly as it treats a classmate's, and a moved child does not keep both the carried
 *     Bucks and the given-back ones.
 *
 * One date cannot split a balance that straddles a cutoff already due and not yet written off
 * on the old row at the instant of the move (the half hour between the :10 mint and the :40
 * sweep, the hour after a past year end is typed, a school whose sweep is not running): the
 * whole of it is kept, never lost. A move itself writes no `expired` row and gives none back.
 *
 * ## A mistyped date must not wipe a class, and a corrected one gives it back
 *
 * The cutoffs come from dates the office types (a class's `ends_on`, a school year's
 * `last_day`), and any date is accepted there. So a cutoff acts only once it is
 * `groups.bucks.expiry_grace_days` days old (default 7, and never more): a wrong date typed on Monday is
 * normally seen and corrected long before it takes anything. And when a cutoff that has
 * already been written off DISAPPEARS (the date was corrected, or moved later), every
 * `expired` row written for it is given back by a compensating `reversal` row pointing at it
 * (`dedupe_key = reversal:{expired entry}`, once, under the student's lock). A cutoff that
 * merely moved later is then written off again when the new one is due. A cutoff that still
 * exists but is not yet due (the grace was raised) is left alone: only a date that no longer
 * exists is undone. Both halves are append-only: nothing written is ever edited.
 *
 * The retention purge then removes a child's rows only when EVERY one of them is due
 * (PrizeLedgerEntry::purgeDueSets), and every row is stamped from its own date, so the sweep
 * cannot separate what was earned from what was spent and leave a partial or negative balance.
 * A reversal is refused after an expiry (ClassStore::reverse): an expired balance stays ended.
 *
 * Runs with no tenant bound (a console run): every query names the class explicitly.
 */
final class BucksExpiry
{
    /** The longest a cutoff may wait: one week, the length of a minted week (see graceDays). */
    public const MAX_GRACE_DAYS = 7;

    /**
     * How many days a cutoff waits before it acts, from config: never below zero and NEVER
     * ABOVE SEVEN, whatever the environment says.
     *
     * The bound is what makes "Bucks end with the school year" hold for a student moved in the
     * days after the last day. A week that starts on or after a cutoff closes seven days later
     * at the earliest, and only closed weeks mint, so while a cutoff is still inside a grace of
     * seven days nothing on any row can be dated on or after it, and a balance carried to
     * another class in those days is written off there with everybody else's. With a longer
     * grace a week could be minted beyond a cutoff still waiting, a move in those days would
     * carry that week's date, and the whole old-year balance would be kept. The value comes from
     * the environment, so the bound is enforced here and not left to a default.
     */
    public static function graceDays(): int
    {
        return min(self::MAX_GRACE_DAYS, max(0, (int) config('groups.bucks.expiry_grace_days', 7)));
    }

    /**
     * EVERY cutoff this class has ('Y-m-d', the first day AFTER the class or the year), due or
     * not, oldest first: the set an `expired` row's cutoff is checked against to see whether its
     * date still exists at all.
     *
     * @return list<string>
     */
    public static function allCutoffs(Group $group, SchoolCalendar $calendar): array
    {
        $cutoffs = [];

        if ($group->ends_on !== null) {
            $cutoffs[] = $group->ends_on->copy()->addDay()->toDateString();
        }

        foreach ($calendar->years() as $year) {
            $cutoffs[] = $year->last_day->copy()->addDay()->toDateString();
        }

        $cutoffs = array_values(array_unique($cutoffs));
        sort($cutoffs);

        return $cutoffs;
    }

    /**
     * The cutoffs that are DUE for this class: passed on the school's clock and at least
     * graceDays() old, oldest first.
     *
     * @return list<string>
     */
    public static function cutoffs(Group $group, SchoolCalendar $calendar): array
    {
        $latest = CarbonImmutable::parse($calendar->today())->subDays(self::graceDays())->toDateString();

        return array_values(array_filter(
            self::allCutoffs($group, $calendar),
            fn (string $cutoff): bool => $cutoff <= $latest,
        ));
    }

    /**
     * @return array{students:int,bucks:int,restored_students:int,restored_bucks:int}
     */
    public static function forClass(Masjid $masjid, Group $group, SchoolCalendar $calendar, bool $dry = false): array
    {
        $out = ['students' => 0, 'bucks' => 0, 'restored_students' => 0, 'restored_bucks' => 0];

        // First give back what was written off for a date that no longer exists, so a cutoff that
        // moved to a date already due is written off again below from the restored balance.
        self::restoreVanished($group, self::allCutoffs($group, $calendar), $dry, $out);

        $cutoffs = self::cutoffs($group, $calendar);

        if ($cutoffs === []) {
            return $out;
        }

        // The transfer half of "later" names `counts_from`, which a deploy serves code for before
        // it migrates. Without the column there can be no transfer row either.
        $transfers = ClassStore::carryReady();

        $holding = DB::table('prize_ledger_entries')
            ->where('group_id', $group->id)
            ->groupBy('group_membership_id')
            ->havingRaw('SUM(amount) > 0')
            ->pluck('group_membership_id')
            ->map(fn ($v): int => (int) $v)
            ->all();

        foreach ($cutoffs as $cutoff) {
            foreach ($holding as $membershipId) {
                // What this cutoff has to write off, decided under the student's lock.
                $amount = 0;

                $decide = function (int $balance) use ($membershipId, $cutoff, $transfers, &$amount): ?array {
                    // What this row holds from the cutoff on: what was minted for a week that
                    // starts on or after it, and a balance carried in or out that counts from a
                    // day on or after it, the one carried out with its minus sign.
                    $later = (int) DB::table('prize_ledger_entries')
                        ->where('group_membership_id', $membershipId)
                        ->where(function ($q) use ($cutoff, $transfers) {
                            $q->where(fn ($w) => $w
                                ->whereIn('kind', PrizeLedgerEntry::MINTED_KINDS)
                                ->where('week_start', '>=', $cutoff));

                            if ($transfers) {
                                $q->orWhere(fn ($w) => $w
                                    ->whereIn('kind', PrizeLedgerEntry::TRANSFER_KINDS)
                                    ->where('counts_from', '>=', $cutoff));
                            }
                        })
                        ->sum('amount');

                    $amount = max(0, min($balance, $balance - $later));

                    if ($amount < 1) {
                        return null;
                    }

                    // This cutoff's next pass, counted under the student's lock.
                    $prefix = 'expired:'.$membershipId.':'.$cutoff.':';
                    $n = PrizeLedgerEntry::withoutMasjidScope()
                        ->where('group_membership_id', $membershipId)
                        ->where('dedupe_key', 'like', $prefix.'%')
                        ->count() + 1;

                    return [
                        'kind' => PrizeLedgerEntry::KIND_EXPIRED,
                        'amount' => -$amount,
                        'dedupe_key' => $prefix.$n,
                    ];
                };

                if ($dry) {
                    // Same arithmetic, no write: the balance read here is the unlocked one, which
                    // is all a dry run needs.
                    $balance = (int) DB::table('prize_ledger_entries')->where('group_membership_id', $membershipId)->sum('amount');
                    $decide($balance);

                    if ($amount > 0) {
                        $out['students']++;
                        $out['bucks'] += $amount;
                    }

                    continue;
                }

                $entry = ClassStore::appendForSystem($group, $membershipId, $decide);

                if ($entry !== null) {
                    $out['students']++;
                    $out['bucks'] += $amount;
                }
            }
        }

        return $out;
    }

    /**
     * Give back every `expired` row of this class whose cutoff is not among `$existing` (its
     * date was corrected or moved later), once each, as a `reversal` pointing at it.
     *
     * @param  list<string>  $existing
     * @param  array{students:int,bucks:int,restored_students:int,restored_bucks:int}  $out
     */
    private static function restoreVanished(Group $group, array $existing, bool $dry, array &$out): void
    {
        $rows = PrizeLedgerEntry::withoutMasjidScope()
            ->where('group_id', $group->id)
            ->where('kind', PrizeLedgerEntry::KIND_EXPIRED)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('prize_ledger_entries as undo')
                    ->whereColumn('undo.reverses_entry_id', 'prize_ledger_entries.id');
            })
            ->orderBy('id')
            ->get(['id', 'group_membership_id', 'amount', 'dedupe_key']);

        $restored = [];

        foreach ($rows as $row) {
            $cutoff = self::cutoffOf((string) $row->dedupe_key);

            // A row whose key names no cutoff is not this sweep's to judge; one whose cutoff still
            // exists (due or not) stands.
            if ($cutoff === null || in_array($cutoff, $existing, true)) {
                continue;
            }

            $amount = -((int) $row->amount);

            if ($amount < 1) {
                continue;
            }

            if ($dry) {
                $restored[(int) $row->group_membership_id] = true;
                $out['restored_bucks'] += $amount;

                continue;
            }

            $entry = ClassStore::appendForSystem($group, (int) $row->group_membership_id, fn (int $balance): array => [
                'kind' => PrizeLedgerEntry::KIND_REVERSAL,
                'amount' => $amount,
                'reverses_entry_id' => (int) $row->id,
                'note' => 'Expiry undone: the end date it came from was corrected.',
                'dedupe_key' => 'reversal:'.$row->id,
            ]);

            if ($entry !== null) {
                $restored[(int) $row->group_membership_id] = true;
                $out['restored_bucks'] += $amount;
            }
        }

        $out['restored_students'] += count($restored);
    }

    /** The cutoff an `expired` row was written for, from its key `expired:{m}:{cutoff}:{n}`. */
    public static function cutoffOf(string $dedupeKey): ?string
    {
        return preg_match('/^expired:\d+:(\d{4}-\d{2}-\d{2})(?::\d+)?$/', $dedupeKey, $m) === 1 ? $m[1] : null;
    }
}
