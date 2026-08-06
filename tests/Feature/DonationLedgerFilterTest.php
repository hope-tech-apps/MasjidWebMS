<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The donation ledger's filter contract
 * (DonationsController::filteredQuery) and the CSV export that shares it.
 *
 * Three things are pinned here, all of them things a treasurer would only
 * discover after acting on a wrong number:
 *
 *   1. A malformed date is REJECTED. A `from` that degraded into "no filter"
 *      would hand an accountant a report covering years they never asked for,
 *      and it would look perfectly plausible.
 *   2. A `to` with no `from` is ACCEPTED. Laravel resolves a missing
 *      `after_or_equal:from` reference to "now", so the naive rule 422s an
 *      open-ended "everything up to 2024" export — the filter is only compared
 *      end-to-end when both ends were actually given.
 *   3. An offline gift is filtered by WHEN IT WAS GIVEN (donated_at), not when
 *      it was keyed in (created_at). Imported history lands in its own year.
 *
 * And the export is checked against the ledger for the SAME query string, since
 * the whole reason they share one query builder is that the file the accountant
 * downloads must never disagree with the page the admin is looking at.
 *
 * Sqlite-in-memory + RefreshDatabase; rows are seeded with the tenant context
 * UNBOUND so the explicit masjid_id is honored (mirrors DonationReadTest).
 */
class DonationLedgerFilterTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private Fund $fundA;

    /** Given 2024-01-05, keyed in 2026-08-05 — the row the year windows disagree about. */
    private Donation $offlineGift;

    private Donation $stripeMarch2026;
    private Donation $stripeJuly2026;
    private Donation $pending2026;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Seed roles/permissions BEFORE the admins so each MasjidAdmin is bridged
        // to the masjid-admin role (which holds `view donations`) on save.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->fundA = Fund::factory()->create(['masjid_id' => $this->masjidA->id]);
        $fundB = Fund::factory()->create(['masjid_id' => $this->masjidB->id]);

        // Offline history: the gift date and the entry date are two years apart,
        // which is exactly the case the ledger has to get right.
        $this->offlineGift = $this->makeGift([
            'source' => 'offline',
            'payment_method' => 'cash',
            'donated_at' => '2024-01-05',
            'created_at' => Carbon::parse('2026-08-05 12:00:00', 'UTC'),
            'charged_amount' => 2500,
        ]);

        // Stripe gifts leave donated_at null, so they are dated by created_at.
        $this->stripeMarch2026 = $this->makeGift([
            'created_at' => Carbon::parse('2026-03-11 15:00:00', 'UTC'),
            'charged_amount' => 5000,
        ]);

        $this->stripeJuly2026 = $this->makeGift([
            'created_at' => Carbon::parse('2026-07-20 15:00:00', 'UTC'),
            'charged_amount' => 7500,
        ]);

        // Not money received — present so the `status` filter has something to cut.
        $this->pending2026 = $this->makeGift([
            'status' => 'pending',
            'created_at' => Carbon::parse('2026-07-21 15:00:00', 'UTC'),
            'charged_amount' => 9900,
        ]);

        // Masjid B's gift: must never appear in A's ledger OR A's export.
        Donation::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'fund_id' => $fundB->id,
            'status' => 'succeeded',
            'source' => 'stripe',
            'created_at' => Carbon::parse('2026-03-12 15:00:00', 'UTC'),
        ]);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /** A succeeded stripe gift to masjid A unless the caller says otherwise. */
    private function makeGift(array $attributes): Donation
    {
        return Donation::factory()->create(array_merge([
            'masjid_id' => $this->masjidA->id,
            'fund_id' => $this->fundA->id,
            'status' => 'succeeded',
            'source' => 'stripe',
        ], $attributes));
    }

    private function ledgerUrl(string $queryString): string
    {
        return "/api/admin/masjids/{$this->masjidA->id}/donations{$queryString}";
    }

    private function exportUrl(string $queryString): string
    {
        return "/api/admin/masjids/{$this->masjidA->id}/donations/export{$queryString}";
    }

    /** @return array<int,int> The donation ids on the returned ledger page. */
    private function ledgerIds(array $json): array
    {
        return array_map(
            fn ($row) => (int) $row['id'],
            $json['data']['data'] ?? []
        );
    }

    /**
     * Data rows in a streamed CSV, header excluded. Parsed with fgetcsv rather
     * than counting newlines so a quoted note containing a line break cannot be
     * miscounted as two rows.
     */
    private function csvDataRowCount(string $csv): int
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $records = 0;

        while (($row = fgetcsv($handle)) !== false) {
            // fgetcsv yields [null] for a blank trailing line.
            if ($row === [null]) {
                continue;
            }

            $records++;
        }

        fclose($handle);

        // Minus the header line the export always writes first.
        return $records - 1;
    }

    // ---------- filter validation ----------

    #[Test]
    public function a_from_that_is_not_a_date_is_rejected_rather_than_ignored(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->ledgerUrl('?from=banana'))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['from']]);
    }

    #[Test]
    public function the_export_rejects_the_same_malformed_date(): void
    {
        Sanctum::actingAs($this->adminA);

        // Same filter contract, same 422 — a bad date must not stream a file.
        $this->getJson($this->exportUrl('?from=banana'))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function a_to_only_filter_is_accepted(): void
    {
        Sanctum::actingAs($this->adminA);

        // `to` in the past with no `from`: the naive `after_or_equal:from` rule
        // resolves the missing reference to "now" and 422s this. It must not.
        $response = $this->getJson($this->ledgerUrl('?to=2024-12-31&per_page=100'))
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame([$this->offlineGift->id], $this->ledgerIds($response->json()));
    }

    #[Test]
    public function a_reversed_range_is_still_rejected_when_both_ends_are_given(): void
    {
        Sanctum::actingAs($this->adminA);

        // Relaxing the rule for a missing `from` must not relax it when the admin
        // actually typed both ends.
        $this->getJson($this->ledgerUrl('?from=2026-12-31&to=2026-01-01'))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    }

    // ---------- offline gifts are dated by donated_at ----------

    #[Test]
    public function an_offline_gift_is_inside_the_window_of_the_year_it_was_given(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->ledgerUrl('?from=2024-01-01&to=2024-12-31&per_page=100'))
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertContains(
            $this->offlineGift->id,
            $this->ledgerIds($response->json()),
            'A gift donated 2024-01-05 belongs in the 2024 window.'
        );
    }

    #[Test]
    public function an_offline_gift_is_outside_the_window_of_the_year_it_was_imported(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->ledgerUrl('?from=2026-01-01&to=2026-12-31&per_page=100'))
            ->assertOk();

        $ids = $this->ledgerIds($response->json());

        $this->assertNotContains(
            $this->offlineGift->id,
            $ids,
            'created_at 2026-08-05 is when it was TYPED IN; the 2026 window must not claim it.'
        );

        // The three gifts actually dated 2026 are all there.
        $this->assertSame(3, $response->json('data.total'));
        $this->assertContains($this->stripeMarch2026->id, $ids);
        $this->assertContains($this->stripeJuly2026->id, $ids);
        $this->assertContains($this->pending2026->id, $ids);
    }

    // ---------- export == ledger ----------

    #[Test]
    public function the_export_returns_the_same_row_count_as_the_ledger_total(): void
    {
        Sanctum::actingAs($this->adminA);

        // A query string that actually cuts: the pending gift is excluded by
        // status, masjid B's gift by the tenant scope.
        $queryString = '?status=succeeded&from=2024-01-01&to=2026-12-31&per_page=100';

        $ledgerTotal = $this->getJson($this->ledgerUrl($queryString))
            ->assertOk()
            ->json('data.total');

        // Guard against a vacuous pass: 0 == 0 would prove nothing.
        $this->assertSame(3, $ledgerTotal);

        $csv = $this->get($this->exportUrl($queryString))
            ->assertOk()
            ->streamedContent();

        $this->assertSame($ledgerTotal, $this->csvDataRowCount($csv));
    }

    #[Test]
    public function the_export_matches_the_ledger_for_a_narrow_date_window_too(): void
    {
        Sanctum::actingAs($this->adminA);

        // The window where the ONLY row is the offline gift, dated by donated_at.
        $queryString = '?from=2024-01-01&to=2024-12-31&per_page=100';

        $ledgerTotal = $this->getJson($this->ledgerUrl($queryString))
            ->assertOk()
            ->json('data.total');

        $this->assertSame(1, $ledgerTotal);

        $csv = $this->get($this->exportUrl($queryString))
            ->assertOk()
            ->streamedContent();

        $this->assertSame($ledgerTotal, $this->csvDataRowCount($csv));

        // The one exported row is dated by when it was GIVEN, not entered.
        $this->assertStringContainsString('2024-01-05', $csv);
        $this->assertStringNotContainsString('2026-08-05', $csv);
    }
}
