<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `add_listed_at_to_masjids_table` — does the migration preserve what production
 * already shows?
 *
 * The column defaults to NULL, so a bare `ALTER` would drop all four live
 * tenants out of the mobile app's organisation picker the moment it ran. The
 * migration therefore backfills by POLICY — never by id:
 *
 *     an organisation real people are already using, or that has already
 *     published content to them, stays listed.
 *
 * These tests build a PRE-migration state (rows whose `listed_at` is still NULL,
 * carrying the footprint each production tenant actually has) and then re-run
 * the migration's `up()` against it — which is safe because `up()` is
 * deliberately idempotent. They assert the outcome measured on production on
 * 2026-08-12:
 *
 *   Burlington Masjid       221 app users, 12 announcements, 10 services → listed
 *   NAFIS Apex Mosque         1 app user,   0 announcements,  8 services → listed
 *   Muslim Education Center   3 app users,  3 announcements, 11 services → listed
 *   test2                     0 app users,  0 announcements,  0 services → NOT
 */
class MasjidDirectoryListingBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $countryId;

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

        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);
    }

    /**
     * The headline: the three real congregations keep the listing they have
     * today; the seed tenant loses it. Both by the same predicate.
     */
    #[Test]
    public function the_backfill_lists_organisations_in_real_use_and_leaves_an_empty_tenant_out(): void
    {
        // ---- pre-migration state: everything unlisted ----
        $burlington = $this->preMigrationMasjid('Burlington Masjid');
        $this->giveMobileAppUsers($burlington, 221);
        $this->giveAnnouncements($burlington, 12);
        $this->giveServices($burlington, 10);

        $apex = $this->preMigrationMasjid('NAFIS Apex Mosque');
        $this->giveMobileAppUsers($apex, 1);
        $this->giveServices($apex, 8);

        $mec = $this->preMigrationMasjid('Muslim Education Center');
        $this->giveMobileAppUsers($mec, 3);
        $this->giveAnnouncements($mec, 3);
        $this->giveServices($mec, 11);

        $seed = $this->preMigrationMasjid('test2');

        foreach ([$burlington, $apex, $mec, $seed] as $masjid) {
            $this->assertNull($masjid->fresh()->listed_at, 'the pre-migration state is not what it claims to be');
        }

        // Without the backfill, this deploy hides every live congregation —
        // which is the production incident the policy exists to prevent.
        $this->assertSame([], $this->directoryIds());

        // Replay the migration from a schema that genuinely has no `listed_at`,
        // so this exercises the deploy path and not just the backfill query.
        $this->replayFromPreMigrationSchema();

        $this->assertNotNull($burlington->fresh()->listed_at);
        $this->assertNotNull($apex->fresh()->listed_at);
        $this->assertNotNull($mec->fresh()->listed_at);
        $this->assertNull($seed->fresh()->listed_at, 'a tenant with no audience and no content was published');

        $this->assertEqualsCanonicalizing(
            [$burlington->id, $apex->id, $mec->id],
            $this->directoryIds()
        );
    }

    /**
     * The predicate is an OR of three independent signs of real use, so that it
     * errs toward LISTING: any one of them on its own is enough. Hiding a live
     * organisation is the expensive mistake here, not listing a quiet one.
     */
    #[Test]
    public function any_single_sign_of_real_use_is_enough_to_stay_listed(): void
    {
        $onlyUsers = $this->preMigrationMasjid('App Users Only');
        $this->giveMobileAppUsers($onlyUsers, 1);

        $onlyAnnouncements = $this->preMigrationMasjid('Announcements Only');
        $this->giveAnnouncements($onlyAnnouncements, 1);

        $onlyServices = $this->preMigrationMasjid('Services Only');
        $this->giveServices($onlyServices, 1);

        $nothing = $this->preMigrationMasjid('Empty Shell');

        $this->runListedAtMigration();

        $this->assertNotNull($onlyUsers->fresh()->listed_at);
        $this->assertNotNull($onlyAnnouncements->fresh()->listed_at);
        $this->assertNotNull($onlyServices->fresh()->listed_at);
        $this->assertNull($nothing->fresh()->listed_at);
    }

    /** "Listed since" answers when the organisation went live, so it dates from the row. */
    #[Test]
    public function a_backfilled_organisation_is_listed_as_of_its_creation(): void
    {
        $masjid = $this->preMigrationMasjid('Old Timer');
        DB::table('masjids')->where('id', $masjid->id)->update([
            'created_at' => '2025-04-08 16:02:56',
        ]);
        $this->giveMobileAppUsers($masjid, 1);

        $this->runListedAtMigration();

        $this->assertSame(
            '2025-04-08 16:02:56',
            $masjid->fresh()->listed_at->format('Y-m-d H:i:s')
        );
    }

    /**
     * Archived organisations are backfilled too. They are already invisible
     * through the SoftDeletes global scope, and leaving them NULL would quietly
     * change what `restore()` does — a restored masjid used to reappear in the
     * directory.
     */
    #[Test]
    public function an_archived_organisation_in_real_use_is_backfilled_so_restore_still_works(): void
    {
        $masjid = $this->preMigrationMasjid('Archived But Real');
        $this->giveAnnouncements($masjid, 2);
        $masjid->delete();

        $this->runListedAtMigration();

        $this->assertNotNull(Masjid::withTrashed()->findOrFail($masjid->id)->listed_at);
        $this->assertNotContains($masjid->id, $this->directoryIds(), 'an archived organisation surfaced in the directory');

        Masjid::withTrashed()->findOrFail($masjid->id)->restore();

        $this->assertContains($masjid->id, $this->directoryIds());
    }

    /**
     * The backfill only ever fills in a NULL — it never moves a listing that is
     * already recorded.
     *
     * Laravel runs a migration once per database, so a second `up()` is not a
     * deploy path; this pins the `whereNull` guard so that a later edit to
     * "just set now() everywhere" would be caught, and it is what makes the
     * replay these tests rely on legitimate.
     */
    #[Test]
    public function the_backfill_never_moves_a_listing_that_already_exists(): void
    {
        $masjid = $this->preMigrationMasjid('Already Live');
        $this->giveMobileAppUsers($masjid, 5);

        $this->runListedAtMigration();

        $listedAt = $masjid->fresh()->listed_at;
        $this->assertNotNull($listedAt);

        $this->giveServices($masjid, 3);
        $this->runListedAtMigration();

        $this->assertTrue(
            $listedAt->equalTo($masjid->fresh()->listed_at),
            'a second pass rewrote "listed since"'
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Re-run the migration under test against the current data.
     *
     * `require` (not require_once) re-evaluates the file and hands back a fresh
     * instance of its anonymous migration class, exactly as Laravel's migrator
     * does.
     */
    private function runListedAtMigration(): void
    {
        $this->listedAtMigration()->up();
    }

    /**
     * Take the schema back to where it was before this migration — no
     * `listed_at` column at all — and then migrate forward over the seeded data.
     * This is the actual deploy, run against a stand-in for today's production
     * rows.
     */
    private function replayFromPreMigrationSchema(): void
    {
        $migration = $this->listedAtMigration();

        $migration->down();
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('masjids', 'listed_at'),
            'could not reach the pre-migration schema'
        );

        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('masjids', 'listed_at'));
    }

    private function listedAtMigration(): Migration
    {
        $paths = glob(database_path('migrations/*_add_listed_at_to_masjids_table.php'));

        $this->assertNotEmpty($paths, 'the listed_at migration is missing');

        $migration = require $paths[0];

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /** A masjid as it existed before this migration: no listing either way. */
    private function preMigrationMasjid(string $name): Masjid
    {
        $masjid = Masjid::create([
            'name' => $name,
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'address' => '1 Test St',
            'latitude' => 43.32,
            'longitude' => -79.79,
            'timezone' => 'America/Toronto',
        ]);

        DB::table('masjids')->where('id', $masjid->id)->update(['listed_at' => null]);

        return $masjid->fresh();
    }

    private function giveMobileAppUsers(Masjid $masjid, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('mobile_app_users')->insert([
                'masjid_id' => $masjid->id,
                'device_id' => 'device-' . $masjid->id . '-' . $i . '-' . uniqid(),
                'user_agent' => 'PHPUnit',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function giveAnnouncements(Masjid $masjid, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('announcements')->insert([
                'masjid_id' => $masjid->id,
                'title' => 'Announcement ' . $i,
                'details' => 'Details',
                'text' => '',
                'start_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function giveServices(Masjid $masjid, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('services')->insert([
                'masjid_id' => $masjid->id,
                'title' => 'Service ' . $i,
                'description' => 'Description',
                'text' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Ids the mobile directory currently offers (uncached — each call is a fresh request). */
    private function directoryIds(): array
    {
        \Illuminate\Support\Facades\Cache::forget(
            \App\Support\MobileCache::globalKey(\App\Support\MobileCache::MASJIDS_LIST)
        );

        return array_column(
            $this->getJson('/api/mobile/masjids')->assertOk()->json('data'),
            'id'
        );
    }
}
