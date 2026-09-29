<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One line of the Manara Bucks ledger (T-003.4, W6). APPEND-ONLY.
 *
 * A child's balance is the SUM of `amount` over their roster row's entries, never a stored
 * figure. A wrong entry is corrected by a NEW `reversal` row that points at it; nothing is
 * updated and nothing is deleted:
 *
 *   - `updating` and `deleting` THROW, so no controller, command or future caller can edit
 *     or remove a row by accident (there is also no route);
 *   - the two sanctioned removals go around the model on purpose, and remove a child's
 *     WHOLE ledger together: the database cascade when a class or school is deleted, and
 *     `purgeDueSets()` when every row of a child's ledger has passed its retention date.
 *     Removing rows one by one would leave a balance the remaining rows do not explain.
 *
 * Who may READ a balance is App\Support\GroupAudience (`readablePrizeLedgerQuery()`, the
 * same audience as an award: the class's teachers, the student, that student's own
 * guardians, and nobody else). There is no rank, no class-wide comparison and no prize
 * wall: the only class-wide read is the teacher's roster-order overview.
 *
 * Tenant-scoped (BelongsToMasjid); cross-tenant test in tests/Feature/ClassStoreTenantIsolationTest.php.
 */
class PrizeLedgerEntry extends Model
{
    use BelongsToMasjid;

    public const KIND_EARNED = 'earned';
    public const KIND_ADJUSTED = 'adjusted';
    public const KIND_REDEEMED = 'redeemed';
    public const KIND_REVERSAL = 'reversal';
    public const KIND_CASHED_OUT = 'cashed_out';
    public const KIND_EXPIRED = 'expired';

    /** PHP constants, never a database enum (.claude/rules/migrations.md). */
    public const KINDS = [
        self::KIND_EARNED,
        self::KIND_ADJUSTED,
        self::KIND_REDEEMED,
        self::KIND_REVERSAL,
        self::KIND_CASHED_OUT,
        self::KIND_EXPIRED,
    ];

    /** The kinds a reversal may undo: something a child spent, never something they earned. */
    public const REVERSIBLE_KINDS = [self::KIND_REDEEMED, self::KIND_CASHED_OUT];

    /** The kinds that put bucks in ("minted from points"). */
    public const MINTED_KINDS = [self::KIND_EARNED, self::KIND_ADJUSTED];

    /** Paper notes, largest first: the cash-out breakdown (owner: the 20/10/5/1 set). */
    public const NOTES = [20, 10, 5, 1];

    /** Nothing is ever updated, so there is no updated_at either. */
    public $timestamps = false;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'group_membership_id',
        'kind',
        'amount',
        'week_start',
        'week_basis',
        'prize_id',
        'prize_title',
        'prize_cost',
        'reverses_entry_id',
        'breakdown',
        'note',
        'created_by_user_id',
        'dedupe_key',
        'occurred_at',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'week_start' => 'date',
            'week_basis' => 'integer',
            'prize_cost' => 'integer',
            'breakdown' => 'array',
            'occurred_at' => 'datetime',
            'retained_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if ($entry->occurred_at === null) {
                $entry->occurred_at = now();
            }

            // RETENTION IS A DEFAULT, NOT A HOPE (the same stance as an award): a record
            // about a child is not kept forever unless somebody says so. The sweep removes a
            // child's rows only when ALL of them are due (purgeDueSets), and every new row is
            // stamped from ITS OWN date, so a child's newest row sets when the whole set goes.
            if ($entry->retained_until === null) {
                $days = (int) config('groups.bucks.retention_days', 0);

                if ($days > 0) {
                    $entry->retained_until = $entry->occurred_at->copy()->addDays($days)->toDateString();
                }
            }
        });

        static::updating(function (self $entry): void {
            throw new LogicException('The Manara Bucks ledger is append-only: write a reversal, never an edit.');
        });

        static::deleting(function (self $entry): void {
            throw new LogicException('The Manara Bucks ledger is append-only: write a reversal, never a delete.');
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** THE STUDENT: their own participant membership. Every audience rule is decided from this row. */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The entry this one corrects (a reversal), when it still exists. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * Remove every child's ledger whose LAST row has passed its retention date, as a SET.
     *
     * A child is due only when EVERY one of their rows has a `retained_until` on or before
     * `$before` (a NULL never is: nobody has set a window). So the sweep cannot take the rows
     * that earned the bucks and leave the redemption that spent them, or the reverse: either
     * would leave a negative or an unexplained balance. The rows go through the query
     * builder on purpose, in one statement per school: the model refuses a single-row delete.
     *
     * @return int how many rows were removed
     */
    public static function purgeDueSets(string $before, ?int $masjidId = null, bool $dryRun = false): int
    {
        $dueMemberships = function (?array $among = null) use ($before, $masjidId) {
            return DB::table('prize_ledger_entries as e')
                ->when($masjidId !== null, fn ($q) => $q->where('e.masjid_id', $masjidId))
                ->when($among !== null, fn ($q) => $q->whereIn('e.group_membership_id', $among))
                ->whereNotExists(function ($q) use ($before) {
                    $q->select(DB::raw(1))
                        ->from('prize_ledger_entries as later')
                        ->whereColumn('later.group_membership_id', 'e.group_membership_id')
                        ->where(function ($w) use ($before) {
                            $w->whereNull('later.retained_until')->orWhereDate('later.retained_until', '>', $before);
                        });
                })
                ->distinct();
        };

        if ($dryRun) {
            return (int) DB::table('prize_ledger_entries')
                ->whereIn('group_membership_id', $dueMemberships()->pluck('e.group_membership_id')->all())
                ->count();
        }

        $removed = 0;

        foreach (array_chunk($dueMemberships()->pluck('e.group_membership_id')->all(), 200) as $chunk) {
            // Re-decided INSIDE a transaction that holds the roster rows, the same rows a
            // redemption locks: a row appended after the list above was read (a child
            // redeems in the last second of their retention) makes that child not due
            // any more, and their whole set stays. MySQL will not delete from a table its
            // own subquery reads, so it is decided first and deleted by id after.
            $removed += DB::transaction(function () use ($chunk, $dueMemberships): int {
                DB::table('group_memberships')->whereIn('id', $chunk)->lockForUpdate()->pluck('id');

                $still = $dueMemberships($chunk)->pluck('e.group_membership_id')->all();

                return $still === []
                    ? 0
                    : DB::table('prize_ledger_entries')->whereIn('group_membership_id', $still)->delete();
            });
        }

        return $removed;
    }

    /** Scope: only entries of these kinds. */
    public function scopeOfKind(Builder $query, string ...$kinds): Builder
    {
        return $query->whereIn('kind', $kinds);
    }
}
