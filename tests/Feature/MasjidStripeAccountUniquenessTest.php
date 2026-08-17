<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M1 — the pre-flight guard on `add_unique_index_to_masjid_stripe_account` and
 * the index it guards must be THE SAME PREDICATE, and neither of them may refuse
 * a state that is not actually ambiguous.
 *
 * The migration ships a friendly refusal whose whole job is to keep an operator
 * from meeting a raw `SQLSTATE[23000]` mid-deploy. It excluded the empty string;
 * the index did not. Measured on this branch before the fix, by seeding three
 * states and calling `up()`:
 *
 *     two live rows on the SAME real account id -> REFUSED politely
 *     two live rows on the EMPTY STRING         -> SQLSTATE[23000]: UNIQUE
 *                                                  constraint failed:
 *                                                  masjids.stripe_account_id
 *     two live rows on NULL                     -> up() SUCCEEDED
 *
 * The middle row is the exact failure the guard exists to prevent, and on MySQL
 * the generated column carries `''` through unchanged and fails identically — a
 * production deploy aborting half-migrated with an opaque error.
 *
 * WHICH SIDE MOVED, AND WHY. The empty string is not a Stripe account. Every
 * inbound routing surface already refuses it BEFORE it looks anything up:
 * `RegistrationPaymentService::resolve()` returns null on
 * `$account === null || $account === ''`, and
 * `StripeConnectService::syncAccountStatus()` returns null on a falsy id. So no
 * event can ever be routed to an `''` row, and two of them are not "two
 * organisations fighting over one Stripe account" — they are two organisations
 * that have not onboarded, exactly like the NULL rows the index already
 * tolerates and the parallel owner index already documents ("NULL is 'no owner',
 * not a value that can collide"). Widening the GUARD to match the index would
 * have refused that deploy in the organisation's own words — "clear the wrong
 * one, the organisation that does not own the account" — advice that means
 * nothing when neither owns anything. So the INDEX moved to the guard's
 * predicate instead: `''` is treated as "no connected account", on both drivers,
 * and the two predicates are now written from one place.
 *
 * What is pinned here:
 *   1. the three seeded states, driven through `up()` itself;
 *   2. the invariant that actually matters, unweakened — two live rows on one
 *      REAL account id are still refused by the database;
 *   3. the states that must stay legal — a trashed twin, and any number of
 *      un-onboarded organisations.
 */
class MasjidStripeAccountUniquenessTest extends TestCase
{
    use RefreshDatabase;

    /** Must match the migration. */
    private const INDEX = 'masjids_active_stripe_account_unique';

    private const MIGRATION = 'migrations/2026_08_20_000000_add_unique_index_to_masjid_stripe_account.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    // ------------------------------------------------- the three seeded states

    /**
     * The case the guard was written for. It already worked; it is here so the
     * widening below cannot quietly drop it.
     */
    #[Test]
    public function two_live_organisations_on_one_real_account_are_refused_politely(): void
    {
        $this->dropIndex();

        $this->makeMasjid(['stripe_account_id' => 'acct_shared']);
        $this->makeMasjid(['stripe_account_id' => 'acct_shared']);

        try {
            $this->runMigrationUp();
            $this->fail('up() accepted two live organisations on one Stripe account.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('acct_shared', $e->getMessage());
            $this->assertStringContainsString('cannot be routed', $e->getMessage());
        }

        // Nothing was created: the refusal happens BEFORE any DDL, which is the
        // whole point of a pre-flight.
        $this->assertFalse($this->indexExists(), 'The pre-flight must refuse before it writes DDL.');
    }

    /**
     * THE MEASURED FAILURE. Before the fix this raised QueryException
     * "SQLSTATE[23000]: UNIQUE constraint failed: masjids.stripe_account_id"
     * from inside `up()` — the raw violation the friendly guard exists to
     * replace, on the one class of duplicate the guard skipped.
     */
    #[Test]
    public function two_live_organisations_that_have_not_onboarded_do_not_block_the_migration(): void
    {
        $this->dropIndex();

        $this->makeMasjid(['stripe_account_id' => '']);
        $this->makeMasjid(['stripe_account_id' => '']);

        $this->runMigrationUp();

        $this->assertTrue($this->indexExists(), 'The index must be created over un-onboarded organisations.');
        $this->assertSame(2, Masjid::where('stripe_account_id', '')->count());
    }

    /** Unchanged, and asserted so the widened index cannot break it. */
    #[Test]
    public function any_number_of_organisations_may_have_no_account_at_all(): void
    {
        $this->dropIndex();

        $this->makeMasjid(['stripe_account_id' => null]);
        $this->makeMasjid(['stripe_account_id' => null]);
        $this->makeMasjid(['stripe_account_id' => null]);

        $this->runMigrationUp();

        $this->assertTrue($this->indexExists());
        $this->assertSame(3, Masjid::whereNull('stripe_account_id')->count());
    }

    /**
     * The guard and the index must agree on EVERY state, not on the two somebody
     * happened to think of. This drives the guard's own predicate and the index's
     * own predicate over the same mixed table and asserts they reach the same
     * verdict — which is the property that broke, stated directly.
     */
    #[Test]
    public function the_preflight_refuses_exactly_what_the_index_refuses(): void
    {
        $this->dropIndex();

        // Everything the column can legally hold, none of it a real duplicate.
        $this->makeMasjid(['stripe_account_id' => null]);
        $this->makeMasjid(['stripe_account_id' => null]);
        $this->makeMasjid(['stripe_account_id' => '']);
        $this->makeMasjid(['stripe_account_id' => '']);
        $this->makeMasjid(['stripe_account_id' => 'acct_live']);
        $this->makeMasjid(['stripe_account_id' => 'acct_trashed'])->delete();
        $this->makeMasjid(['stripe_account_id' => 'acct_trashed']);

        // The guard passes…
        $this->runMigrationUp();

        // …and so does the DDL it guards. Before the fix these disagreed.
        $this->assertTrue($this->indexExists());
    }

    // ------------------------------------------------------- the real invariant

    #[Test]
    public function the_database_still_refuses_a_second_live_organisation_on_one_account(): void
    {
        $this->makeMasjid(['stripe_account_id' => 'acct_live']);

        $rejected = false;

        try {
            $this->makeMasjid(['stripe_account_id' => 'acct_live']);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Two live organisations were accepted on one Connect account — an inbound webhook has no home.'
        );
        $this->assertSame(1, Masjid::where('stripe_account_id', 'acct_live')->count());
    }

    /**
     * An offboarded organisation keeps its account id on purpose — `resolve()`
     * falls back to `onlyTrashed()` so money arriving for it is still recorded —
     * so re-onboarding on the same account has to stay legal.
     */
    #[Test]
    public function a_trashed_organisation_does_not_pin_its_account(): void
    {
        $first = $this->makeMasjid(['stripe_account_id' => 'acct_reused']);
        $first->delete();

        $second = $this->makeMasjid(['stripe_account_id' => 'acct_reused']);

        $this->assertSame('acct_reused', $second->stripe_account_id);
        $this->assertSame(1, Masjid::where('stripe_account_id', 'acct_reused')->count());
    }

    /** The un-onboarded state, now legal at the DDL level, is legal at runtime too. */
    #[Test]
    public function two_un_onboarded_organisations_can_be_created_side_by_side(): void
    {
        $this->makeMasjid(['stripe_account_id' => '']);
        $this->makeMasjid(['stripe_account_id' => '']);

        $this->assertSame(2, Masjid::where('stripe_account_id', '')->count());
    }

    // ------------------------------------------------------------------ helpers

    private function runMigrationUp(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    private function dropIndex(): void
    {
        DB::getDriverName() === 'mysql'
            ? DB::statement('DROP INDEX ' . self::INDEX . ' ON masjids')
            : DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE masjids DROP COLUMN active_stripe_account_id');
        }
    }

    private function indexExists(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()'
                . ' AND table_name = ? AND index_name = ?',
                ['masjids', self::INDEX]
            ) !== [];
        }

        return DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?", [self::INDEX]) !== [];
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }
}
