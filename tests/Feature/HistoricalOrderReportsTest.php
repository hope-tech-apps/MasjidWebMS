<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\HistoricalOrder;
use App\Models\Masjid;
use App\Models\Registration;
use App\Models\User;
use App\Services\Receipts\AnnualStatementService;
use App\Services\Receipts\ReceiptService;
use App\Services\Registrations\RegistrationException;
use App\Services\Registrations\RegistrationService;
use App\Support\DonationMetrics;
use App\Support\ImpactMetrics;
use App\Support\ModuleFacts;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WritesWixOrderExport;
use Tests\TestCase;

/**
 * Imported Wix history never reads as money Manara processed
 * (DECISIONS.md 2026-09-25, "Wix order history").
 *
 * Every surface that totals or acts on donations and registrations is asked
 * once, with the synthetic Wix export imported beside one Stripe gift and one
 * offline gift Manara recorded itself:
 *
 *   giving dashboard (DonationMetrics), the ledger + CSV, receipts (issue and
 *   edit), annual statements, impact figures, the Giving module's facts, the
 *   contact record's totals, contact merge, and registration cancel.
 *
 * Each exclusion is on by default and history is reachable by name
 * (`source=historical`), so nothing is hidden from the organisation — it is
 * only never counted as Manara's.
 */
class HistoricalOrderReportsTest extends TestCase
{
    use RefreshDatabase;
    use WritesWixOrderExport;

    private Masjid $org;
    private User $admin;
    private Contact $amina;
    private Fund $fitr;
    private Donation $stripeGift;
    private Donation $offlineGift;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = $this->makeOrgForWixImport();
        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->org->user_id = $this->admin->id;
        $this->org->save();

        $this->amina = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Amina', 'last_name' => 'Test',
            'email' => 'amina@example.test',
        ]);

        // A LIVE, RECEIPTABLE fund of the same name: the import files the Wix
        // Zakat-ul-Fitr orders here, so every exclusion below has to come from
        // the gift's source and not from a fund that happens to issue nothing.
        $this->fitr = Fund::factory()->create([
            'masjid_id' => $this->org->id, 'name' => 'Zakat-ul-Fitr', 'type' => 'fitra',
            'receiptable' => true, 'is_active' => true,
        ]);

        $this->stripeGift = Donation::factory()->create([
            'masjid_id' => $this->org->id, 'fund_id' => $this->fitr->id, 'contact_id' => $this->amina->id,
            'source' => Donation::SOURCE_STRIPE, 'status' => 'succeeded', 'charged_amount' => 10000,
            'created_at' => Carbon::parse('2021-04-20 15:00:00', 'UTC'),
        ]);
        $this->offlineGift = Donation::factory()->create([
            'masjid_id' => $this->org->id, 'fund_id' => $this->fitr->id, 'contact_id' => $this->amina->id,
            'source' => Donation::SOURCE_OFFLINE, 'payment_method' => 'cash', 'status' => 'succeeded',
            'charged_amount' => 700, 'donated_at' => '2021-04-21',
        ]);

        $this->assertSame(0, $this->importWix($this->org, $this->writeWixExport(), ['--execute' => true, '--batch' => 'b1']));
        $this->assertSame(2, Donation::withoutMasjidScope()->where('source', Donation::SOURCE_HISTORICAL)->count());
    }

    protected function tearDown(): void
    {
        $this->removeWixExports();
        parent::tearDown();
    }

    #[Test]
    public function the_giving_dashboard_leaves_imported_history_out_unless_it_is_asked_for_by_name(): void
    {
        app(TenantContext::class)->set($this->org->id);
        $metrics = DonationMetrics::forMasjid($this->org);

        $this->assertSame(10700, $metrics->summary()['all_time']['gross_cents']);
        $this->assertSame(2, $metrics->summary()['all_time']['gift_count']);

        $fitr = collect($metrics->byFund())->firstWhere('fund_id', $this->fitr->id);
        $this->assertSame(10700, $fitr['gross_cents']);

        $history = $metrics->summary(['source' => Donation::SOURCE_HISTORICAL])['all_time'];
        $this->assertSame(4900, $history['gross_cents'], 'history is still there when asked for');
    }

    #[Test]
    public function the_ledger_and_its_csv_leave_history_out_by_default_and_show_it_by_name(): void
    {
        Sanctum::actingAs($this->admin);
        $base = "/api/admin/masjids/{$this->org->id}/donations";

        $ids = collect($this->getJson($base)->assertOk()->json('data.data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->stripeGift->id, $this->offlineGift->id], $ids);

        $history = $this->getJson("{$base}?source=historical")->assertOk()->json('data.data');
        $this->assertCount(2, $history);
        $this->assertSame([Donation::SOURCE_HISTORICAL], array_values(array_unique(array_column($history, 'source'))));

        $csv = $this->get("{$base}/export")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('historical', $csv);
    }

    #[Test]
    public function a_historical_gift_is_never_receipted_or_edited(): void
    {
        $gift = Donation::withoutMasjidScope()->where('source', Donation::SOURCE_HISTORICAL)->firstOrFail();

        $this->assertNull(app(ReceiptService::class)->issueFor($gift), 'the service declines, whoever asks');

        Sanctum::actingAs($this->admin);
        $base = "/api/admin/masjids/{$this->org->id}/donations/{$gift->id}";

        $this->postJson("{$base}/receipt")->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'old Wix site'));
        $this->putJson($base, ['note' => 'edited'])->assertStatus(422);

        $this->assertSame(0, $gift->receipt()->count());
        $this->assertStringNotContainsString('edited', (string) $gift->fresh()->note);
    }

    #[Test]
    public function annual_statements_leave_imported_history_out(): void
    {
        $statements = app(AnnualStatementService::class);

        $row = collect($statements->summaryForYear($this->org->id, 2021))->firstWhere('contact_id', $this->amina->id);
        $this->assertSame(10700, $row['total_eligible']);
        $this->assertSame(2, $row['gift_count']);

        $statement = $statements->forContact($this->org->id, $this->amina->id, 2021);
        $this->assertSame(10700, $statement['total_eligible']);
        $this->assertSame(2, $statement['gift_count']);
    }

    #[Test]
    public function impact_figures_leave_imported_history_out(): void
    {
        $values = collect(ImpactMetrics::forMasjid($this->org)->report()['metrics'])->pluck('value', 'key');

        $this->assertSame(10700, $values[ImpactMetrics::DONATIONS_TOTAL]);
        $this->assertSame(0, $values[ImpactMetrics::PROGRAM_FEES_COLLECTED] ?? 0);
        $this->assertSame(0, $values[ImpactMetrics::REGISTRATIONS_CONFIRMED] ?? 0);
    }

    #[Test]
    public function the_giving_module_does_not_count_an_imported_gift_as_recorded(): void
    {
        // A Zakat-ul-Fitr order from this March, well inside the last 12 months.
        $recent = Donation::withoutMasjidScope()->where('source', Donation::SOURCE_HISTORICAL)->firstOrFail();
        $recent->forceFill(['created_at' => now()->subMonths(6), 'donated_at' => now()->subMonths(6)->toDateString()])->save();
        $this->offlineGift->forceFill(['created_at' => now()->subMonths(2)])->save();

        $facts = ModuleFacts::for($this->org, 'giving');

        $this->assertContains('1 gift recorded in the last 12 months', $facts);
    }

    #[Test]
    public function the_contact_record_totals_history_apart_from_what_manara_recorded(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson("/api/admin/masjids/{$this->org->id}/contacts/{$this->amina->id}")
            ->assertOk()->json('data');

        $this->assertSame(10700, $data['giving_total']);
        $this->assertSame(4900, $data['historical_giving_total']);
        $this->assertCount(4, $data['donations'], 'the history is on the record, marked');
    }

    #[Test]
    public function merging_a_contact_carries_its_imported_orders_to_the_survivor(): void
    {
        Sanctum::actingAs($this->admin);

        $duplicate = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Amina', 'last_name' => 'Duplicate',
            'email' => 'amina.old@example.test',
        ]);
        $orderIds = HistoricalOrder::withoutMasjidScope()->where('contact_id', $this->amina->id)->pluck('id')->all();
        $this->assertNotEmpty($orderIds, 'the premise: the import linked orders to Amina');

        $this->postJson("/api/admin/masjids/{$this->org->id}/contacts/{$this->amina->id}/merge", [
            'target_contact_id' => $duplicate->id,
        ])->assertOk();

        $this->assertSame(
            count($orderIds),
            HistoricalOrder::withoutMasjidScope()->whereIn('id', $orderIds)->where('contact_id', $duplicate->id)->count(),
            'the orders follow the gifts they produced to the surviving contact'
        );
    }

    #[Test]
    public function a_historical_registration_cannot_be_cancelled(): void
    {
        $registration = Registration::withoutMasjidScope()
            ->where('source', Registration::SOURCE_HISTORICAL)
            ->where('status', Registration::STATUS_CONFIRMED)->firstOrFail();

        try {
            app(RegistrationService::class)->cancel($registration);
            $this->fail('a historical registration was cancelled');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('old Wix site', $e->getMessage());
        }

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->fresh()->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->fresh()->payment_status);
    }
}
