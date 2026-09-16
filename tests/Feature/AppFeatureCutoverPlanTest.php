<?php

namespace Tests\Feature;

use App\Console\Commands\AppFeaturesCutoverPlan;
use App\Models\Announcement;
use App\Models\ContactReason;
use App\Models\DonationLink;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * `app-features:cutover-plan` — the command whose entire value is that it
 * changes nothing.
 *
 * It is written to be run against PRODUCTION, days before the migration it
 * describes exists, so an owner can resolve the decisions it surfaces while
 * there is still time. That only works if "read-only" is a fact rather than an
 * intention, so the first thing this file does is take a full snapshot of every
 * table the cutover would ever touch and assert it is byte-identical afterwards.
 *
 * The rest is about whether the plan tells the truth:
 *
 *   - the mapping is the literal one (ids 1-5 worship, 7-11 content, 6 special);
 *   - Donate ON never proposes `giving = true`, because an app drawer row is not
 *     evidence that an organisation can take card gifts;
 *   - Donate OFF proposes `giving = false` only where that is already safe;
 *   - an app row switched off over live content or open intake is BLOCKING, and
 *     an owner's resolution clears it;
 *   - the two notice classes — no pivot rows at all, and a worship row off at a
 *     masjid — are reported and do NOT block.
 *
 * Runs against the in-memory SQLite fixture like every other suite here. It has
 * no production access of any kind and must never gain any.
 */
class AppFeatureCutoverPlanTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    /**
     * The eleven catalogue rows, in the ids the installed builds route by —
     * including the U+2019 apostrophe in `Qur’an`, which Play vc13 matches on
     * by NAME.
     *
     * @var array<int, array{0: string, 1: string}> id => [name, key]
     */
    private const CATALOGUE = [
        1 => ['Qur’an', 'quran'],
        2 => ['Hadith', 'hadith'],
        3 => ['Adhkar', 'adhkar'],
        4 => ['Qibla', 'qibla'],
        5 => ['Tasbih', 'tasbih'],
        6 => ['Donate', 'donate'],
        7 => ['About Us', 'about_us'],
        8 => ['Gallery', 'gallery'],
        9 => ['Services', 'services'],
        10 => ['Announcements', 'announcements'],
        11 => ['Contact Us', 'contact_us'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();

        // `name` and `key` only. `is_available` is NOT a column on
        // mobile_app_features — availability is per organisation and lives on
        // the pivot, masjid_mobile_app_features, which orgWithPivot() writes
        // and which the command reads. MobileAppFeature::$fillable lists it
        // anyway, which is what made passing it here look right; Eloquent then
        // puts it in the INSERT and SQLite refuses the whole statement.
        foreach (self::CATALOGUE as [$name, $key]) {
            MobileAppFeature::create(['name' => $name, 'key' => $key]);
        }
    }

    // ---------------------------------------------------------- it writes nothing

    /**
     * THE POINT OF THE COMMAND.
     *
     * A full snapshot of every table the cutover would ever write, taken before
     * and compared after — not a row count, which would miss a value changed in
     * place, and not the ledger alone, which would miss a raw write.
     */
    #[Test]
    public function it_writes_absolutely_nothing(): void
    {
        $org = $this->orgWithPivot('Muslim Education Center', [
            1 => false, 2 => true, 6 => false, 10 => false, 11 => false,
        ]);

        $this->announcement($org);
        DonationLink::create(['masjid_id' => $org->id, 'link' => 'https://example.test/give']);

        $before = $this->snapshot();

        Artisan::call('app-features:cutover-plan');

        $this->assertSame($before, $this->snapshot(), 'the plan changed something it must never change');
    }

    /** The command polices its own promise, so a future edit that writes fails loudly. */
    #[Test]
    public function the_command_asserts_the_capability_ledger_is_unchanged(): void
    {
        $this->orgWithPivot('Muslim Education Center', [1 => true]);

        $ledgerBefore = MasjidCapabilityChange::query()->count();

        Artisan::call('app-features:cutover-plan');

        $this->assertSame($ledgerBefore, MasjidCapabilityChange::query()->count());
    }

    // ------------------------------------------------------------- the mapping

    #[Test]
    public function the_mapping_is_the_literal_one(): void
    {
        $this->assertSame([
            1 => 'quran',
            2 => 'hadith',
            3 => 'adhkar',
            4 => 'qibla',
            5 => 'tasbih',
            6 => 'donation_link',
            7 => 'about_us',
            8 => 'gallery',
            9 => 'services',
            10 => 'announcements',
            11 => 'contact_requests',
        ], AppFeaturesCutoverPlan::LEGACY_MAP);

        // Id 10 is Announcements ALONE. It must never write `events`: the menu
        // entry opens both, but the legacy row means one of them.
        $this->assertNotContains('events', AppFeaturesCutoverPlan::LEGACY_MAP);
    }

    #[Test]
    public function each_id_proposes_the_pivot_value_it_carries(): void
    {
        $org = $this->orgWithPivot('Muslim Education Center', [
            1 => false, 2 => true, 7 => true, 9 => false,
        ]);

        $rows = $this->rowsFor($org);

        $this->assertSame(['quran' => false], $rows[1]['writes']);
        $this->assertSame(['hadith' => true], $rows[2]['writes']);
        $this->assertSame(['about_us' => true], $rows[7]['writes']);
        $this->assertSame(['services' => false], $rows[9]['writes']);

        // An id with no pivot row carries no decision, so it proposes nothing.
        $this->assertSame([], $rows[8]['writes']);
        $this->assertNull($rows[8]['pivot']);
    }

    /**
     * Giving carries Stripe, funds and receipts. Nothing about a drawer row
     * being on is evidence that an organisation is ready to take card gifts, so
     * the mapping may never propose switching it on.
     */
    #[Test]
    public function donate_on_writes_the_link_and_never_switches_giving_on(): void
    {
        $org = $this->orgWithPivot('Muslim Education Center', [6 => true]);

        $this->assertSame(['donation_link' => true], $this->rowsFor($org)[6]['writes']);
    }

    #[Test]
    public function donate_off_writes_giving_off_only_when_that_is_already_safe(): void
    {
        $safe = $this->orgWithPivot('Quiet Masjid', [6 => false]);

        $this->assertSame(
            ['donation_link' => false, 'giving' => false],
            $this->rowsFor($safe)[6]['writes']
        );

        $funded = $this->orgWithPivot('Funded Masjid', [6 => false]);
        $this->fund($funded, 'Zakat', 'zakat');

        $this->assertSame(
            ['donation_link' => false],
            $this->rowsFor($funded)[6]['writes'],
            'giving must be left alone while money is attached to it'
        );
    }

    // ------------------------------------------------------------ the conflicts

    #[Test]
    public function an_app_row_off_over_live_content_is_a_blocking_decision(): void
    {
        $org = $this->orgWithPivot('Muslim Education Center', [10 => false]);
        $this->announcement($org);

        $finding = $this->findingFor($org, 'a', 'announcements');

        $this->assertNotNull($finding, 'announcements off over a live announcement must be raised');
        $this->assertTrue($finding['blocking']);
        $this->assertStringContainsString('1 announcement', $finding['message']);
    }

    #[Test]
    public function contact_requests_off_is_raised_even_with_nothing_stored(): void
    {
        // The cost here is not stored content: a public form stops accepting
        // messages. No row count can show that, so it is said unconditionally.
        $org = $this->orgWithPivot('Muslim Education Center', [11 => false]);

        $finding = $this->findingFor($org, 'a', 'contact_requests');

        $this->assertNotNull($finding);
        $this->assertTrue($finding['blocking']);
        $this->assertStringContainsString('public contact form', $finding['message']);

        // With reasons stored, they are counted too.
        ContactReason::create(['masjid_id' => $org->id, 'name' => 'Imam', 'is_active' => true, 'order' => 1]);

        $this->assertStringContainsString('1 contact reason', $this->findingFor($org, 'a', 'contact_requests')['message']);
    }

    #[Test]
    public function donate_off_with_money_attached_is_a_blocking_decision(): void
    {
        $org = $this->orgWithPivot('Muslim Education Center', [6 => false]);
        $this->fund($org, 'General', 'general');

        $finding = $this->findingFor($org, 'b', 'giving');

        $this->assertNotNull($finding);
        $this->assertTrue($finding['blocking']);
        $this->assertStringContainsString('1 active fund', $finding['message']);
    }

    #[Test]
    public function services_off_over_published_services_is_raised(): void
    {
        $org = $this->orgWithPivot('Muslim Education Center', [9 => false]);
        // `text` is NOT NULL with no default here too, same as announcements.
        Service::create([
            'masjid_id' => $org->id,
            'title' => 'Nikah',
            'description' => 'Marriage services',
            'text' => 'Marriage services',
        ]);

        $finding = $this->findingFor($org, 'a', 'services');

        $this->assertNotNull($finding);
        $this->assertStringContainsString('1 service', $finding['message']);
    }

    /**
     * An organisation with no pivot rows is the only case an installed build can
     * actually see change: `[]` today, eleven rows after. It is reported, and it
     * does not block — there is nothing for anybody to decide.
     */
    #[Test]
    public function an_organisation_with_no_pivot_rows_is_a_notice_not_a_blocker(): void
    {
        $org = $this->listedOrg('Fresh Organisation');

        $finding = $this->findingFor($org, 'c', null);

        $this->assertNotNull($finding);
        $this->assertFalse($finding['blocking']);
        $this->assertStringContainsString('11 rows', $finding['message']);

        $this->assertSame(0, $this->plan()['blocking']);
    }

    /**
     * A worship row off at a masjid — which production already looks like — is
     * listed so the owner sees it, and blocks nothing: there is no admin screen
     * behind those five.
     */
    #[Test]
    public function a_worship_row_off_at_a_masjid_is_a_notice_not_a_blocker(): void
    {
        $org = $this->orgWithPivot('Burlington Masjid', [1 => false, 2 => true]);

        $finding = $this->findingFor($org, 'd', 'quran');

        $this->assertNotNull($finding);
        $this->assertFalse($finding['blocking']);
        $this->assertStringContainsString('No admin screen', $finding['message']);

        $this->assertSame(0, $this->plan()['blocking'], 'Qur’an being off today must not block the cutover');
    }

    #[Test]
    public function an_owners_resolution_clears_a_blocking_decision(): void
    {
        $org = $this->orgWithPivot('Muslim Education Center', [10 => false]);
        $this->announcement($org);

        $this->assertSame(1, $this->plan()['blocking']);

        // The shape config/app_feature_cutover.php carries once the owner has
        // answered. The file does not exist yet, and its absence is the normal
        // early state, never an error.
        config(['app_feature_cutover' => [$org->id => ['announcements' => 'hide_everywhere']]]);

        $this->assertSame(0, $this->plan()['blocking']);

        // Still reported, so the record of what was decided does not vanish.
        $this->assertNotNull($this->findingFor($org, 'a', 'announcements'));
    }

    // ----------------------------------------------------------------- exit code

    #[Test]
    public function the_exit_code_reports_only_blocking_decisions(): void
    {
        $this->orgWithPivot('Burlington Masjid', [1 => false]);

        $this->assertSame(0, Artisan::call('app-features:cutover-plan', ['--json' => true]));

        $org = $this->orgWithPivot('Muslim Education Center', [10 => false]);
        $this->announcement($org);

        $this->assertSame(
            AppFeaturesCutoverPlan::EXIT_CONFLICTS,
            Artisan::call('app-features:cutover-plan', ['--json' => true])
        );
    }

    #[Test]
    public function an_unknown_organisation_is_refused_rather_than_planned(): void
    {
        $this->assertSame(1, Artisan::call('app-features:cutover-plan', ['--org' => 999999]));
    }

    // -------------------------------------------------------------------- helpers

    /** @param array<int, bool> $pivot legacy id => is_available */
    private function orgWithPivot(string $name, array $pivot): Masjid
    {
        $org = $this->listedOrg($name);

        foreach ($pivot as $featureId => $available) {
            MasjidMobileAppFeature::create([
                'masjid_id' => $org->id,
                'feature_id' => $featureId,
                'is_available' => $available,
            ]);
        }

        return $org->fresh();
    }

    /** One live announcement, which is what makes switching the module off a decision. */
    private function announcement(Masjid $org): Announcement
    {
        return Announcement::create([
            'masjid_id' => $org->id,
            'title' => 'Eid prayer',
            'details' => 'Eid prayer at 8am',
            // `text` is NOT NULL and has no default. It is unused by the
            // application (see the 2026-07-21 widening migration, which says
            // so), but the column is still there and still refuses a null, so
            // a fixture that omits it cannot insert a row at all.
            'text' => 'Eid prayer at 8am',
            'start_date' => now()->toDateString(),
        ]);
    }

    /** An active fund — money attached to Giving, which the plan must refuse to switch off. */
    private function fund(Masjid $org, string $name, string $type): Fund
    {
        return Fund::withoutMasjidScope()->create([
            'masjid_id' => $org->id,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function plan(array $options = []): array
    {
        Artisan::call('app-features:cutover-plan', $options + ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<int, array<string, mixed>> legacy id => row */
    private function rowsFor(Masjid $org): array
    {
        $plan = $this->plan(['--org' => $org->id]);

        $rows = [];

        foreach ($plan['plan'][0]['rows'] as $row) {
            $rows[$row['legacy_id']] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function findingFor(Masjid $org, string $class, ?string $key): ?array
    {
        foreach ($this->plan(['--org' => $org->id])['plan'][0]['findings'] as $finding) {
            if ($finding['class'] === $class && $finding['key'] === $key) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Every table the cutover could ever write, dumped in full.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $tables = [
            'masjid_mobile_app_features',
            'mobile_app_features',
            'masjid_capability_changes',
            'masjids',
            'donation_links',
            'announcements',
        ];

        $snapshot = [];

        foreach ($tables as $table) {
            // Rows as plain arrays, not stdClass: two reads of an unchanged
            // table return different OBJECTS, so a strict comparison of the
            // objects would fail whether or not anything was written.
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}
