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

/**
 * Every WRITE to the Manara Bucks ledger that a person makes, in one place (T-003.4).
 *
 * Redeem, reverse, cash out, and the internal `append()` that minting and expiry share.
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
     * A child who has LEFT the class gets their own refusal, saying why (owner question W6-C1):
     * their Bucks stay on their record here, and what happens to them on a move or a withdrawal
     * is not decided yet, so nothing can be spent from this class meanwhile.
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
                'That student has left this class. Their Manara Bucks stay on their record here, but they cannot be spent in this class.',
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
