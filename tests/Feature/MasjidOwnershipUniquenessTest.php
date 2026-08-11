<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S0 — ownership determinism (docs/multi-tenant-admin-design.md).
 *
 * `masjids.user_id` was nullable with no unique index, and `User::masjid()` is a
 * `hasOne` — so two masjids pointing at one admin did not error, they made
 * `ResolveMasjidTenant` bind whichever row the database felt like returning.
 * Design invariant 9: "masjids.user_id is unique among non-deleted rows."
 *
 * What is pinned here, in the order the guarantee is built:
 *   1. the DATABASE refuses a duplicate — the only guarantee that survives a
 *      seeder, an artisan command or a hand-written UPDATE;
 *   2. the two states that must stay legal — trashed masjids, and masjids with
 *      no owner (production has three);
 *   3. `UpdateMasjidRequest` turns the violation into the 422 the SPA renders,
 *      WITHOUT breaking the ordinary save that re-submits a masjid's own owner;
 *   4. `masjids:reconcile-owners` converts a dirty database into one the index
 *      can actually be added to — asserted by adding it.
 */
class MasjidOwnershipUniquenessTest extends TestCase
{
    use RefreshDatabase;

    /** Must match `add_owner_uniqueness_to_masjids_table`. */
    private const INDEX = 'masjids_active_owner_unique';

    private int $countryId;

    private int $cityId;

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

        // Country/City declare no $fillable — seed through the query builder.
        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);
    }

    // ---------------------------------------------------------------- database

    #[Test]
    public function the_database_refuses_a_second_live_masjid_for_the_same_owner(): void
    {
        $owner = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $owner->id]);

        $rejected = false;

        try {
            $this->makeMasjid(['user_id' => $owner->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'A second live masjid was accepted for an owner who already has one — ownership is still non-deterministic.'
        );
        $this->assertSame(1, Masjid::where('user_id', $owner->id)->count());
    }

    #[Test]
    public function a_trashed_masjid_does_not_pin_its_former_owner(): void
    {
        $owner = $this->masjidAdmin();
        $first = $this->makeMasjid(['user_id' => $owner->id]);

        $first->delete();
        $this->assertSoftDeleted('masjids', ['id' => $first->id]);

        // Re-provisioning after a trash must work — the index is partial for
        // exactly this reason.
        $second = $this->makeMasjid(['user_id' => $owner->id]);

        $this->assertSame($owner->id, (int) $second->user_id);
        $this->assertSame(1, Masjid::where('user_id', $owner->id)->count());
    }

    #[Test]
    public function any_number_of_masjids_may_have_no_owner(): void
    {
        // Production carries three unowned masjids today; the index must tolerate
        // them, because NULL is "no owner", not a value that can collide.
        $this->makeMasjid(['user_id' => null]);
        $this->makeMasjid(['user_id' => null]);
        $this->makeMasjid(['user_id' => null]);

        $this->assertSame(3, Masjid::whereNull('user_id')->count());
    }

    // ----------------------------------------------------------- update request

    #[Test]
    public function updating_a_masjid_onto_an_owner_who_already_owns_another_is_rejected(): void
    {
        $ownerOfA = $this->masjidAdmin();
        $masjidA = $this->makeMasjid(['user_id' => $ownerOfA->id]);
        $masjidB = $this->makeMasjid(['user_id' => $this->masjidAdmin()->id]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/masjids/{$masjidB->id}", $this->updatePayload($masjidB, [
            'user_id' => $ownerOfA->id,
        ]))
            // BaseFormRequest's envelope — a raw ValidationException would 500 here.
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['status', 'data' => ['user_id']]);

        $this->assertSame($ownerOfA->id, (int) $masjidA->fresh()->user_id, 'Masjid A must keep its owner.');
        $this->assertNotSame($ownerOfA->id, (int) $masjidB->fresh()->user_id);
    }

    #[Test]
    public function re_saving_a_masjid_with_its_own_current_owner_still_succeeds(): void
    {
        // The regression this slice could easily have introduced: every save the
        // SPA makes re-submits the masjid's existing user_id, so a `unique` rule
        // without ignore() would 422 an update that works today.
        $owner = $this->masjidAdmin();
        $masjid = $this->makeMasjid(['user_id' => $owner->id]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/masjids/{$masjid->id}", $this->updatePayload($masjid, [
            'name' => 'Renamed Masjid ' . uniqid(),
            'user_id' => $owner->id,
        ]))->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame($owner->id, (int) $masjid->fresh()->user_id);
    }

    #[Test]
    public function a_masjid_may_take_over_an_owner_whose_only_other_masjid_is_trashed(): void
    {
        // The validation rule is scoped to live rows, like the index; a trashed
        // masjid must not block re-pointing its former owner.
        $owner = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $owner->id])->delete();
        $target = $this->makeMasjid(['user_id' => null]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/masjids/{$target->id}", $this->updatePayload($target, [
            'user_id' => $owner->id,
        ]))->assertOk();

        $this->assertSame($owner->id, (int) $target->fresh()->user_id);
    }

    // ------------------------------------------------------- reconcile command

    #[Test]
    public function the_reconcile_command_reports_a_clean_database_as_clean(): void
    {
        $this->makeMasjid(['user_id' => $this->masjidAdmin()->id]);
        $this->makeMasjid(['user_id' => null]);

        $this->artisan('masjids:reconcile-owners')->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function the_reconcile_command_resolves_duplicates_into_a_state_the_index_accepts(): void
    {
        // Reproduce a pre-index database — a staging box or an old dump — by
        // dropping the constraint this slice adds. Without that, the duplicates
        // this command exists for cannot be created at all.
        $this->dropOwnerUniqueIndex();

        $owner = $this->masjidAdmin();
        $first = $this->makeMasjid(['user_id' => $owner->id, 'name' => 'First ' . uniqid()]);
        $second = $this->makeMasjid(['user_id' => $owner->id, 'name' => 'Second ' . uniqid()]);
        $third = $this->makeMasjid(['user_id' => $owner->id, 'name' => 'Third ' . uniqid()]);

        // Reporting is read-only and tells the operator the database is dirty.
        $this->artisan('masjids:reconcile-owners')->assertExitCode(Command::FAILURE);
        $this->assertSame(3, Masjid::where('user_id', $owner->id)->count(), 'A report run must change nothing.');

        $this->artisan('masjids:reconcile-owners', ['--fix' => true])->assertExitCode(Command::SUCCESS);

        // First-created wins; the losers are detached, never deleted.
        $this->assertSame($owner->id, (int) $first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);
        $this->assertNull($third->fresh()->user_id);
        $this->assertSame(3, Masjid::count());

        // The actual point of the command: the index can now be created. If this
        // throws, the reconciliation did not do its job.
        $this->createOwnerUniqueIndex();

        $this->artisan('masjids:reconcile-owners')->assertExitCode(Command::SUCCESS);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Drop / recreate the ownership index exactly as the migration writes it,
     * per driver. The suite runs on SQLite (partial index); the MySQL branch
     * keeps this test honest if it is ever pointed at production's driver, where
     * the same predicate is carried by the `active_owner_user_id` generated
     * column that stays in place while only the index comes and goes.
     */
    private function dropOwnerUniqueIndex(): void
    {
        DB::getDriverName() === 'mysql'
            ? DB::statement('DROP INDEX ' . self::INDEX . ' ON masjids')
            : DB::statement('DROP INDEX ' . self::INDEX);
    }

    private function createOwnerUniqueIndex(): void
    {
        DB::getDriverName() === 'mysql'
            ? DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (active_owner_user_id)')
            : DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (user_id) WHERE deleted_at IS NULL');
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    /** The fields UpdateMasjidRequest requires, echoing the masjid's own values. */
    private function updatePayload(Masjid $masjid, array $overrides = []): array
    {
        return array_merge([
            'name' => $masjid->name,
            'email' => $masjid->email,
            'phone' => $masjid->phone,
            'address' => $masjid->address,
            'latitude' => 0.0,
            'longitude' => 0.0,
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
        ], $overrides);
    }

    private function masjidAdmin(): User
    {
        return User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }
}
