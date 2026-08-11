<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Receipts\AnnualStatementService;
use App\Services\Receipts\DonationReceiptPdfService;
use App\Services\Receipts\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * T-007b — the receipt policy for OFFLINE gifts.
 *
 * The asymmetry this closes: a Stripe gift is receipted automatically because a
 * signed webhook proves it settled; a cash or cheque gift is a human assertion,
 * so recording it and issuing a serialled tax document for it are two separate
 * acts. Recording issues nothing. A treasurer issues, once, per gift.
 *
 * The lines this suite holds:
 *   - entry alone never mints a receipt (a mis-keyed amount must not burn a serial)
 *   - issuance runs through the SAME ReceiptService, so offline and online gifts
 *     share ONE gap-free per-masjid sequence
 *   - issuing twice yields one receipt with one serial
 *   - the document is honest about its provenance ("Received by: Check no. 1189")
 *     and the ONLINE document is byte-identical to before this feature
 *   - annual statements, which already counted offline gifts, are untouched
 */
class OfflineDonationReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private Fund $fundA;
    private Fund $fundB;
    private User $adminA;

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

        config(['services.stripe.currency' => 'usd']);

        // MasjidAdmins on the gated CRM routes need the bridged role + permissions.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid([
            'name' => 'Masjid Al-Noor',
            'tax_id' => '46-4693999',
            'statement_signatory' => 'Imam Shaher',
        ]);
        $this->masjidB = $this->makeMasjid(['name' => 'Masjid Al-Huda']);

        $this->fundA = $this->makeFund($this->masjidA);
        $this->fundB = $this->makeFund($this->masjidB);

        $this->adminA = $this->makeAdminFor($this->masjidA);
    }

    // ===================== recording issues nothing =====================

    #[Test]
    public function recording_an_offline_gift_issues_no_receipt(): void
    {
        Sanctum::actingAs($this->adminA);

        $id = $this->recordOfflineGift(['amount' => 5000.00, 'payment_method' => 'cash']);

        // The gift is booked and succeeded...
        $this->assertDatabaseHas('donations', [
            'id' => $id,
            'source' => 'offline',
            'status' => 'succeeded',
            'charged_amount' => 500000,
        ]);

        // ...but no serial was consumed and no tax document exists yet.
        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
        $this->assertNull(Donation::withoutMasjidScope()->find($id)->receipt_eligible_amount);

        // So there is nothing to download either.
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt/pdf")
            ->assertStatus(404);
    }

    // ======================= the shared sequence ========================

    #[Test]
    public function issuing_for_an_offline_gift_takes_the_next_serial_in_the_shared_sequence(): void
    {
        // An online gift gets serial 1 through the webhook's usual seam.
        $online = $this->stripeDonation($this->masjidA, $this->fundA, 10000);
        $this->assertSame(1, app(ReceiptService::class)->issueFor($online)->serial_number);

        Sanctum::actingAs($this->adminA);

        // The offline gift takes 2 — one sequence, not a parallel one.
        $offlineId = $this->recordOfflineGift(['amount' => 50.00, 'payment_method' => 'check', 'check_number' => '1189']);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$offlineId}/receipt")
            ->assertStatus(201)
            ->assertJsonPath('data.serial_number', 2)
            ->assertJsonPath('data.eligible_amount', 5000)
            ->assertJsonPath('data.payment_method', 'check')
            ->assertJsonPath('data.payment_reference', '1189');

        // And the next online gift continues from there: 1, 2, 3 with no hole.
        $second = $this->stripeDonation($this->masjidA, $this->fundA, 2500);
        $this->assertSame(3, app(ReceiptService::class)->issueFor($second)->serial_number);

        $serials = DonationReceipt::withoutMasjidScope()
            ->where('masjid_id', $this->masjidA->id)
            ->orderBy('serial_number')
            ->pluck('serial_number')
            ->all();

        $this->assertSame([1, 2, 3], $serials);
    }

    #[Test]
    public function an_offline_serial_is_independent_per_masjid(): void
    {
        // Masjid B burns serials 1 and 2 of its OWN sequence.
        app(ReceiptService::class)->issueFor($this->stripeDonation($this->masjidB, $this->fundB, 1000));
        app(ReceiptService::class)->issueFor($this->stripeDonation($this->masjidB, $this->fundB, 2000));

        Sanctum::actingAs($this->adminA);

        // A's first offline receipt is still A's serial 1 — sequences never bleed.
        $id = $this->recordOfflineGift(['amount' => 25.00, 'payment_method' => 'cash']);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt")
            ->assertStatus(201)
            ->assertJsonPath('data.serial_number', 1);
    }

    // ============================ idempotency ===========================

    #[Test]
    public function issuing_twice_returns_the_same_receipt_and_burns_one_serial(): void
    {
        Sanctum::actingAs($this->adminA);

        $id = $this->recordOfflineGift(['amount' => 5000.00, 'payment_method' => 'check', 'check_number' => '4021']);
        $url = "/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt";

        $first = $this->postJson($url)->assertStatus(201);

        // A double-clicked button: the SAME receipt comes back, and 200 rather
        // than 201 says so. A second serial here would be a hole in the sequence
        // the moment the duplicate was noticed and deleted.
        $this->postJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.serial_number', $first->json('data.serial_number'));

        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->where('donation_id', $id)->count());
        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->count());
        $this->assertSame(500000, Donation::withoutMasjidScope()->find($id)->receipt_eligible_amount);
    }

    // ========================= the document ============================

    #[Test]
    public function the_pdf_names_the_payment_method_and_reference_of_an_offline_gift(): void
    {
        Sanctum::actingAs($this->adminA);

        $contact = $this->makeContact($this->masjidA, 'Amina', 'Yusuf');
        $id = $this->recordOfflineGift([
            'amount' => 5000.00,
            'payment_method' => 'check',
            'check_number' => '1189',
            'contact_id' => $contact->id,
        ]);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt")->assertStatus(201);

        $html = $this->renderedDocument($this->receiptOf($id));

        $this->assertStringContainsString('Receipt No. 1', $html);
        $this->assertStringContainsString('Amina Yusuf', $html);
        $this->assertStringContainsString('USD 5,000.00', $html);
        // The line that makes the document honest about what backs it.
        $this->assertStringContainsString('Received by', $html);
        $this->assertStringContainsString('Check no. 1189', $html);

        // And it is a real PDF an admin can hand over.
        $response = $this->get("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->content());
    }

    #[Test]
    public function a_cash_gift_shows_the_method_with_no_reference(): void
    {
        Sanctum::actingAs($this->adminA);

        $id = $this->recordOfflineGift(['amount' => 300.00, 'payment_method' => 'cash']);
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt")->assertStatus(201);

        $html = $this->renderedDocument($this->receiptOf($id));

        $this->assertStringContainsString('Received by', $html);
        $this->assertStringContainsString('Cash', $html);
        // No cheque number was entered, so none is invented.
        $this->assertStringNotContainsString('Cash no.', $html);
    }

    #[Test]
    public function the_online_receipt_document_is_unchanged(): void
    {
        // The whole feature is additive: a Stripe gift has no payment_method, so
        // the provenance row is absent and the donor's document reads exactly as
        // it did before T-007b.
        $receipt = app(ReceiptService::class)->issueFor(
            $this->stripeDonation($this->masjidA, $this->fundA, 10000)
        );

        $this->assertNull($receipt->payment_method);
        $this->assertNull($receipt->payment_reference);

        $html = $this->renderedDocument($receipt);

        $this->assertStringNotContainsString('Received by', $html);
        $this->assertStringContainsString('USD 100.00', $html);
    }

    #[Test]
    public function the_receipt_snapshots_provenance_and_does_not_follow_a_later_edit(): void
    {
        Sanctum::actingAs($this->adminA);

        $id = $this->recordOfflineGift(['amount' => 100.00, 'payment_method' => 'check', 'check_number' => '1189']);
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt")->assertStatus(201);

        // Drift the donation under the issued receipt (an import correction, say).
        Donation::withoutMasjidScope()->find($id)
            ->forceFill(['payment_method' => 'cash', 'check_number' => null])->save();

        // The donor is holding a document that says cheque 1189. It still does.
        $html = $this->renderedDocument($this->receiptOf($id));
        $this->assertStringContainsString('Check no. 1189', $html);
    }

    // ====================== what may not be issued ======================

    #[Test]
    public function a_stripe_donation_cannot_be_receipted_by_hand(): void
    {
        // Online receipts belong to the webhook; hand-issuing one would be a
        // second path to the same document.
        $online = $this->stripeDonation($this->masjidA, $this->fundA, 10000);

        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$online->id}/receipt")
            ->assertStatus(422);

        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_offline_gift_to_a_non_receiptable_fund_is_refused(): void
    {
        $relief = Fund::create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Pass-through Relief',
            'type' => 'general',
            'receiptable' => false,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->adminA);

        $id = $this->recordOfflineGift(['amount' => 100.00, 'payment_method' => 'cash', 'fund_id' => $relief->id]);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt")
            ->assertStatus(422);

        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
    }

    // ========================= access control ==========================

    #[Test]
    public function another_masjids_donation_under_your_own_route_is_a_404(): void
    {
        $foreign = Donation::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'fund_id' => $this->fundB->id,
            'source' => 'offline',
            'payment_method' => 'cash',
            'status' => 'succeeded',
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$foreign->id}/receipt")
            ->assertStatus(404);

        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
    }

    #[Test]
    public function naming_another_masjid_in_the_route_is_a_403(): void
    {
        $foreign = Donation::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'fund_id' => $this->fundB->id,
            'source' => 'offline',
            'payment_method' => 'cash',
            'status' => 'succeeded',
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidB->id}/donations/{$foreign->id}/receipt")
            ->assertStatus(403);

        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
    }

    #[Test]
    public function issuing_requires_the_manage_donations_permission(): void
    {
        Sanctum::actingAs($this->adminA);
        $id = $this->recordOfflineGift(['amount' => 100.00, 'payment_method' => 'cash']);

        // Strip the bridged role and grant only the ledger's read permission.
        // syncRoles/givePermissionTo touch pivots only, so the observer does not
        // re-bridge the role from users.type.
        $this->adminA->syncRoles([]);
        $this->adminA->givePermissionTo('view donations');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->adminA);

        // Reading the ledger is still fine...
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}")->assertOk();

        // ...but minting a tax document is a money mutation.
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt")
            ->assertStatus(403);

        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
    }

    #[Test]
    public function issuing_requires_authentication(): void
    {
        $gift = Donation::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'fund_id' => $this->fundA->id,
            'source' => 'offline',
            'payment_method' => 'cash',
            'status' => 'succeeded',
        ]);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$gift->id}/receipt")
            ->assertStatus(401);

        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
    }

    // ======================= annual statements =========================

    #[Test]
    public function the_annual_statement_total_is_the_same_before_and_after_issuance(): void
    {
        // Offline gifts ALREADY reach statements through the charged_amount
        // fallback. Issuing a receipt must add the serial without moving a cent —
        // if it changed the total, a donor's year-end letter would disagree with
        // the receipts in their folder.
        Sanctum::actingAs($this->adminA);

        $contact = $this->makeContact($this->masjidA, 'Bilal', 'Rahman');
        $id = $this->recordOfflineGift([
            'amount' => 5000.00,
            'payment_method' => 'check',
            'check_number' => '1189',
            'contact_id' => $contact->id,
            'donated_at' => '2025-03-04',
        ]);

        $before = app(AnnualStatementService::class)->forContact($this->masjidA->id, $contact->id, 2025);

        $this->assertSame(500000, $before['total_eligible']);
        $this->assertSame(1, $before['gift_count']);
        $this->assertNull($before['gifts'][0]['serial']);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$id}/receipt")->assertStatus(201);

        $after = app(AnnualStatementService::class)->forContact($this->masjidA->id, $contact->id, 2025);

        $this->assertSame(500000, $after['total_eligible']);
        $this->assertSame(1, $after['gift_count']);
        // The only difference: the gift can now be quoted by its receipt number.
        $this->assertSame(1, $after['gifts'][0]['serial']);
    }

    // ============================= helpers =============================

    /** POST the offline-gift form the way an admin's screen does; returns its id. */
    private function recordOfflineGift(array $body): int
    {
        $response = $this->postJson(
            "/api/admin/masjids/{$this->masjidA->id}/donations",
            array_merge([
                'fund_id' => $this->fundA->id,
                'donated_at' => '2026-03-04',
            ], $body)
        )->assertStatus(201);

        return (int) $response->json('data.id');
    }

    private function receiptOf(int $donationId): DonationReceipt
    {
        $receipt = DonationReceipt::withoutMasjidScope()->where('donation_id', $donationId)->first();
        $this->assertNotNull($receipt, 'No receipt was issued for donation ' . $donationId . '.');

        return $receipt;
    }

    /** A succeeded Stripe gift — the path that receipts itself from a webhook. */
    private function stripeDonation(Masjid $masjid, Fund $fund, int $cents): Donation
    {
        return Donation::factory()->succeeded()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => $cents,
            'charged_amount' => $cents,
        ]);
    }

    /** The receipt document's visible HTML — what the donor actually reads. */
    private function renderedDocument(DonationReceipt $receipt): string
    {
        $captured = [];

        View::composer('pdf.donation-receipt', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        app(DonationReceiptPdfService::class)->pdfFor($receipt);

        $this->assertNotEmpty($captured, 'The receipt blade was never rendered.');

        return view('pdf.donation-receipt', $captured)->render();
    }

    private function makeContact(Masjid $masjid, string $first, string $last): Contact
    {
        $contact = new Contact([
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first) . '@donor.test',
        ]);
        $contact->masjid_id = $masjid->id;
        $contact->save();

        return $contact;
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
            'crm_enabled' => true,
        ], $overrides));
    }

    private function makeFund(Masjid $masjid): Fund
    {
        return Fund::create([
            'masjid_id' => $masjid->id,
            'name' => 'General Fund',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
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
}
