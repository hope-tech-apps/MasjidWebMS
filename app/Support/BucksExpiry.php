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
 * as soon as that day has passed on the SCHOOL's clock. A class with no end date in a school
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
 * The retention purge then removes a child's rows only when EVERY one of them is due
 * (PrizeLedgerEntry::purgeDueSets), and every row is stamped from its own date, so the sweep
 * cannot separate what was earned from what was spent and leave a partial or negative balance.
 * A reversal is refused after an expiry (ClassStore::reverse): an expired balance stays ended.
 *
 * Runs with no tenant bound (a console run): every query names the class explicitly.
 */
final class BucksExpiry
{
    /**
     * The cutoffs ('Y-m-d', the first day AFTER the class or the year) that have passed for
     * this class, oldest first.
     *
     * @return list<string>
     */
    public static function cutoffs(Group $group, SchoolCalendar $calendar): array
    {
        $today = $calendar->today();
        $cutoffs = [];

        if ($group->ends_on !== null && $group->ends_on->toDateString() < $today) {
            $cutoffs[] = $group->ends_on->copy()->addDay()->toDateString();
        }

        foreach ($calendar->years() as $year) {
            if ($year->last_day->toDateString() < $today) {
                $cutoffs[] = $year->last_day->copy()->addDay()->toDateString();
            }
        }

        $cutoffs = array_values(array_unique($cutoffs));
        sort($cutoffs);

        return $cutoffs;
    }

    /**
     * @return array{students:int,bucks:int}
     */
    public static function forClass(Masjid $masjid, Group $group, SchoolCalendar $calendar, bool $dry = false): array
    {
        $out = ['students' => 0, 'bucks' => 0];
        $cutoffs = self::cutoffs($group, $calendar);

        if ($cutoffs === []) {
            return $out;
        }

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

                $decide = function (int $balance) use ($membershipId, $cutoff, &$amount): ?array {
                    $later = (int) DB::table('prize_ledger_entries')
                        ->where('group_membership_id', $membershipId)
                        ->whereIn('kind', PrizeLedgerEntry::MINTED_KINDS)
                        ->where('week_start', '>=', $cutoff)
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
}
