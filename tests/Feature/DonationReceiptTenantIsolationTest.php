<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Receipts\ReceiptService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for DonationReceipt — the cross-tenant test
 * .claude/rules/tenant-scoping.md makes mandatory for every BelongsToMasjid
 * model, which this one shipped without.
 *
 * A receipt is the most consequential row in the CRM: it is a serialled tax
 * document, and its serial belongs to a GAP-FREE PER-MASJID sequence that a
 * regulator may audit. Two claims therefore have to hold at once, and they pull
 * in opposite directions:
 *
 *   - a bound admin sees only their own organization's receipts (the global
 *     scope), and
 *   - the ISSUER runs UNBOUND — receipts are minted from the Stripe webhook,
 *     which has no tenant — so ReceiptService reads and writes across the
 *     boundary on purpose, via the documented `withoutMasjidScope()` bypass.
 *
 * The second is what makes the first worth testing at the model layer rather
 * than only through a controller: the code that allocates serials is the code
 * that ignores the scope, so if the two organizations' sequences were ever to
 * bleed, nothing above would notice.
 *
 * There is deliberately NO update or delete route for a receipt — the document a
 * donor already filed is immutable — so "A cannot update or delete B's row" is
 * pinned here as the absence of any such verb, not as a 404.
 *
 * The receipt-PDF download's own 404/403 pair is held by DonationReceiptPdfTest
 * and the issuance pair by OfflineDonationReceiptTest; this file restates the
 * read refusal compactly and adds what neither covers — the model scope, the
 * per-masjid sequence, the unbound issuer, and the ledger's `receipt` eager load.
 */
class DonationReceiptTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private User $adminA;

    private Fund $fundA;
    private Fund $fundB;

    private Donation $giftA;
    private Donation $giftB;

    private DonationReceipt $receiptA;
    private DonationReceipt $receiptB;

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

        config(['services.stripe.currency' => 'usd']);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        // Unbound is the ISSUER's context, and the default for this suite.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->fundA = $this->makeFund($this->masjidA);
        $this->fundB = $this->makeFund($this->masjidB);

        // Both organizations issue their FIRST receipt. Same serial (1), same
        // amount: a leak cannot hide behind a distinguishing value.
        $this->giftA = $this->makeSucceededGift($this->masjidA, $this->fundA, 10000);
        $this->giftB = $this->makeSucceededGift($this->masjidB, $this->fundB, 10000);

        $this->receiptA = app(ReceiptService::class)->issueFor($this->giftA);
        $this->receiptB = app(ReceiptService::class)->issueFor($this->giftB);
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

    private function makeFund(Masjid $masjid): Fund
    {
        return Fund::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => 'General',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
    }

    /** A settled Stripe gift — the shape the webhook receipts. */
    private function makeSucceededGift(Masjid $masjid, Fund $fund, int $cents): Donation
    {
        return Donation::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'type' => 'one_time',
            'source' => 'stripe',
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => 'usd',
            'status' => 'succeeded',
            'idempotency_key' => 'gift_' . uniqid(),
        ]);
    }

    /** An offline gift a treasurer recorded — the shape issueReceipt accepts. */
    private function makeOfflineGift(Masjid $masjid, Fund $fund, int $cents): Donation
    {
        return Donation::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'type' => 'one_time',
            'source' => 'offline',
            'payment_method' => 'cash',
            'donated_at' => '2026-03-01',
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => 'usd',
            'status' => 'succeeded',
            'idempotency_key' => 'gift_' . uniqid(),
        ]);
    }

    private function donationsUrl(Masjid $masjid): string
    {
        return "/api/admin/masjids/{$masjid->id}/donations";
    }

    // =============================================================== model layer

    #[Test]
    public function receipt_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, DonationReceipt::count());
        $this->assertSame($this->receiptA->id, DonationReceipt::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_receipt(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(DonationReceipt::find($this->receiptB->id));
    }

    #[Test]
    public function a_bound_tenant_cannot_find_another_organizations_receipt_by_its_serial(): void
    {
        // Both organizations hold a receipt numbered 1. Looking one up by serial
        // — which is how a donor's enquiry arrives ("receipt no. 1") — must
        // answer with this organization's document and nothing else.
        $this->tenant->set($this->masjidA->id);

        $ids = DonationReceipt::where('serial_number', 1)->pluck('id')->all();

        $this->assertSame([$this->receiptA->id], $ids);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_receipts(): void
    {
        // This is the ISSUER's view, and it must stay this way: the webhook has
        // no tenant and still has to reach the masjid the gift belongs to.
        $this->assertSame(2, DonationReceipt::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, DonationReceipt::withoutMasjidScope()->count());
    }

    #[Test]
    public function receipt_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $gift = $this->makeSucceededGift($this->masjidA, $this->fundA, 2500);

        $this->tenant->set($this->masjidA->id);

        $receipt = DonationReceipt::create([
            // A caller trying to file the document under masjid B.
            'masjid_id' => $this->masjidB->id,
            'donation_id' => $gift->id,
            'serial_number' => 99,
            'issue_date' => '2026-03-01',
            'gross_amount' => 2500,
            'advantage_amount' => 0,
            'eligible_amount' => 2500,
            'currency' => 'usd',
        ]);

        $this->assertSame($this->masjidA->id, $receipt->masjid_id);
    }

    #[Test]
    public function the_serial_sequence_is_per_masjid_so_serial_one_exists_in_both(): void
    {
        $this->assertSame(1, (int) $this->receiptA->serial_number);
        $this->assertSame(1, (int) $this->receiptB->serial_number);

        $this->assertSame(
            2,
            DonationReceipt::withoutMasjidScope()->where('serial_number', 1)->count()
        );
    }

    #[Test]
    public function a_duplicate_serial_inside_one_masjid_is_refused_by_the_database(): void
    {
        // The application allocates serials, but the unique (masjid_id,
        // serial_number) is the backstop the gap-free claim actually rests on.
        $gift = $this->makeSucceededGift($this->masjidA, $this->fundA, 2500);

        $this->expectException(QueryException::class);

        DonationReceipt::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidA->id,
            'donation_id' => $gift->id,
            'serial_number' => 1,          // already A's
            'issue_date' => '2026-03-01',
            'gross_amount' => 2500,
            'advantage_amount' => 0,
            'eligible_amount' => 2500,
            'currency' => 'usd',
        ]);
    }

    #[Test]
    public function a_donations_receipt_relation_never_crosses_tenants(): void
    {
        $this->tenant->set($this->masjidA->id);

        // Read B's gift through the bypass (as reporting code would), then walk
        // to its receipt with the tenant bound to A. The relation is scoped, so
        // A gets nothing rather than B's tax document.
        $foreign = Donation::withoutMasjidScope()->find($this->giftB->id);

        $this->assertNull($foreign->receipt()->first());
    }

    #[Test]
    public function the_unbound_issuer_files_the_receipt_under_the_gifts_masjid(): void
    {
        // The webhook path: no tenant is bound, so the creating hook is a no-op
        // and ReceiptService's explicit masjid_id is the only thing deciding
        // which organization's sequence this document joins.
        $gift = $this->makeSucceededGift($this->masjidB, $this->fundB, 7500);

        $receipt = app(ReceiptService::class)->issueFor($gift);

        $this->assertSame($this->masjidB->id, $receipt->masjid_id);
        $this->assertSame(2, (int) $receipt->serial_number);   // B's own 1, 2 — not A's

        // And A still has exactly one receipt, still numbered 1.
        $this->tenant->set($this->masjidA->id);
        $this->assertSame(1, DonationReceipt::count());
        $this->assertSame(1, (int) DonationReceipt::first()->serial_number);
    }

    // ================================================================ HTTP layer

    #[Test]
    public function the_ledger_discloses_only_the_admins_own_receipts(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->donationsUrl($this->masjidA))->assertOk();

        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->giftA->id, $rows[0]['id']);
        $this->assertSame($this->receiptA->id, $rows[0]['receipt']['id']);
    }

    #[Test]
    public function reading_another_masjids_receipt_through_your_own_route_is_a_404(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->donationsUrl($this->masjidA) . "/{$this->giftB->id}/receipt/pdf")
            ->assertStatus(404);
    }

    #[Test]
    public function naming_another_masjid_in_the_route_is_a_403(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->donationsUrl($this->masjidB) . "/{$this->giftB->id}/receipt/pdf")
            ->assertStatus(403);
    }

    #[Test]
    public function issuing_cannot_mint_a_receipt_against_another_masjids_gift(): void
    {
        $foreign = $this->makeOfflineGift($this->masjidB, $this->fundB, 500000);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->donationsUrl($this->masjidA) . "/{$foreign->id}/receipt")
            ->assertStatus(404);

        // No document was filed for B — and no serial was burned in A either,
        // which is the half a bare status assertion would miss: a consumed and
        // then discarded serial is exactly the hole regulators look for.
        $this->assertSame(
            0,
            DonationReceipt::withoutMasjidScope()->where('donation_id', $foreign->id)->count()
        );
        $this->assertSame(
            1,
            DonationReceipt::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->count()
        );
    }

    #[Test]
    public function issuing_takes_the_next_serial_in_the_admins_own_sequence(): void
    {
        // B races ahead to serial 5. A's next document is still A's 2.
        for ($i = 0; $i < 4; $i++) {
            app(ReceiptService::class)->issueFor(
                $this->makeSucceededGift($this->masjidB, $this->fundB, 1000)
            );
        }

        $own = $this->makeOfflineGift($this->masjidA, $this->fundA, 25000);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->donationsUrl($this->masjidA) . "/{$own->id}/receipt")
            ->assertStatus(201)
            ->assertJsonPath('data.serial_number', 2)
            ->assertJsonPath('data.masjid_id', $this->masjidA->id);
    }

    #[Test]
    public function a_receipt_has_no_update_or_delete_verb_at_all(): void
    {
        // The strongest form of "A cannot edit or destroy B's receipt": nobody
        // can edit or destroy ANY receipt over HTTP. A donor's filed document is
        // immutable, and a void is a new row, never an UPDATE of the old one.
        Sanctum::actingAs($this->adminA);

        $url = $this->donationsUrl($this->masjidA) . "/{$this->giftA->id}/receipt";

        $this->putJson($url, ['eligible_amount' => 1])->assertStatus(405);
        $this->deleteJson($url)->assertStatus(405);

        $this->assertDatabaseHas('donation_receipts', [
            'id' => $this->receiptA->id,
            'eligible_amount' => 10000,
        ]);
    }
}
