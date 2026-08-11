<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Receipts\DonationReceiptPdfService;
use App\Services\Receipts\ReceiptService;
use App\Services\Receipts\StatementLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The per-donation receipt as a PDF: rendered from the STORED receipt row,
 * attached to the donor's receipt email, and downloadable by an admin.
 *
 * The line this suite holds: the PDF is a rendering of the receipt, never a
 * second opinion about it. Serial, issue date and every amount come off
 * donation_receipts — if a figure were recomputed, a donor could end up holding
 * two tax documents that disagree. `the_pdf_reads_the_stored_row_and_never_recomputes`
 * is the test that proves it.
 *
 * Issuance itself is NOT re-tested here — DonationFlowTest owns the gap-free
 * serial + idempotency guarantees, and this feature must not perturb them.
 */
class DonationReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_secret';

    private Masjid $masjidA;
    private Masjid $masjidB;
    private Fund $fundA;
    private Fund $fundB;

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

        config([
            'services.stripe.webhook_secret' => $this->webhookSecret,
            'services.stripe.currency' => 'usd',
        ]);

        // MasjidAdmins on the CRM routes need the bridged role + permissions.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid([
            'name' => 'Masjid Al-Noor',
            'tax_id' => '46-4693999',
            'statement_signatory' => 'Imam Shaher',
        ]);
        $this->masjidB = $this->makeMasjid(['name' => 'Masjid Al-Huda']);

        $this->fundA = $this->makeFund($this->masjidA);
        $this->fundB = $this->makeFund($this->masjidB);
    }

    // ============================ rendering ============================

    #[Test]
    public function the_service_renders_a_non_empty_pdf_for_an_issued_receipt(): void
    {
        $receipt = $this->issueReceiptFor($this->donationFor($this->masjidA, $this->fundA, 10000));

        $pdf = app(DonationReceiptPdfService::class)->pdfFor($receipt);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
        $this->assertSame('donation-receipt-1.pdf', app(DonationReceiptPdfService::class)->filename($receipt));
    }

    #[Test]
    public function the_pdf_carries_the_serial_donor_fund_amounts_and_the_jurisdiction_line(): void
    {
        $contact = $this->makeContact($this->masjidA, 'Amina', 'Yusuf');
        $receipt = $this->issueReceiptFor(
            $this->donationFor($this->masjidA, $this->fundA, 12500, ['contact_id' => $contact->id])
        );

        $html = $this->renderedDocument($receipt);

        $this->assertStringContainsString('Receipt No. 1', $html);
        $this->assertStringContainsString('Amina Yusuf', $html);
        $this->assertStringContainsString('General Fund', $html);
        $this->assertStringContainsString('USD 125.00', $html);   // gross and eligible
        $this->assertStringContainsString('Masjid Al-Noor', $html);
        $this->assertStringContainsString('46-4693999', $html);   // EIN off the letterhead
        $this->assertStringContainsString('section 501(c)(3)', $html);
        $this->assertStringContainsString('US Federal tax ID number', $html);
        $this->assertStringContainsString('Imam Shaher', $html);
    }

    #[Test]
    public function the_pdf_reads_the_stored_row_and_never_recomputes(): void
    {
        $donation = $this->donationFor($this->masjidA, $this->fundA, 10000);
        $receipt = $this->issueReceiptFor($donation);

        // Drift the donation out from under the receipt. A renderer that
        // recomputed from the donation would print $999.99; the receipt row is
        // the tax record, so the document must still say $100.00.
        $donation->forceFill(['charged_amount' => 99999])->save();

        $data = $this->documentData($receipt->fresh());

        $this->assertSame('100.00', $data['grossAmount']);
        $this->assertSame('100.00', $data['eligibleAmount']);
        $this->assertSame('0.00', $data['advantageAmount']);
        $this->assertSame(1, $data['serial']);
        $this->assertSame('US', $data['jurisdiction']);
    }

    #[Test]
    public function the_annual_statement_letter_still_renders_on_the_shared_letterhead(): void
    {
        // The letterhead (logo/address/EIN/signatory) is shared with the year-end
        // letter; this is the guard that the receipt PDF did not disturb it.
        $contact = $this->makeContact($this->masjidA, 'Bilal', 'Rahman');
        $donation = $this->donationFor($this->masjidA, $this->fundA, 20000, [
            'contact_id' => $contact->id,
            'donated_at' => '2025-03-04',
        ]);
        $this->issueReceiptFor($donation);

        $pdf = app(StatementLetterService::class)->pdfFor($this->masjidA->id, $contact->id, 2025);

        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    // ========================= admin download ==========================

    #[Test]
    public function an_admin_downloads_the_receipt_pdf_with_private_no_store_headers(): void
    {
        $donation = $this->donationFor($this->masjidA, $this->fundA, 10000);
        $this->issueReceiptFor($donation);

        Sanctum::actingAs($this->makeAdminFor($this->masjidA));

        $response = $this->get(
            "/api/admin/masjids/{$this->masjidA->id}/donations/{$donation->id}/receipt/pdf"
        )->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        // A tax document naming a donor: no proxy copy, no disk copy. Symfony
        // re-orders Cache-Control alphabetically, so assert the directives.
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertStringContainsString('donation-receipt-1.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->content());
    }

    #[Test]
    public function downloading_a_donation_that_never_issued_a_receipt_is_a_404(): void
    {
        // Offline gifts issue no receipt today, so there is nothing to render.
        $donation = $this->donationFor($this->masjidA, $this->fundA, 10000, ['source' => 'offline']);

        Sanctum::actingAs($this->makeAdminFor($this->masjidA));

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$donation->id}/receipt/pdf")
            ->assertStatus(404);
    }

    #[Test]
    public function another_masjids_donation_under_your_own_route_is_a_404(): void
    {
        $foreign = $this->donationFor($this->masjidB, $this->fundB, 10000);
        $this->issueReceiptFor($foreign);

        Sanctum::actingAs($this->makeAdminFor($this->masjidA));

        // Masjid A's admin, masjid A's route, masjid B's donation id: the
        // tenant-scoped lookup finds nothing rather than handing over B's donor.
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$foreign->id}/receipt/pdf")
            ->assertStatus(404);
    }

    #[Test]
    public function naming_another_masjid_in_the_route_is_a_403(): void
    {
        $foreign = $this->donationFor($this->masjidB, $this->fundB, 10000);
        $this->issueReceiptFor($foreign);

        Sanctum::actingAs($this->makeAdminFor($this->masjidA));

        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/donations/{$foreign->id}/receipt/pdf")
            ->assertStatus(403);
    }

    #[Test]
    public function the_download_requires_authentication(): void
    {
        $donation = $this->donationFor($this->masjidA, $this->fundA, 10000);
        $this->issueReceiptFor($donation);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$donation->id}/receipt/pdf")
            ->assertStatus(401);
    }

    // ======================= emailed attachment ========================

    #[Test]
    public function the_receipt_email_carries_the_pdf_as_an_attachment(): void
    {
        $donation = $this->donationFor($this->masjidA, $this->fundA, 10000, ['status' => 'pending']);

        $this->postWebhook($this->checkoutCompletedEvent($donation))->assertOk();

        $attachment = $this->firstMailAttachment();

        $this->assertSame('application', $attachment->getMediaType());
        $this->assertSame('pdf', $attachment->getMediaSubtype());
        $this->assertSame('donation-receipt-1.pdf', $attachment->getFilename());
        $this->assertStringStartsWith('%PDF-', $attachment->getBody());
    }

    #[Test]
    public function the_attachment_does_not_change_issuance_or_delivery_marking(): void
    {
        $donation = $this->donationFor($this->masjidA, $this->fundA, 10000, ['status' => 'pending']);

        $this->postWebhook($this->checkoutCompletedEvent($donation, ['event_id' => 'evt_1']))->assertOk();
        // A redelivery of the same gift under a different event id.
        $this->postWebhook($this->checkoutCompletedEvent($donation, ['event_id' => 'evt_2']))->assertOk();

        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count());
        $this->assertNotNull($donation->fresh()->receipt_delivered_at);
        // Delivered exactly once — the second event must not re-send.
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    // ============================= helpers =============================

    /** The Symfony attachment on the first message the array transport captured. */
    private function firstMailAttachment(): \Symfony\Component\Mime\Part\DataPart
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'No receipt mail was sent.');

        $attachments = $messages->first()->getOriginalMessage()->getAttachments();
        $this->assertCount(1, $attachments, 'The receipt email carried no attachment.');

        return $attachments[0];
    }

    /** The blade data the PDF was rendered from, captured as the view composes. */
    private function documentData(DonationReceipt $receipt): array
    {
        $captured = [];

        View::composer('pdf.donation-receipt', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        app(DonationReceiptPdfService::class)->pdfFor($receipt);

        $this->assertNotEmpty($captured, 'The receipt blade was never rendered.');

        return $captured;
    }

    /** The receipt document's visible HTML — what the donor actually reads. */
    private function renderedDocument(DonationReceipt $receipt): string
    {
        return view('pdf.donation-receipt', $this->documentData($receipt))->render();
    }

    private function issueReceiptFor(Donation $donation): DonationReceipt
    {
        $receipt = app(ReceiptService::class)->issueFor($donation);
        $this->assertNotNull($receipt, 'The fixture donation did not issue a receipt.');

        return $receipt;
    }

    private function donationFor(Masjid $masjid, Fund $fund, int $cents, array $overrides = []): Donation
    {
        return Donation::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'status' => 'succeeded',
        ], $overrides));
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

    /** Post a Stripe-signed event to the webhook, signing exactly like Stripe. */
    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );
    }

    private function checkoutCompletedEvent(Donation $donation, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $o['id'] ?? 'cs_' . uniqid(),
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'payment_intent' => $o['payment_intent'] ?? ('pi_' . uniqid()),
                    'client_reference_id' => $donation->uuid,
                    'metadata' => ['donation_uuid' => $donation->uuid],
                    // Seeds the donor Contact, which is who the receipt is mailed to.
                    'customer_details' => ['email' => 'donor@test.local', 'name' => 'Amina Yusuf'],
                ],
            ],
        ];
    }
}
