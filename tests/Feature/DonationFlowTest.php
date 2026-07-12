<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Stripe\DonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end CRM money path (Phase-0 spike): create a pending donation, drive
 * it to succeeded via a signed webhook, and issue a gap-free receipt — all with
 * Stripe MOCKED (no live API calls; live test-mode charge is a follow-up once
 * keys land).
 *
 * The webhook signature verification is REAL: each test signs its payload with
 * the configured secret exactly like Stripe does. The only Stripe seam mocked
 * is the outbound Checkout Session create (DonationService::createCheckoutSession).
 * Sqlite-in-memory is forced in setUp (mirrors TenantIsolationTest/ContactCrud).
 */
class DonationFlowTest extends TestCase
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

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Deterministic Stripe config for the whole suite.
        config([
            'services.stripe.webhook_secret' => $this->webhookSecret,
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
        ]);

        // Seed the additive spatie roles/permissions so the MasjidAdmins created
        // by makeAdminFor() are bridged to the masjid-admin role (holding the CRM
        // permission set). The new `permission:` gate on the admin funds/donations
        // routes then passes for them, exactly as it will in production.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->masjidB = $this->makeMasjid(['stripe_account_id' => 'acct_B', 'stripe_charges_enabled' => true]);

        // Seeded UNBOUND so the explicit masjid_id is honored.
        $this->fundA = $this->makeFund($this->masjidA);
        $this->fundB = $this->makeFund($this->masjidB);
    }

    // ================= public: create pending donation =================

    #[Test]
    public function create_checkout_persists_a_pending_donation_and_returns_the_url(): void
    {
        $this->stubCheckoutSession('cs_test_1', 'https://checkout.stripe.test/cs_test_1', 'pi_test_1');

        $response = $this->postJson(
            "/api/mobile/masjids/{$this->masjidA->id}/donations/checkout",
            ['fund_id' => $this->fundA->id, 'amount' => 10000, 'donor_covers_fees' => false]
        )->assertStatus(201);

        $response->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/cs_test_1');

        $this->assertDatabaseHas('donations', [
            'masjid_id' => $this->masjidA->id,
            'fund_id' => $this->fundA->id,
            'intended_amount' => 10000,
            'charged_amount' => 10000,
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_test_1',
            'stripe_payment_intent_id' => 'pi_test_1',
        ]);
    }

    #[Test]
    public function create_checkout_grosses_up_when_the_donor_covers_fees(): void
    {
        $this->stubCheckoutSession('cs_test_2', 'https://checkout.stripe.test/cs_test_2', 'pi_test_2');

        $this->postJson(
            "/api/mobile/masjids/{$this->masjidA->id}/donations/checkout",
            ['fund_id' => $this->fundA->id, 'amount' => 10000, 'donor_covers_fees' => true]
        )->assertStatus(201)
            ->assertJsonPath('data.charged_amount', 10330);

        $this->assertDatabaseHas('donations', [
            'intended_amount' => 10000,
            'charged_amount' => 10330, // $103.30 gross-up
            'donor_covers_fees' => true,
        ]);
    }

    #[Test]
    public function create_checkout_rejects_a_masjid_that_cannot_accept_donations(): void
    {
        $notReady = $this->makeMasjid(); // no stripe account / charges disabled
        $fund = $this->makeFund($notReady);

        $this->postJson(
            "/api/mobile/masjids/{$notReady->id}/donations/checkout",
            ['fund_id' => $fund->id, 'amount' => 5000]
        )->assertStatus(422);

        $this->assertDatabaseCount('donations', 0);
    }

    #[Test]
    public function create_checkout_rejects_a_fund_from_another_masjid(): void
    {
        // Masjid A can accept, but the fund belongs to masjid B → 404.
        $this->postJson(
            "/api/mobile/masjids/{$this->masjidA->id}/donations/checkout",
            ['fund_id' => $this->fundB->id, 'amount' => 5000]
        )->assertStatus(404);
    }

    // ================= webhook: succeed + issue receipt =================

    #[Test]
    public function checkout_completed_webhook_succeeds_donation_stores_ids_and_issues_receipt(): void
    {
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000);

        $this->postWebhook($this->checkoutCompletedEvent($donation, [
            'id' => 'cs_live_1',
            'payment_intent' => 'pi_live_1',
        ]))->assertOk();

        $donation->refresh();
        $this->assertSame('succeeded', $donation->status);
        $this->assertSame('cs_live_1', $donation->stripe_checkout_session_id);
        $this->assertSame('pi_live_1', $donation->stripe_payment_intent_id);
        $this->assertSame(10000, $donation->receipt_eligible_amount);

        $receipt = DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame(1, $receipt->serial_number);
        $this->assertSame(10000, $receipt->gross_amount);
        $this->assertSame(0, $receipt->advantage_amount);
        $this->assertSame(10000, $receipt->eligible_amount); // gross − advantage
        $this->assertSame('US', $receipt->jurisdiction);
        $this->assertSame($this->masjidA->id, $receipt->masjid_id);
    }

    #[Test]
    public function eligible_amount_equals_gross_minus_advantage_for_a_donor_covered_gift(): void
    {
        // Donor covered fees: charged (gross) = 10330, advantage 0 → eligible 10330.
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10330, [
            'donor_covers_fees' => true,
        ]);

        $this->postWebhook($this->checkoutCompletedEvent($donation))->assertOk();

        $receipt = DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->first();
        $this->assertSame(10330, $receipt->gross_amount);
        $this->assertSame(0, $receipt->advantage_amount);
        $this->assertSame(10330, $receipt->eligible_amount);
        $this->assertSame(10330, $donation->fresh()->receipt_eligible_amount);
    }

    #[Test]
    public function payment_intent_succeeded_stores_charge_and_balance_transaction(): void
    {
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000, [
            'stripe_payment_intent_id' => 'pi_pi_1',
        ]);

        // Balance transaction is expanded onto the payload, so no Stripe fetch.
        $this->postWebhook($this->paymentIntentSucceededEvent($donation, [
            'id' => 'pi_pi_1',
            'charge_id' => 'ch_pi_1',
            'balance_transaction_id' => 'txn_pi_1',
            'fee' => 330,
            'net' => 9670,
        ]))->assertOk();

        $donation->refresh();
        $this->assertSame('succeeded', $donation->status);
        $this->assertSame('ch_pi_1', $donation->stripe_charge_id);
        $this->assertSame('txn_pi_1', $donation->stripe_balance_transaction_id);
        $this->assertSame(330, $donation->stripe_fee_amount);
        $this->assertSame(9670, $donation->net_amount);

        $this->assertSame(
            1,
            DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count()
        );
    }

    // ================= webhook: idempotency =================

    #[Test]
    public function replaying_the_same_event_id_issues_only_one_receipt(): void
    {
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000);

        $event = $this->checkoutCompletedEvent($donation, ['event_id' => 'evt_dup_1']);

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk(); // duplicate delivery

        $this->assertSame(
            1,
            DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count()
        );
        $this->assertSame(1, StripeWebhookEvent::where('stripe_event_id', 'evt_dup_1')->count());
    }

    #[Test]
    public function two_different_events_for_the_same_donation_still_issue_one_receipt(): void
    {
        // checkout.session.completed AND payment_intent.succeeded both fire.
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000, [
            'stripe_payment_intent_id' => 'pi_two_1',
        ]);

        $this->postWebhook($this->checkoutCompletedEvent($donation, [
            'event_id' => 'evt_cs_1',
            'payment_intent' => 'pi_two_1',
        ]))->assertOk();

        $this->postWebhook($this->paymentIntentSucceededEvent($donation, [
            'event_id' => 'evt_pi_1',
            'id' => 'pi_two_1',
            'charge_id' => 'ch_two_1',
            'balance_transaction_id' => 'txn_two_1',
            'fee' => 330,
            'net' => 9670,
        ]))->assertOk();

        $this->assertSame(
            1,
            DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count()
        );
    }

    // ================= webhook: signature gate =================

    #[Test]
    public function webhook_rejects_a_bad_signature(): void
    {
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000);
        $payload = json_encode($this->checkoutCompletedEvent($donation));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertStatus(401);

        $this->assertDatabaseCount('donation_receipts', 0);
        $this->assertDatabaseHas('donations', ['id' => $donation->id, 'status' => 'pending']);
    }

    // ================= receipts: gap-free per masjid =================

    #[Test]
    public function receipt_serials_are_gap_free_and_independent_per_masjid(): void
    {
        // Three succeeded gifts for A, one for B.
        $aDonations = [
            $this->makePendingDonation($this->masjidA, $this->fundA, 1000, 1000),
            $this->makePendingDonation($this->masjidA, $this->fundA, 2000, 2000),
            $this->makePendingDonation($this->masjidA, $this->fundA, 3000, 3000),
        ];
        $bDonation = $this->makePendingDonation($this->masjidB, $this->fundB, 5000, 5000);

        foreach ($aDonations as $i => $d) {
            $this->postWebhook($this->checkoutCompletedEvent($d, ['event_id' => "evt_a_{$i}"]))->assertOk();
        }
        $this->postWebhook($this->checkoutCompletedEvent($bDonation, ['event_id' => 'evt_b_0']))->assertOk();

        $aSerials = DonationReceipt::withoutMasjidScope()
            ->where('masjid_id', $this->masjidA->id)
            ->orderBy('serial_number')
            ->pluck('serial_number')
            ->all();
        $bSerials = DonationReceipt::withoutMasjidScope()
            ->where('masjid_id', $this->masjidB->id)
            ->orderBy('serial_number')
            ->pluck('serial_number')
            ->all();

        $this->assertSame([1, 2, 3], $aSerials); // gap-free
        $this->assertSame([1], $bSerials);       // independent sequence per masjid
    }

    // ================= admin: tenant scoping =================

    #[Test]
    public function admin_funds_index_is_scoped_to_the_admins_masjid(): void
    {
        $this->makeFund($this->masjidA);       // A now has 2 funds
        $adminA = $this->makeAdminFor($this->masjidA);

        Sanctum::actingAs($adminA);

        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/funds")->assertOk();

        // Only masjid A's funds (2), never masjid B's.
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function admin_store_fund_stamps_the_bound_masjid(): void
    {
        $adminA = $this->makeAdminFor($this->masjidA);

        Sanctum::actingAs($adminA);

        // Client tries to plant it in masjid B; the trait stamps A.
        $response = $this->postJson("/api/admin/masjids/{$this->masjidA->id}/funds", [
            'masjid_id' => $this->masjidB->id,
            'name' => 'Ramadan Fund',
            'type' => 'sadaqah',
        ])->assertStatus(201);

        $this->assertSame($this->masjidA->id, $response->json('data.masjid_id'));
        $this->assertDatabaseHas('funds', ['name' => 'Ramadan Fund', 'masjid_id' => $this->masjidA->id]);
    }

    #[Test]
    public function admin_donations_index_is_scoped_and_blocks_cross_masjid_routes(): void
    {
        Donation::factory()->count(2)->create(['masjid_id' => $this->masjidA->id, 'fund_id' => $this->fundA->id]);
        Donation::factory()->count(3)->create(['masjid_id' => $this->masjidB->id, 'fund_id' => $this->fundB->id]);

        $adminA = $this->makeAdminFor($this->masjidA);
        Sanctum::actingAs($adminA);

        // Own masjid: sees only its 2 donations.
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations")
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        // Targeting masjid B in the route is forbidden by ResolveMasjidTenant.
        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/donations")
            ->assertStatus(403);
    }

    // ============================= helpers =============================

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

    private function makeFund(Masjid $masjid, array $overrides = []): Fund
    {
        return Fund::create(array_merge([
            'masjid_id' => $masjid->id,
            'name' => 'General Fund',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ], $overrides));
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

    private function makePendingDonation(
        Masjid $masjid,
        Fund $fund,
        int $intended,
        int $charged,
        array $overrides = []
    ): Donation {
        return Donation::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => $intended,
            'charged_amount' => $charged,
            'status' => 'pending',
        ], $overrides));
    }

    /**
     * Bind a partial DonationService whose only mocked method is the outbound
     * Checkout Session create — every other method (gross-up, persistence)
     * runs for real. Keeps the live Stripe API out of the test.
     */
    private function stubCheckoutSession(string $id, string $url, ?string $paymentIntent): void
    {
        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createCheckoutSession')
            ->andReturn(['id' => $id, 'url' => $url, 'payment_intent' => $paymentIntent]);

        $this->app->instance(DonationService::class, $service);
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
                ],
            ],
        ];
    }

    private function paymentIntentSucceededEvent(Donation $donation, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => 'acct_A',
            'data' => [
                'object' => [
                    'id' => $o['id'] ?? 'pi_' . uniqid(),
                    'object' => 'payment_intent',
                    'metadata' => ['donation_uuid' => $donation->uuid],
                    // Charge + balance transaction expanded so no Stripe fetch.
                    'latest_charge' => [
                        'id' => $o['charge_id'] ?? 'ch_' . uniqid(),
                        'object' => 'charge',
                        'balance_transaction' => [
                            'id' => $o['balance_transaction_id'] ?? 'txn_' . uniqid(),
                            'object' => 'balance_transaction',
                            'fee' => $o['fee'] ?? 330,
                            'net' => $o['net'] ?? 9670,
                        ],
                    ],
                ],
            ],
        ];
    }
}
