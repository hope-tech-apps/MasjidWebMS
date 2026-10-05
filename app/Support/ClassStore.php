<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/**
 * Every WRITE to the Manara Bucks ledger that a person makes, in one place (T-003.4).
 *
 * Redeem, reverse, cash out, the internal `append()` that minting and expiry share, and the
 * pair that carries a balance to another class when a student is moved (`carryBalance`).
 * Nothing else writes `prize_ledger_entries`, so the rules below cannot be forgotten by a
 * second caller.
 *
 * ## The rules
 *
 *  - APPEND-ONLY. A correction is a `reversal` row pointing at the entry it undoes; there
 *    is no update and no delete (PrizeLedgerEntry refuses both, and no route exists).
 *  - EVERY WRITE LOCKS THE STUDENT'S ROSTER ROW FIRST (and the prize row, for a redemption),
 *    inside one transaction, so two taps, two teachers or a redemption racing the weekly
 *    minting are serialised on the child and the second one sees the first one's rows.
 *    `lockForUpdate` is what MySQL honours; SQLite (the suite) has no row locks, so the
 *    invariant is ALSO checked after the write: if the child's balance would be below zero,
 *    the transaction is rolled back. A balance can never go negative, with or without the
 *    lock, and a test proves it without one.
 *  - IDEMPOTENT. A `request_id` from the client (one per write, required at the HTTP door)
 *    makes a double-tap or a retry a replay, not a second deduction; a reversal is unique per
 *    entry by construction. A replay returns the row it already wrote and touches nothing, and
 *    ONLY when it asks for the same thing: the same id with another prize (or another cash-out
 *    amount) is a 409 `request_id_reused`, never the first row passed off as the second's.
 *  - THE PRIZE MUST BE THIS SCHOOL'S AND EITHER SCHOOL-WIDE OR THIS CLASS'S OWN. Another
 *    school's prize (invisible through the tenant scope) and another class's are refused.
 *  - STOCK is optional (NULL = unlimited). It is decremented in the same transaction as the
 *    redemption, with a guard that refuses to go below zero, and given back by a reversal.
 *  - CASH-OUT TO PAPER is built and OFF: refused here, not just in a controller, while the
 *    school's `paper_bucks_enabled` is false.
 *  - A BALANCE FOLLOWS A MOVED STUDENT, from the commit that makes the move call the writer.
 *    NOT YET: at this commit nothing calls `carryBalance`, a move leaves the balance on the
 *    old row, and no transfer row can exist. When it is wired, the move writes one
 *    `transfer_out` on the roster row being left and one `transfer_in` on the row in the new
 *    class, for the old row's WHOLE balance or not at all, both rows or neither. It is the one
 *    write here that takes no lock of its own: its only caller will already hold both roster
 *    rows. What ACTS TODAY is the row's own state: a row left behind by a move is CARRIED AWAY
 *    (a leaving date and a "moved to"), the mint writes nothing on it, and a prize given from
 *    it can no longer be undone there.
 *
 * ## Reading a balance
 *
 * `balances()` takes a query ALREADY constrained by GroupAudience::readablePrizeLedgerQuery().
 * The one raw SUM in this class (`rawBalance`) exists to enforce the invariant inside a write
 * the caller was already authorised to make; it is private and is never returned to a client.
 */
final class ClassStore
{
    /** Longest request id a client may send: a UUID. */
    public const REQUEST_ID_MAX = 36;

    /** A client's request id: a UUID or a short token, never something that could be a key. */
    public const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_-]{8,36}$/';

    /** carryRule(): Bucks go with a student moved between these two classes on this day. */
    public const CARRY_MOVE = 'move';

    /** carryRule(): the class being left ended before today, so its Bucks end with it. */
    public const CARRY_FROM_ENDED = 'from_ended';

    /** carryRule(): the class being entered ended before today, so nothing moves into it. */
    public const CARRY_TO_ENDED = 'to_ended';

    /** A missing `counts_from` column is asked about again after this long (carryReady). */
    private const CARRY_RECHECK_SECONDS = 30;

    /** @var array{0: bool, 1: int}|null  whether the column was there, and when that was asked */
    private static ?array $carryColumnSeen = null;

    // ------------------------------------------------------------------ reads

    /**
     * Balances of these students, from a query the caller obtained through the audience.
     *
     * @param  Builder<PrizeLedgerEntry>  $readable  GroupAudience::readablePrizeLedgerQuery()
     * @param  array<int,int>  $membershipIds
     * @return array<int,int>  membership id => bucks (a student with no rows is absent, read as 0)
     */
    public static function balances(Builder $readable, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        return $readable->clone()
            ->whereIn('group_membership_id', $membershipIds)
            ->selectRaw('group_membership_id, SUM(amount) as bucks')
            ->groupBy('group_membership_id')
            ->pluck('bucks', 'group_membership_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * The 20/10/5/1 note set that adds up to `$amount`, largest notes first, as
     * `['20' => n, '10' => n, '5' => n, '1' => n]`. Always sums to `$amount`.
     *
     * @return array<string,int>
     */
    public static function breakdown(int $amount): array
    {
        $left = max(0, $amount);
        $out = [];

        foreach (PrizeLedgerEntry::NOTES as $note) {
            $out[(string) $note] = intdiv($left, $note);
            $left -= $out[(string) $note] * $note;
        }

        return $out;
    }

    /**
     * Do Bucks move between these two classes on this day (the school's today)? A rule about two
     * classes and a date. It reads no ledger and says nothing about any child, so the move may
     * print a sentence from it, and `carryBalance` asks the same function before it writes: the
     * sentence and the write cannot disagree.
     *
     * A class that ENDED before today keeps its Bucks: its own end date is a cutoff only its own
     * sweep ever evaluates, so a balance carried out in the days before that write-off would
     * escape it for good. And nothing moves INTO a class that has ended, whose cutoff would then
     * be applied to an amount it never governed. A class that ends today has not ended.
     *
     * @param  string  $today  'Y-m-d' on the school's clock: the day the move is RUN, never the day typed
     * @return self::CARRY_*
     */
    public static function carryRule(Group $from, Group $to, string $today): string
    {
        if ($from->ends_on !== null && $from->ends_on->toDateString() < $today) {
            return self::CARRY_FROM_ENDED;
        }

        if ($to->ends_on !== null && $to->ends_on->toDateString() < $today) {
            return self::CARRY_TO_ENDED;
        }

        return self::CARRY_MOVE;
    }

    /**
     * Whether `migrate` has added `prize_ledger_entries.counts_from` yet.
     *
     * bin/deploy makes the new code live BEFORE it runs `php artisan migrate`. For that window
     * the hourly expiry must not name the column in its sum, and a move must not reach
     * `carryBalance`. Memoised per process as App\Support\StudentAge is: a column that exists is
     * remembered for good, one that is missing is asked again after CARRY_RECHECK_SECONDS, and a
     * question that could not be answered is "not yet" for that call only.
     */
    public static function carryReady(): bool
    {
        $now = now()->getTimestamp();
        $seen = self::$carryColumnSeen;

        if ($seen !== null && ($seen[0] || $now - $seen[1] < self::CARRY_RECHECK_SECONDS)) {
            return $seen[0];
        }

        try {
            $exists = Schema::hasColumn('prize_ledger_entries', 'counts_from');
        } catch (Throwable) {
            return false;
        }

        self::$carryColumnSeen = [$exists, $now];

        return $exists;
    }

    /** Forget what carryReady() saw: for a test that drops or adds the column, and for nothing else. */
    public static function forgetCarryReady(): void
    {
        self::$carryColumnSeen = null;
    }

    /**
     * Does this organisation hold any ledger row at all? One EXISTS. A fact about the SCHOOL,
     * whatever its switch says today, and never about a child: it is what the move asks so that
     * nothing the office reads about one student's place depends on that student's own ledger.
     */
    public static function schoolHoldsRows(int $masjidId): bool
    {
        return DB::table('prize_ledger_entries')->where('masjid_id', $masjidId)->exists();
    }

    // ----------------------------------------------------------------- writes

    /**
     * A teacher redeems one prize for one student of their class.
     *
     * @return array{entry:PrizeLedgerEntry,replayed:bool}
     *
     * @throws ClassStoreRefusal
     */
    public static function redeem(
        Group $group,
        GroupMembership $membership,
        Prize $prize,
        ?User $by,
        ?string $requestId = null,
        ?string $note = null,
    ): array {
        $key = $requestId !== null ? self::keyFor('redeemed', (int) $membership->id, $requestId) : null;
        $same = fn (PrizeLedgerEntry $e): bool => $e->prize_id !== null && (int) $e->prize_id === (int) $prize->getKey();

        return self::guarded($key, $same, function () use ($group, $membership, $prize, $by, $key, $note, $same): array {
            $student = self::lockStudent($group, $membership);

            if ($key !== null && ($existing = self::existing($key)) !== null) {
                return self::replay($existing, $same);
            }

            // Locked with the student: two classes racing for the last one are serialised too.
            $locked = Prize::query()->whereKey($prize->getKey())->lockForUpdate()->first();

            if ($locked === null || (int) $locked->masjid_id !== (int) $group->masjid_id) {
                throw new ClassStoreRefusal('prize_unknown', 'That id names no prize in this school.');
            }

            if ($locked->group_id !== null && (int) $locked->group_id !== (int) $group->id) {
                throw new ClassStoreRefusal('prize_other_class', 'That prize belongs to another class, so it cannot be given here.');
            }

            if (! $locked->is_active) {
                throw new ClassStoreRefusal('prize_retired', 'That prize has been retired.');
            }

            if (! $locked->inStock()) {
                throw new ClassStoreRefusal('out_of_stock', 'That prize is out of stock.');
            }

            $cost = (int) $locked->cost_bucks;
            $balance = self::rawBalance((int) $student->id);

            if ($balance < $cost) {
                throw new ClassStoreRefusal('not_enough_bucks', 'That student does not have enough Manara Bucks for this prize.');
            }

            $entry = self::insert($group, $student, PrizeLedgerEntry::KIND_REDEEMED, -$cost, [
                'prize_id' => $locked->id,
                'prize_title' => $locked->title,
                'prize_cost' => $cost,
                'note' => $note,
                'created_by_user_id' => $by?->id,
                'dedupe_key' => $key,
            ]);

            if ($locked->stock !== null) {
                $taken = DB::table('prizes')->where('id', $locked->id)->where('stock', '>', 0)->decrement('stock');

                if ($taken !== 1) {
                    throw new ClassStoreRefusal('out_of_stock', 'That prize is out of stock.');
                }
            }

            self::assertNotNegative((int) $student->id);

            return ['entry' => $entry, 'replayed' => false];
        });
    }

    /**
     * A teacher corrects a redemption or a cash-out: a NEW row that gives the bucks back.
     *
     * @return array{entry:PrizeLedgerEntry,replayed:bool}
     *
     * @throws ClassStoreRefusal
     */
    public static function reverse(Group $group, PrizeLedgerEntry $entry, ?User $by, ?string $note = null): array
    {
        $key = 'reversal:'.$entry->id;

        return self::guarded($key, null, function () use ($group, $entry, $by, $key, $note): array {
            // The roster row of the student the entry belongs to, locked before anything is read.
            $student = GroupMembership::query()->whereKey($entry->group_membership_id)->lockForUpdate()->first();

            if ($student === null || (int) $entry->group_id !== (int) $group->id || (int) $student->group_id !== (int) $group->id) {
                throw new ClassStoreRefusal('entry_unknown', 'That entry is not in this class.', 404);
            }

            if (($existing = self::existing($key)) !== null) {
                return ['entry' => $existing, 'replayed' => true];
            }

            if (! in_array($entry->kind, PrizeLedgerEntry::REVERSIBLE_KINDS, true)) {
                throw new ClassStoreRefusal('not_reversible', 'Only a prize given or Bucks paid out can be reversed.');
            }

            // An expiry closes the student's balance for good: giving bucks back after it would
            // hand a child bucks their class no longer has. An expiry that was itself given back
            // (its end date was corrected, BucksExpiry) no longer closes anything.
            $expiredSince = PrizeLedgerEntry::query()
                ->where('group_membership_id', $student->id)
                ->where('kind', PrizeLedgerEntry::KIND_EXPIRED)
                ->where('id', '>', $entry->id)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('prize_ledger_entries as undo')
                        ->whereColumn('undo.reverses_entry_id', 'prize_ledger_entries.id');
                })
                ->exists();

            if ($expiredSince) {
                throw new ClassStoreRefusal('expired', 'That balance has expired, so this entry can no longer be reversed.');
            }

            // A move closes it too, in two ways. While the student is away after a move, giving
            // Bucks back would put them on a row nothing can be spent from, with the prize back on
            // the shelf: decided from the row's own state, so a move that carried nothing (a child
            // who had spent everything) is covered as well. And once a balance has left this row
            // in a transfer, an entry older than that transfer stays closed even after the
            // student comes back to the row.
            $movedSince = self::carriedAway($student) || PrizeLedgerEntry::query()
                ->where('group_membership_id', $student->id)
                ->where('kind', PrizeLedgerEntry::KIND_TRANSFER_OUT)
                ->where('id', '>', $entry->id)
                ->exists();

            if ($movedSince) {
                throw new ClassStoreRefusal('moved_away', 'That student was moved to another class after this was recorded, so it can no longer be undone here.');
            }

            $reversal = self::insert($group, $student, PrizeLedgerEntry::KIND_REVERSAL, -((int) $entry->amount), [
                'prize_id' => $entry->prize_id,
                'prize_title' => $entry->prize_title,
                'prize_cost' => $entry->prize_cost,
                'reverses_entry_id' => $entry->id,
                'breakdown' => $entry->breakdown,
                'note' => $note,
                'created_by_user_id' => $by?->id,
                'dedupe_key' => $key,
            ]);

            if ($entry->kind === PrizeLedgerEntry::KIND_REDEEMED && $entry->prize_id !== null) {
                // Back on the shelf, and only where stock is counted at all.
                DB::table('prizes')->where('id', $entry->prize_id)->whereNotNull('stock')->increment('stock');
            }

            return ['entry' => $reversal, 'replayed' => false];
        });
    }

    /**
     * A teacher pays bucks out as paper notes. BUILT AND OFF: refused while the school's
     * `paper_bucks_enabled` is false.
     *
     * @return array{entry:PrizeLedgerEntry,replayed:bool}
     *
     * @throws ClassStoreRefusal
     */
    public static function cashOut(
        Group $group,
        GroupMembership $membership,
        int $amount,
        ?User $by,
        ?string $requestId = null,
        ?string $note = null,
    ): array {
        if (! ClassStoreSettings::paperEnabled((int) $group->masjid_id)) {
            throw new ClassStoreRefusal('paper_bucks_off', 'Paper Manara Bucks are not switched on for this school.', 403);
        }

        if ($amount < 1) {
            throw new ClassStoreRefusal('bad_amount', 'A cash-out is at least one buck.');
        }

        $key = $requestId !== null ? self::keyFor('cashedout', (int) $membership->id, $requestId) : null;
        $same = fn (PrizeLedgerEntry $e): bool => (int) $e->amount === -$amount;

        return self::guarded($key, $same, function () use ($group, $membership, $amount, $by, $key, $note, $same): array {
            $student = self::lockStudent($group, $membership);

            if ($key !== null && ($existing = self::existing($key)) !== null) {
                return self::replay($existing, $same);
            }

            if (self::rawBalance((int) $student->id) < $amount) {
                throw new ClassStoreRefusal('not_enough_bucks', 'That student does not have that many Manara Bucks.');
            }

            $entry = self::insert($group, $student, PrizeLedgerEntry::KIND_CASHED_OUT, -$amount, [
                'breakdown' => self::breakdown($amount),
                'note' => $note,
                'created_by_user_id' => $by?->id,
                'dedupe_key' => $key,
            ]);

            self::assertNotNegative((int) $student->id);

            return ['entry' => $entry, 'replayed' => false];
        });
    }

    /**
     * Edit a prize: the row is LOCKED (the same lock a redemption takes on it) and a new
     * `stock` is written only if the stock is still what the editor loaded (`$expectedStock`),
     * so a count typed on a screen opened before a redemption can never put the given prize
     * back on the shelf. A mismatch is a 409 `stock_changed` and writes nothing. `$scope` says
     * whose shelf (a class's own, or the school-wide list); a prize outside it is a 404.
     *
     * @param  Builder<Prize>  $scope
     * @param  array<string,mixed>  $changes  SavePrizeRequest::changes()
     *
     * @throws ClassStoreRefusal
     */
    public static function updatePrize(Builder $scope, int|string $prizeId, array $changes, ?int $expectedStock): Prize
    {
        return DB::transaction(function () use ($scope, $prizeId, $changes, $expectedStock): Prize {
            $prize = $scope->clone()->whereKey($prizeId)->lockForUpdate()->firstOrFail();

            if (array_key_exists('stock', $changes)) {
                $current = $prize->stock === null ? null : (int) $prize->stock;

                if ($current !== $expectedStock) {
                    throw new ClassStoreRefusal(
                        'stock_changed',
                        'The number left changed to '.($current === null ? 'no limit' : $current)
                            .' while this was being edited (a prize was given or given back). Nothing was saved; check the number and save again.',
                        409,
                    );
                }
            }

            foreach ($changes as $field => $value) {
                $prize->{$field} = $value;
            }

            $prize->save();

            return $prize->fresh();
        });
    }

    /**
     * Append one row for the system (weekly minting and expiry) inside the student's lock.
     * Idempotent on `dedupe_key`: a key that already exists writes nothing and returns null.
     *
     * `$decide` receives the student's CURRENT balance (read under the lock) and returns the
     * attributes of the row to write, or null to write nothing. So a clawback can be clamped
     * to what the child still holds without a second, unlocked read.
     *
     * NOTHING IS MINTED ON A ROW THAT WAS CARRIED AWAY. The mint lists a class's current
     * students BEFORE it takes a row's lock, so a run that listed a child and then waited behind
     * their move would otherwise write `earned` on the row they have just left, after its balance
     * went to the other class. Decided here, under the lock, from the row's own state and for
     * both minted kinds; an `expired` row and an expiry given back are still written there.
     *
     * @param  callable(int):?array{kind:string,amount:int,dedupe_key:string}  $decide
     */
    public static function appendForSystem(Group $group, int $membershipId, callable $decide): ?PrizeLedgerEntry
    {
        try {
            return DB::transaction(function () use ($group, $membershipId, $decide): ?PrizeLedgerEntry {
                $student = GroupMembership::query()->whereKey($membershipId)->lockForUpdate()->first();

                if ($student === null || (int) $student->group_id !== (int) $group->id) {
                    return null;
                }

                $attributes = $decide(self::rawBalance((int) $student->id));

                if ($attributes === null) {
                    return null;
                }

                if (in_array($attributes['kind'], PrizeLedgerEntry::MINTED_KINDS, true) && self::carriedAway($student)) {
                    return null;
                }

                $key = $attributes['dedupe_key'];

                if (self::existing($key) !== null) {
                    return null;
                }

                $kind = $attributes['kind'];
                $amount = $attributes['amount'];
                unset($attributes['kind'], $attributes['amount']);

                $entry = self::insert($group, $student, $kind, $amount, $attributes);

                self::assertNotNegative((int) $student->id);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // Another run wrote the same fact between our check and our insert: it is done.
            return null;
        } catch (ClassStoreRefusal) {
            return null;
        }
    }

    /**
     * TO BE CALLED ONLY BY RosterMove::write(), inside the move's transaction, with BOTH roster
     * rows already held by that transaction. NOTHING CALLS IT YET: the commit that adds that one
     * call also widens the move's guard for the deploy window (`RosterMove::ready()`) to this
     * class's column check (`carryReady()`), which today guards expiry's read only. It takes no
     * lock of its own. Writes the pair for the old
     * row's whole balance, or nothing. Returns nothing: no caller can learn whether a pair was
     * written, so no caller can print it.
     *
     * Nothing is written when the two classes' dates say Bucks do not move (carryRule), or when
     * the old row holds nothing or owes (a zero or negative balance stays where it is). It is
     * written whatever the school's `class_store` switch says: a school that switched the store
     * off still holds rows.
     *
     * NOT through appendForSystem() or guarded(): both swallow a refusal or a duplicate key and
     * answer null or a replay, and through them a move could commit with half a pair. NOT
     * through lockStudent(), which refuses a row that has left and locks what the move already
     * holds. Everything thrown here leaves the move's transaction and rolls the whole move back.
     * A fault is a LogicException, never a refusal: the move answers a unique violation as "this
     * roster changed, try again", which is right for the roster's own index and a loop for a
     * ledger key that is wrong by construction, so a duplicate key is rethrown as a fault here.
     *
     * @param  string  $today  'Y-m-d' on the school's clock: the day the move is RUN
     *
     * @throws LogicException
     */
    public static function carryBalance(Group $from, GroupMembership $old, Group $to, GroupMembership $new, ?User $by, string $today): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A balance is carried inside the move\'s transaction, with both roster rows held: no transaction is open.');
        }

        if ((int) $old->id === (int) $new->id) {
            throw new LogicException('A balance is carried from one roster row to ANOTHER: the same row was given twice.');
        }

        foreach ([$old, $new] as $row) {
            if (! in_array($row->role, GroupMembership::PARTICIPANT_ROLES, true)) {
                throw new LogicException("Roster row {$row->id} is not a student's own place, so it holds no balance to carry.");
            }
        }

        if ((int) $old->contact_id !== (int) $new->contact_id) {
            throw new LogicException("Roster rows {$old->id} and {$new->id} are two different people's: a balance never changes hands.");
        }

        if ((int) $old->masjid_id !== (int) $new->masjid_id
            || (int) $from->masjid_id !== (int) $old->masjid_id
            || (int) $to->masjid_id !== (int) $new->masjid_id) {
            throw new LogicException("Roster rows {$old->id} and {$new->id} are not in one organisation: a balance never leaves its school.");
        }

        if ((int) $old->group_id !== (int) $from->id || (int) $new->group_id !== (int) $to->id) {
            throw new LogicException("Roster rows {$old->id} and {$new->id} are not the places held in the class left and the class entered.");
        }

        if (self::carryRule($from, $to, $today) !== self::CARRY_MOVE) {
            return;
        }

        // Exact: every ledger writer takes the roster row's lock first, and the move holds it.
        $balance = self::rawBalance((int) $old->id);

        if ($balance < 1) {
            return;
        }

        // Counted by kind, never with LIKE on the key (its underscore is a wildcard).
        $n = DB::table('prize_ledger_entries')
            ->where('group_membership_id', $old->id)
            ->where('kind', PrizeLedgerEntry::KIND_TRANSFER_OUT)
            ->count() + 1;

        $countsFrom = self::countsFrom((int) $old->id, $today);

        // What the new row reads before the pair. Nothing is DECIDED from it (on a return it was
        // read before that row's lock): it is only what the check below compares the row with.
        $before = self::rawBalance((int) $new->id);

        try {
            $out = self::insert($from, $old, PrizeLedgerEntry::KIND_TRANSFER_OUT, -$balance, [
                'created_by_user_id' => $by?->id,
                'dedupe_key' => 'transfer_out:'.$old->id.':'.$n,
                'counts_from' => $countsFrom,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Not chained: the database's own message carries the insert's values, the amount among them.
            throw new LogicException("The ledger already holds transfer_out:{$old->id}:{$n}, a key only this write makes.");
        }

        try {
            self::insert($to, $new, PrizeLedgerEntry::KIND_TRANSFER_IN, $balance, [
                'created_by_user_id' => $by?->id,
                'dedupe_key' => 'transfer_in:'.$out->id,
                'counts_from' => $countsFrom,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new LogicException("The ledger already holds transfer_in:{$out->id}, a key only this write makes.");
        }

        // A check of THIS METHOD'S OWN ARITHMETIC, not a race guard: both sums are this
        // transaction's snapshot plus its own two rows, so they can catch a mistake here and
        // nothing another transaction did. It does not ask whether the new row is below zero: a
        // re-opened row that owes for an older reason of its own is not this write's fault.
        if (self::rawBalance((int) $old->id) !== 0 || self::rawBalance((int) $new->id) !== $before + $balance) {
            throw new LogicException("The pair written for roster rows {$old->id} and {$new->id} does not add up: nothing was carried.");
        }

        // AFTER the commit, so a move that was retried or rolled back says nothing. At WARNING
        // because production drops info lines. Ids only: no amount and no count.
        $line = ['membership' => (int) $old->id, 'new_membership' => (int) $new->id, 'by' => $by?->id];

        DB::afterCommit(static function () use ($line): void {
            Log::warning('class_store.carried', $line);
        });
    }

    // -------------------------------------------------------------- internals

    /**
     * Run a write in a transaction. A repeated `dedupe_key` that loses the race on the
     * unique index becomes a replay of the row that won, not an error (and, like any replay,
     * only if it asked for the same thing: `$same`).
     *
     * @param  (callable(PrizeLedgerEntry):bool)|null  $same
     * @param  callable():array{entry:PrizeLedgerEntry,replayed:bool}  $work
     * @return array{entry:PrizeLedgerEntry,replayed:bool}
     *
     * @throws ClassStoreRefusal
     */
    private static function guarded(?string $key, ?callable $same, callable $work): array
    {
        try {
            return DB::transaction($work);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $key !== null ? self::existing($key) : null;

            if ($existing === null) {
                throw $e;
            }

            return self::replay($existing, $same);
        }
    }

    /**
     * The answer to a request id already used: the row it wrote, when this request asks for the
     * same thing, and a 409 otherwise (the screen reused an id for a different write, which must
     * not come back as if the second write had been done).
     *
     * @param  (callable(PrizeLedgerEntry):bool)|null  $same
     * @return array{entry:PrizeLedgerEntry,replayed:bool}
     *
     * @throws ClassStoreRefusal
     */
    private static function replay(PrizeLedgerEntry $existing, ?callable $same): array
    {
        if ($same !== null && ! $same($existing)) {
            throw new ClassStoreRefusal(
                'request_id_reused',
                'That request id was already used for a different prize or amount. Nothing new was written; try again.',
                409,
            );
        }

        return ['entry' => $existing, 'replayed' => true];
    }

    /**
     * The student's roster row, locked, and only if they are a CURRENT participant of THIS class.
     *
     * A child who has LEFT the class gets their own refusal, saying why: nothing can be spent
     * for them from this class any more. The sentence does not say where their Bucks are, because
     * that depends on how they left. A move to another class takes the balance with it
     * (carryBalance); a move out of a class that had already ended, and a student simply
     * recorded as left, leave it on this row (the withdrawal half of owner question W6-C1 is not
     * decided).
     */
    private static function lockStudent(Group $group, GroupMembership $membership): GroupMembership
    {
        $student = GroupMembership::query()->whereKey($membership->getKey())->lockForUpdate()->first();

        if (
            $student === null
            || (int) $student->group_id !== (int) $group->id
            || ! in_array($student->role, GroupMembership::PARTICIPANT_ROLES, true)
        ) {
            throw new ClassStoreRefusal('not_a_student', 'That id names no current student of this class.');
        }

        if ($student->hasLeft()) {
            throw new ClassStoreRefusal(
                'student_left',
                'That student has left this class, so nothing can be spent for them here.',
            );
        }

        return $student;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private static function insert(Group $group, GroupMembership $student, string $kind, int $amount, array $attributes): PrizeLedgerEntry
    {
        return PrizeLedgerEntry::create([
            // Explicit: the minting and expiry commands run with no tenant bound, where the
            // creating hook stamps nothing. A bound request's hook overrides it with the same value.
            'masjid_id' => $group->masjid_id,
            'group_id' => $group->id,
            'group_membership_id' => $student->id,
            'kind' => $kind,
            'amount' => $amount,
            'occurred_at' => now(),
        ] + $attributes);
    }

    /**
     * Was this roster row left by a MOVE? Read from the row alone: a leaving date AND a "moved
     * to". That is exactly what a move leaves on the old row, whether or not a pair was written.
     * A row the student returned to by a move has neither, and a row put back by hand has no
     * leaving date, so both are current again and earn again. No ledger row is consulted: a test
     * on "the newest transfer row" would stop a child put back after a mistaken move from ever
     * earning in that class again, and only if they had held Bucks.
     */
    private static function carriedAway(GroupMembership $row): bool
    {
        return $row->left_on !== null && $row->moved_to_group_id !== null;
    }

    /**
     * The day a carried amount counts as minted on: the newest week the old row was minted for
     * (a positive `earned` or `adjusted` row), or the newest date an earlier transfer brought
     * onto it, whichever is later; never after `$today`; null when the row has neither. Read
     * under the old row's lock, and written on BOTH rows of the pair.
     *
     * It is what lets a cutoff older than the move leave a carried balance alone (the old row
     * held something minted since that cutoff, so the date is on or after it) and lets a cutoff
     * after the move take it (the date is never after the day the move ran). It travels down a
     * chain of moves: on the next move the new row is the old row, and its own `transfer_in`
     * brings the date along.
     */
    private static function countsFrom(int $oldRowId, string $today): ?string
    {
        $minted = DB::table('prize_ledger_entries')
            ->where('group_membership_id', $oldRowId)
            ->whereIn('kind', PrizeLedgerEntry::MINTED_KINDS)
            ->where('amount', '>', 0)
            ->max('week_start');

        $brought = DB::table('prize_ledger_entries')
            ->where('group_membership_id', $oldRowId)
            ->where('kind', PrizeLedgerEntry::KIND_TRANSFER_IN)
            ->max('counts_from');

        $days = [];

        foreach ([$minted, $brought] as $day) {
            if ($day !== null) {
                $days[] = substr((string) $day, 0, 10);
            }
        }

        return $days === [] ? null : min(max($days), $today);
    }

    private static function existing(string $key): ?PrizeLedgerEntry
    {
        return PrizeLedgerEntry::query()->where('dedupe_key', $key)->first();
    }

    private static function keyFor(string $kind, int $membershipId, string $requestId): string
    {
        return $kind.':'.$membershipId.':'.$requestId;
    }

    /** The invariant, checked AFTER the write, so an engine with no row locks still holds it. */
    private static function assertNotNegative(int $membershipId): void
    {
        if (self::rawBalance($membershipId) < 0) {
            throw new ClassStoreRefusal('balance_changed', 'That student\'s balance changed while this was being saved. Nothing was taken; try again.', 409);
        }
    }

    /**
     * The student's balance as the ledger says it, for use INSIDE a write that has locked the
     * roster row. Never returned to a client: every balance a person reads goes through
     * GroupAudience::readablePrizeLedgerQuery() and balances().
     */
    private static function rawBalance(int $membershipId): int
    {
        return (int) DB::table('prize_ledger_entries')->where('group_membership_id', $membershipId)->sum('amount');
    }
}
