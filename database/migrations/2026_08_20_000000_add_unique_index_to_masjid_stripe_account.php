<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One Stripe Connect account belongs to one LIVE organisation.
 *
 * ## What the missing constraint cost
 *
 * `RegistrationPaymentService::resolve()` routes an incoming webhook by
 * `Masjid::where('stripe_account_id', $account)->first()`. The column was a
 * plain nullable string with no index and no uniqueness, so `first()` silently
 * picked the lowest id. With two LIVE organisations on one account id — an
 * operator pasting the wrong value, an import, a test account reused — the
 * event resolved against the WRONG organisation, `findByUuidForMasjid()`
 * missed, and the handler returned. Measured:
 *
 *     checkout 200 (live Stripe URL)
 *     webhook  http=200  status=pending  pay=awaiting  payments=0  seat=1
 *     +46 min  status=cancelled  pay=canceled  seat=0
 *
 * The family was charged, Stripe got its 200 and never retried, no
 * `registration_payments` row was written, and the reaper released the seat she
 * had paid for. There was no log line on that branch either, so the first
 * anybody heard of it was the family (that half is fixed in `resolve()`).
 *
 * ## Why the index is on LIVE rows only
 *
 * A soft-deleted organisation legitimately keeps its `stripe_account_id`, and
 * `resolve()` deliberately falls back to `onlyTrashed()` so that money arriving
 * for an offboarded organisation is still RECORDED rather than dropped — the
 * money moved, and whoever has to refund it needs the row. An offboarded
 * organisation that later re-onboards on the same account is therefore an
 * ordinary state, and a blanket unique index would refuse it. What must never
 * happen is TWO LIVE rows on one account, because that is the only case where
 * the question "whose event is this" has no answer.
 *
 * This is the same shape, and the same two-driver implementation, as
 * `masjids_active_owner_unique` (add_owner_uniqueness_to_masjids_table):
 * MySQL gets a VIRTUAL generated column carrying the value only while the row
 * is live, SQLite gets a partial index. Kept deliberately parallel so there is
 * one pattern to understand rather than two.
 *
 * ## Why not a check in the resolver
 *
 * By the time an event arrives the ambiguity already exists, and nothing in the
 * payload says which organisation was meant — Stripe's `account` is exactly the
 * value that is duplicated. Refusing there loses the money quietly; guessing
 * assigns a stranger's payment. The only place the question has an answer is
 * the write.
 *
 * Verified read-only against production before writing this: 4 organisations,
 * 2 distinct Connect accounts, one row each. Additive.
 */
return new class extends Migration
{
    /** Name of the unique index on both drivers. */
    private const INDEX = 'masjids_active_stripe_account_unique';

    /** MySQL/MariaDB-only shadow column; see the class docblock. */
    private const COLUMN = 'active_stripe_account_id';

    public function up(): void
    {
        $this->refuseToRunWithDuplicateAccounts();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE masjids ADD COLUMN ' . self::COLUMN . ' VARCHAR(255)'
                . ' GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN stripe_account_id ELSE NULL END)'
                . ' VIRTUAL'
            );

            DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (' . self::COLUMN . ')');

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (stripe_account_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP INDEX ' . self::INDEX . ' ON masjids');
            DB::statement('ALTER TABLE masjids DROP COLUMN ' . self::COLUMN);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
    }

    /**
     * Fail loudly, before any DDL, if the data would violate the new index.
     *
     * The raw constraint violation names a column and an index and leaves the
     * operator to work out that two organisations are fighting over one Stripe
     * account. This names the accounts. Which organisation owns one is a money
     * question a human has to answer, and a migration is the wrong place to
     * answer it unattended.
     */
    private function refuseToRunWithDuplicateAccounts(): void
    {
        $duplicates = DB::table('masjids')
            ->select('stripe_account_id')
            ->whereNull('deleted_at')
            ->whereNotNull('stripe_account_id')
            ->where('stripe_account_id', '!=', '')
            ->groupBy('stripe_account_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('stripe_account_id')
            ->all();

        if ($duplicates === []) {
            return;
        }

        throw new RuntimeException(
            'Two live organisations share a Stripe Connect account id, so an incoming webhook cannot be '
            . 'routed to either of them: ' . implode(', ', $duplicates) . '. Clear the wrong one — the '
            . 'organisation that does not own the account — before migrating.'
        );
    }
};
