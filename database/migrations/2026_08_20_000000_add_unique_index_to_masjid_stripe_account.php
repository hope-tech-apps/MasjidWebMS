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
 * ## Why the index is on LIVE, ONBOARDED rows only
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
 * The EMPTY STRING is "not onboarded", exactly like NULL, and is excluded for
 * the same reason. It is not a Stripe account id and it can never be routed to:
 * `RegistrationPaymentService::resolve()` returns null on
 * `$account === null || $account === ''` before it looks anything up, and
 * `StripeConnectService::syncAccountStatus()` returns null on a falsy account
 * id. So two live rows carrying `''` are not two organisations fighting over one
 * account — they are two organisations that have not onboarded, which is the
 * ordinary state of a freshly provisioned tenant. The parallel owner index says
 * the same thing about NULL: "not an owner, not a value that can collide".
 *
 * This mattered because the pre-flight guard below ALREADY excluded `''` while
 * the index did not, so the one duplicate class the guard exists to catch
 * politely was the one it let through into raw DDL. Measured on this branch, by
 * seeding each state and calling `up()`:
 *
 *     two live rows on the SAME real account id -> REFUSED politely
 *     two live rows on the EMPTY STRING         -> SQLSTATE[23000]: UNIQUE
 *                                                  constraint failed:
 *                                                  masjids.stripe_account_id
 *     two live rows on NULL                     -> up() SUCCEEDED
 *
 * On MySQL the generated column carried `''` through unchanged and failed the
 * same way: a production deploy aborting half-migrated with an opaque error. The
 * two predicates are now written from ONE place — `LIVE_ONBOARDED_SQL` below is
 * the index's WHERE clause, the generated column's CASE, and the guard's filter
 * — so they cannot drift apart again. MasjidStripeAccountUniquenessTest drives
 * all three states through `up()` and asserts the guard refuses exactly what the
 * index refuses.
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

    /**
     * THE PREDICATE, written once.
     *
     * A row is subject to uniqueness only while it is LIVE and ONBOARDED. This
     * string is the SQLite partial index's WHERE clause, the MySQL generated
     * column's CASE condition, and — through `refuseToRunWithDuplicateAccounts()`
     * — the pre-flight's filter. When those three disagree the migration aborts
     * mid-deploy on a state its own guard claims to catch; see the class
     * docblock for the measurement.
     *
     * NULL is excluded by the `<> ''` comparison itself (NULL <> '' is NULL, and
     * neither a partial index nor a CASE takes an unknown as true), which is the
     * behaviour both drivers already wanted: no account is no account.
     */
    private const LIVE_ONBOARDED_SQL = "deleted_at IS NULL AND stripe_account_id <> ''";

    public function up(): void
    {
        $this->refuseToRunWithDuplicateAccounts();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE masjids ADD COLUMN ' . self::COLUMN . ' VARCHAR(255)'
                . ' GENERATED ALWAYS AS (CASE WHEN ' . self::LIVE_ONBOARDED_SQL
                . ' THEN stripe_account_id ELSE NULL END)'
                . ' VIRTUAL'
            );

            DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (' . self::COLUMN . ')');

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (stripe_account_id)'
            . ' WHERE ' . self::LIVE_ONBOARDED_SQL
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
     *
     * The filter is `LIVE_ONBOARDED_SQL` verbatim — the index's own predicate —
     * because a pre-flight that scans a NARROWER set than the constraint it
     * precedes is worse than no pre-flight at all: it reports the database clean
     * and then hands the operator the raw error anyway, half-migrated. That is
     * exactly what the `!= ''` written here by hand used to do.
     */
    private function refuseToRunWithDuplicateAccounts(): void
    {
        $duplicates = DB::table('masjids')
            ->select('stripe_account_id')
            ->whereRaw(self::LIVE_ONBOARDED_SQL)
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
