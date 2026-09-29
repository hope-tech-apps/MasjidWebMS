<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidPointsSetting;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Support\AcademicRecordsHeld;
use App\Support\CapabilityWriter;
use App\Support\ClassStoreSettings;
use App\Support\SchoolSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoreFixture;
use Tests\TestCase;

/**
 * T-003.4 (W6): the class store's foundations. Not what a teacher can do (ClassStoreApiTest),
 * not how bucks are made (ClassStoreMintingTest) and not who may read them
 * (ClassStorePrivacyTest): the SHAPE of the data, and the switch.
 *
 * Three promises are pinned here because nothing else can catch them:
 *   - the schema survives MySQL, which SQLite hides (index names over 64 characters, column
 *     types SQLite does not enforce, a partial index that does not exist there);
 *   - the ledger is APPEND-ONLY in the application, and a child's rows leave TOGETHER or not
 *     at all, so a balance is never left partial or negative;
 *   - the store is OFF for every organisation, and while it is off the mobile /features and
 *     tv-config are byte for byte what they were.
 */
class ClassStoreSchemaTest extends TestCase
{
    use BuildsClassStoreFixture;
    use RefreshDatabase;

    private const MIGRATIONS = [
        'database/migrations/2026_10_04_100000_create_prizes_table.php',
        'database/migrations/2026_10_04_100100_create_prize_ledger_entries_table.php',
        'database/migrations/2026_10_04_100200_add_prizes_converted_at_to_behavior_weeks_table.php',
        'database/migrations/2026_10_04_100300_add_buck_settings_to_masjid_points_settings_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreSchools();
    }

    protected function tearDown(): void
    {
        $this->thaw();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the schema

    #[Test]
    public function the_tables_and_their_column_types_are_what_mysql_will_also_enforce(): void
    {
        foreach (['prizes', 'prize_ledger_entries'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        // SQLite ignores varchar(n) and integer widths, so a round-trip proves nothing: assert
        // the declared TYPE (.claude/rules/shipping.md).
        $this->assertSame('varchar', Schema::getColumnType('prizes', 'title'));
        $this->assertSame('varchar', Schema::getColumnType('prizes', 'description'));
        $this->assertSame('integer', Schema::getColumnType('prizes', 'cost_bucks'));
        $this->assertSame('integer', Schema::getColumnType('prizes', 'stock'));
        $this->assertSame('varchar', Schema::getColumnType('prize_ledger_entries', 'kind'));
        $this->assertSame('integer', Schema::getColumnType('prize_ledger_entries', 'amount'));
        $this->assertSame('varchar', Schema::getColumnType('prize_ledger_entries', 'dedupe_key'));
        $this->assertSame('varchar', Schema::getColumnType('prize_ledger_entries', 'note'));
        $this->assertSame('datetime', Schema::getColumnType('prize_ledger_entries', 'occurred_at'));
        $this->assertSame('date', Schema::getColumnType('prize_ledger_entries', 'retained_until'));
        $this->assertSame('datetime', Schema::getColumnType('behavior_weeks', 'prizes_converted_at'));
        $this->assertSame('date', Schema::getColumnType('masjid_points_settings', 'bucks_from'));

        // The migrations declare the widths MySQL will enforce; SQLite cannot show them.
        $ledger = file_get_contents(base_path(self::MIGRATIONS[1]));
        $this->assertStringContainsString("string('kind', 16)", $ledger);
        $this->assertStringContainsString("string('dedupe_key', 64)", $ledger);
        $this->assertStringContainsString("string('note', 255)", $ledger);
        $this->assertStringContainsString("string('title', 120)", file_get_contents(base_path(self::MIGRATIONS[0])));
    }

    #[Test]
    public function every_index_name_is_hand_written_and_fits_mysqls_64_characters(): void
    {
        $expected = [
            'prizes' => ['prizes_masjid_active_idx', 'prizes_masjid_group_idx'],
            'prize_ledger_entries' => [
                'prize_ledger_dedupe_unique', 'prize_ledger_member_occurred_idx',
                'prize_ledger_group_occurred_idx', 'prize_ledger_retained_idx',
            ],
        ];

        foreach ($expected as $table => $names) {
            $have = array_column(Schema::getIndexes($table), 'name');

            foreach ($names as $name) {
                $this->assertContains($name, $have, "{$table} lost its hand-named index {$name}");
            }

            foreach ($have as $name) {
                $this->assertLessThanOrEqual(64, strlen((string) $name), "{$table}: {$name} is over MySQL's 64 characters");
            }
        }

        // The default Laravel would have generated for the ledger's natural composite is 68
        // characters: proof the hand names are doing real work, not decoration.
        $this->assertSame(68, strlen('prize_ledger_entries_masjid_id_group_membership_id_occurred_at_index'));

        // And the source: no index or unique in any of the four migrations without a name of
        // its own, no enum, no partial index.
        foreach (self::MIGRATIONS as $path) {
            $source = file_get_contents(base_path($path));

            foreach (preg_split('/\R/', $source) as $line) {
                if (preg_match('/->(index|unique)\(/', $line) === 1) {
                    $this->assertMatchesRegularExpression("/,\s*'[a-z0-9_]+'\)\s*;/", $line, "{$path}: an index with no hand name: {$line}");
                }
            }

            $this->assertStringNotContainsString('->enum(', $source, "{$path} uses a database enum");
            $this->assertStringNotContainsString('WHERE', $source, "{$path} reaches for a partial index");
        }
    }

    #[Test]
    public function the_ledger_has_no_updated_at_and_no_soft_delete_because_nothing_is_ever_updated(): void
    {
        $columns = Schema::getColumnListing('prize_ledger_entries');

        $this->assertNotContains('updated_at', $columns);
        $this->assertNotContains('created_at', $columns);
        $this->assertNotContains('deleted_at', $columns);
        $this->assertContains('occurred_at', $columns);
        $this->assertFalse((new PrizeLedgerEntry)->usesTimestamps());
        $this->assertNotContains(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(PrizeLedgerEntry::class));
    }

    #[Test]
    public function a_dedupe_key_is_unique_and_a_missing_one_is_not(): void
    {
        $this->credit($this->amira, 3);
        $this->credit($this->amira, 3);
        $this->assertSame(2, PrizeLedgerEntry::query()->whereNull('dedupe_key')->count(), 'NULLs are distinct, so unkeyed rows repeat freely');

        $row = fn (string $key) => PrizeLedgerEntry::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
            'kind' => PrizeLedgerEntry::KIND_EARNED, 'amount' => 1, 'dedupe_key' => $key,
        ]);

        $row('earned:1:2026-10-04');

        $this->expectException(QueryException::class);
        $row('earned:1:2026-10-04');
    }

    // ------------------------------------------------------------ append-only

    #[Test]
    public function the_ledger_refuses_an_update_and_a_delete_through_the_model(): void
    {
        $entry = $this->credit($this->amira, 5);

        $refused = 0;

        foreach ([
            fn () => $entry->update(['amount' => 500]),
            fn () => $entry->forceFill(['amount' => 500])->save(),
            fn () => $entry->delete(),
            fn () => $entry->forceDelete(),
            fn () => PrizeLedgerEntry::query()->whereKey($entry->id)->get()->each->delete(),
        ] as $attempt) {
            try {
                $attempt();
            } catch (LogicException $e) {
                $refused++;
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame(5, $refused, 'every route around the model guard must be refused');
        $this->assertSame(5, $this->balanceOf($this->amira));
        $this->assertSame(1, PrizeLedgerEntry::query()->count());
    }

    #[Test]
    public function no_route_updates_or_deletes_a_ledger_entry(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (! preg_match('#(prize-entries|prize_ledger|bucks/entries)#', $route->uri())) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $this->assertNotContains($method, ['PUT', 'PATCH', 'DELETE'], $method.' '.$route->uri().' would edit the ledger');
            }
        }

        $this->assertTrue(true);
    }

    // ---------------------------------------------------- a set goes together

    #[Test]
    public function the_retention_purge_removes_a_childs_ledger_only_when_every_row_is_due(): void
    {
        $past = now()->subDays(10)->toDateString();
        $future = now()->addDays(10)->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $entry = function (GroupMembership $m, int $amount, ?string $retained) {
            return PrizeLedgerEntry::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $m->id,
                'kind' => $amount >= 0 ? PrizeLedgerEntry::KIND_EARNED : PrizeLedgerEntry::KIND_REDEEMED,
                'amount' => $amount, 'retained_until' => $retained,
            ]);
        };

        // Amira: earned long ago (due), spent recently (NOT due). Purging only the due row
        // would leave -4: the whole set must stay.
        $entry($this->amira, 10, $past);
        $entry($this->amira, -4, $future);

        // Yusuf: every row due. The whole set goes, and nothing is left partial.
        $entry($this->yusuf, 8, $past);
        $entry($this->yusuf, -3, $yesterday);

        $removed = PrizeLedgerEntry::purgeDueSets(now()->toDateString());

        $this->assertSame(2, $removed);
        $this->assertSame(6, $this->balanceOf($this->amira), 'her set is intact, so the balance is still explained');
        $this->assertSame(2, PrizeLedgerEntry::query()->where('group_membership_id', $this->amira->id)->count());
        $this->assertSame(0, PrizeLedgerEntry::query()->where('group_membership_id', $this->yusuf->id)->count());
    }

    #[Test]
    public function a_row_with_no_retention_date_keeps_its_whole_set_and_a_dry_run_deletes_nothing(): void
    {
        config(['groups.bucks.retention_days' => 0]);

        $old = PrizeLedgerEntry::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
            'kind' => PrizeLedgerEntry::KIND_EARNED, 'amount' => 4, 'retained_until' => now()->subYear()->toDateString(),
        ]);
        $kept = $this->credit($this->amira, 2); // retention 0: retained_until stays NULL

        $this->assertNull($kept->fresh()->retained_until);
        $this->assertSame(0, PrizeLedgerEntry::purgeDueSets(now()->toDateString()));
        $this->assertSame(2, PrizeLedgerEntry::query()->count());

        // Give the newest row a due date: now everything is due, but a dry run only counts.
        DB::table('prize_ledger_entries')->where('id', $kept->id)->update(['retained_until' => now()->subDay()->toDateString()]);
        $this->assertSame(2, PrizeLedgerEntry::purgeDueSets(now()->toDateString(), null, true));
        $this->assertSame(2, PrizeLedgerEntry::query()->count(), 'a dry run removes nothing');
        $this->assertSame(2, PrizeLedgerEntry::purgeDueSets(now()->toDateString()));
        $this->assertNotNull($old);
    }

    #[Test]
    public function every_new_row_is_stamped_from_its_own_date_so_the_newest_row_sets_when_the_set_goes(): void
    {
        config(['groups.bucks.retention_days' => 30]);

        $this->freeze('2026-10-04 12:00');
        $first = $this->credit($this->amira, 2);
        $this->freeze('2026-10-20 12:00');
        $second = $this->credit($this->amira, 2);

        $this->assertSame('2026-11-03', $first->fresh()->retained_until->toDateString());
        $this->assertSame('2026-11-19', $second->fresh()->retained_until->toDateString());

        // On the day the first row is due the set is not, because the second is not.
        $this->assertSame(0, PrizeLedgerEntry::purgeDueSets('2026-11-03'));
        $this->assertSame(2, PrizeLedgerEntry::purgeDueSets('2026-11-19'));
    }

    #[Test]
    public function the_group_retention_sweep_command_includes_the_ledger_and_says_so(): void
    {
        $this->credit($this->amira, 3);
        DB::table('prize_ledger_entries')->update(['retained_until' => now()->subDay()->toDateString()]);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('groups:purge-feed'));
        $this->assertStringContainsString('1 bucks ledger row(s)', \Illuminate\Support\Facades\Artisan::output());
        $this->assertSame(0, PrizeLedgerEntry::query()->count());
    }

    // ---------------------------------------- a roster row that holds a ledger

    #[Test]
    public function a_roster_row_holding_a_ledger_cannot_be_removed_and_the_office_is_told_why(): void
    {
        $this->credit($this->amira, 3);

        $held = AcademicRecordsHeld::counts($this->amira);
        $this->assertSame(1, $held['Manara Bucks']);
        $this->assertSame('1 Manara Bucks', AcademicRecordsHeld::describe(['Manara Bucks' => 1, 'marks' => 0]));

        $this->actAs($this->admin);
        $this->deleteJson($this->adminUrl('/groups/'.$this->class->id.'/members/'.$this->amira->id))
            ->assertStatus(409)
            ->assertJsonPath('status', 'failed');

        $this->assertNotNull(GroupMembership::query()->find($this->amira->id));

        // And the database itself refuses, whoever asks: RESTRICT, not a courtesy.
        $this->expectException(QueryException::class);
        DB::table('group_memberships')->where('id', $this->amira->id)->delete();
    }

    // --------------------------------------------------------------- settings

    #[Test]
    public function the_settings_read_as_one_point_a_buck_and_paper_off_when_nothing_is_set(): void
    {
        $this->assertSame(
            ['points_per_buck' => 1, 'paper_bucks_enabled' => false, 'bucks_from' => null],
            ClassStoreSettings::for($this->school->id),
        );

        // A row written for the report schedule alone (before these columns) reads the same.
        MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $this->school->id, 'report_weekday' => 5, 'report_time' => '15:00']);
        $this->assertSame(
            ['points_per_buck' => 1, 'paper_bucks_enabled' => false, 'bucks_from' => null],
            ClassStoreSettings::for($this->school->id),
        );

        // A hand-edited row that is not usable reads as the default rather than crashing the hourly run.
        DB::table('masjid_points_settings')->where('masjid_id', $this->school->id)->update(['points_per_buck' => 0]);
        $this->assertSame(1, ClassStoreSettings::for($this->school->id)['points_per_buck']);
        DB::table('masjid_points_settings')->where('masjid_id', $this->school->id)->update(['points_per_buck' => 5000]);
        $this->assertSame(1, ClassStoreSettings::for($this->school->id)['points_per_buck']);
    }

    // -------------------------------------------------------- the capability

    #[Test]
    public function the_class_store_is_off_for_every_organisation_type_until_a_superadmin_decides(): void
    {
        foreach (['masjid', 'school', 'community'] as $type) {
            $org = $this->newSchool('Type '.$type, $type);

            $this->assertFalse($org->hasCapability('class_store'), "{$type} must not have the store by default");
            $this->assertFalse(SchoolSettings::classStore($org->fresh()), $type);
        }

        $this->assertFalse(SchoolSettings::classStore(null), 'an unknown organisation reads as off');
        $this->assertSame('grant', config('capabilities.class_store.kind'));
        $this->assertSame('school', config('capabilities.class_store.group'));
        $this->assertSame(['masjid' => false, 'school' => false, 'community' => false], config('capabilities.class_store.defaults'));
        $this->assertFalse(config('capabilities.class_store.listed_when_off'));
    }

    #[Test]
    public function it_is_writable_through_the_capability_writer_and_reads_back(): void
    {
        $super = \App\Models\User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);

        $this->assertFalse(SchoolSettings::classStore($this->school->fresh()));

        CapabilityWriter::apply($this->school, ['class_store' => true], (int) $super->id);
        $this->assertTrue(SchoolSettings::classStore($this->school->fresh()));

        CapabilityWriter::apply($this->school->fresh(), ['class_store' => false], (int) $super->id);
        $this->assertFalse(SchoolSettings::classStore($this->school->fresh()));

        // The switch is audited like every grant.
        $this->assertSame(2, DB::table('masjid_capability_changes')->where('masjid_id', $this->school->id)->where('capability', 'class_store')->count());
    }

    #[Test]
    public function while_the_store_is_off_or_on_the_mobile_features_and_tv_config_are_byte_for_byte_unchanged(): void
    {
        Cache::flush();
        $features = $this->getJson('/api/mobile/masjids/'.$this->school->id.'/features');
        $tv = $this->getJson('/api/mobile/masjids/'.$this->school->id.'/tv-config');
        $features->assertOk();
        $tv->assertOk();
        $before = [$features->getContent(), $tv->getContent()];

        // Straight to the column: touching the model would move updated_at, which is not the point.
        DB::table('masjids')->where('id', $this->school->id)->update(['capability_overrides' => json_encode(['class_store' => true])]);
        Cache::flush();

        $featuresOn = $this->getJson('/api/mobile/masjids/'.$this->school->id.'/features');
        $tvOn = $this->getJson('/api/mobile/masjids/'.$this->school->id.'/tv-config');

        $this->assertSame($before[0], $featuresOn->getContent(), '/features moved when the class store was switched on');
        $this->assertSame($before[1], $tvOn->getContent(), 'tv-config moved when the class store was switched on');
        $this->assertStringNotContainsString('class_store', $before[0].$before[1]);
    }

    // ----------------------------------------------------------- the models

    #[Test]
    public function a_prize_belongs_to_a_shelf_and_a_shelf_is_the_school_wide_list_plus_the_classs_own(): void
    {
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $wide = $this->prize(['title' => 'Sticker']);
        $mine = $this->prize(['title' => 'Bookmark', 'group_id' => $this->class->id]);
        $theirs = $this->prize(['title' => 'Eraser', 'group_id' => $other->id]);

        $shelf = Prize::query()->availableTo($this->class)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$wide->id, $mine->id], $shelf);
        $this->assertNotContains($theirs->id, $shelf);
        $this->assertTrue($wide->isSchoolWide());
        $this->assertFalse($mine->isSchoolWide());
        $this->assertTrue($this->prize(['stock' => null])->inStock());
        $this->assertTrue($this->prize(['stock' => 1])->inStock());
        $this->assertFalse($this->prize(['stock' => 0])->inStock());
    }

    #[Test]
    public function the_two_new_migrations_roll_back_and_the_ledger_one_refuses_while_it_holds_rows(): void
    {
        $this->credit($this->amira, 1);

        $migration = require base_path(self::MIGRATIONS[1]);

        try {
            $migration->down();
            $this->fail('down() must refuse while the ledger has rows');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('prize_ledger_entries'));
        $this->assertSame(1, PrizeLedgerEntry::query()->count());
    }
}
