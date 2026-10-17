<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Stripe\DonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
    use \Tests\Support\SeedsDonationSubscriptions;

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

    public static function ignoredDonationEvents(): array
    {
        return [
            'checkout other account' => ['checkout.session.completed', 'other_account'],
            'checkout missing account' => ['checkout.session.completed', 'missing_account'],
            'checkout wrong amount' => ['checkout.session.completed', 'amount'],
            'checkout wrong currency' => ['checkout.session.completed', 'currency'],
            'checkout missing amount' => ['checkout.session.completed', 'missing_amount'],
            'checkout missing currency' => ['checkout.session.completed', 'missing_currency'],
            'async checkout other account' => ['checkout.session.async_payment_succeeded', 'other_account'],
            'intent other account' => ['payment_intent.succeeded', 'other_account'],
            'intent wrong amount' => ['payment_intent.succeeded', 'amount'],
            'intent wrong currency' => ['payment_intent.succeeded', 'currency'],
        ];
    }

    #[Test]
    #[DataProvider('ignoredDonationEvents')]
    public function donation_events_must_match_the_charge_account_amount_and_currency(string $type, string $mismatch): void
    {
        Mail::fake();
        $contact = Contact::factory()->create(['masjid_id' => $this->masjidA->id, 'email' => 'original@example.test']);
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10330, [
            'contact_id' => $contact->id, 'donor_covers_fees' => true,
        ]);
        $before = $donation->fresh()->getAttributes();
        $contactBefore = $contact->fresh()->getAttributes();
        $event = $type === 'payment_intent.succeeded'
            ? $this->paymentIntentSucceededEvent($donation)
            : $this->checkoutCompletedEvent($donation, ['type' => $type]);
        $event['data']['object']['customer_details'] = ['email' => 'checkout@example.test', 'name' => 'Test Donor'];
        $amountKey = $type === 'payment_intent.succeeded' ? 'amount_received' : 'amount_total';
        if ($mismatch === 'other_account') $event['account'] = $this->masjidB->stripe_account_id;
        if ($mismatch === 'missing_account') unset($event['account']);
        if ($mismatch === 'amount') $event['data']['object'][$amountKey] = 10000;
        if ($mismatch === 'currency') $event['data']['object']['currency'] = 'cad';
        if ($mismatch === 'missing_amount') unset($event['data']['object'][$amountKey]);
        if ($mismatch === 'missing_currency') unset($event['data']['object']['currency']);

        $this->postWebhook($event)->assertOk();

        $this->assertSame($before, $donation->fresh()->getAttributes());
        $this->assertSame($contactBefore, $contact->fresh()->getAttributes());
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('donation_receipts', 0);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_platform_donation_only_accepts_an_event_without_an_account(): void
    {
        $this->masjidA->update(['stripe_account_id' => null]);
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 5000, 5000);
        $event = $this->checkoutCompletedEvent($donation);
        $event['account'] = $this->masjidB->stripe_account_id;
        $this->postWebhook($event)->assertOk();
        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertDatabaseCount('donation_receipts', 0);

        $event['id'] = 'evt_platform_correct';
        unset($event['account']);
        $this->postWebhook($event)->assertOk();
        $this->assertSame('succeeded', $donation->fresh()->status);
        $this->assertDatabaseCount('donation_receipts', 1);
    }

    public static function recurringIdentifiers(): array
    {
        return [
            'uuid before checkout' => [null, true],
            'uuid after checkout' => ['sub_recurring_test', true],
            'linked id without metadata' => ['sub_recurring_test', false],
        ];
    }

    #[Test]
    #[DataProvider('recurringIdentifiers')]
    public function a_recurring_invoice_matches_its_commitments_account_before_linking_or_booking(?string $stripeSubId, bool $includeUuid): void
    {
        Mail::fake();
        $subscription = $this->seedDonationSubscription($this->masjidA, $this->fundA, [
            'status' => 'pending', 'stripe_subscription_id' => $stripeSubId,
        ]);
        $before = $subscription->getAttributes();
        $event = [
            'id' => 'evt_recurring_other', 'type' => 'invoice.payment_succeeded',
            'account' => $this->masjidB->stripe_account_id,
            'data' => ['object' => [
                'id' => 'in_recurring_test', 'subscription' => 'sub_recurring_test', 'amount_paid' => 5000,
                'subscription_details' => ['metadata' => ['donation_subscription_uuid' => $subscription->uuid]],
            ]],
        ];
        if (! $includeUuid) unset($event['data']['object']['subscription_details']);
        $this->postWebhook($event)->assertOk();
        $this->assertSame($before, $subscription->fresh()->getAttributes());
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('donation_receipts', 0);
        Mail::assertNothingSent();

        $event['id'] = 'evt_recurring_correct';
        $event['account'] = $this->masjidA->stripe_account_id;
        $this->postWebhook($event)->assertOk();
        $this->assertSame('sub_recurring_test', $subscription->fresh()->stripe_subscription_id);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertDatabaseCount('donations', 1);
        $this->assertDatabaseCount('donation_receipts', 1);
    }

    #[Test]
    public function subscription_checkout_matches_the_commitments_account(): void
    {
        Mail::fake();
        $subscription = $this->seedDonationSubscription($this->masjidA, $this->fundA, [
            'status' => 'pending', 'stripe_subscription_id' => null, 'stripe_customer_id' => null,
        ]);
        $before = $subscription->getAttributes();
        $event = [
            'id' => 'evt_sub_checkout_other', 'type' => 'checkout.session.completed',
            'account' => $this->masjidB->stripe_account_id,
            'data' => ['object' => [
                'id' => 'cs_recurring_test', 'mode' => 'subscription', 'subscription' => 'sub_checkout_test',
                'customer' => 'cus_checkout_test', 'metadata' => ['donation_subscription_uuid' => $subscription->uuid],
                'customer_details' => ['email' => 'checkout@example.test', 'name' => 'Test Donor'],
            ]],
        ];
        $this->postWebhook($event)->assertOk();
        $this->assertSame($before, $subscription->fresh()->getAttributes());
        $this->assertDatabaseCount('contacts', 0);
        Mail::assertNothingSent();
        $event['id'] = 'evt_sub_checkout_correct';
        $event['account'] = $this->masjidA->stripe_account_id;
        $this->postWebhook($event)->assertOk();
        $this->assertSame('sub_checkout_test', $subscription->fresh()->stripe_subscription_id);
    }

    #[Test]
    public function subscription_deletion_matches_the_commitments_account(): void
    {
        $subscription = $this->seedDonationSubscription($this->masjidA, $this->fundA, [
            'stripe_subscription_id' => 'sub_checkout_test',
        ]);
        $event = ['id' => 'evt_sub_deleted_other', 'type' => 'customer.subscription.deleted',
            'account' => $this->masjidB->stripe_account_id, 'data' => ['object' => ['id' => 'sub_checkout_test']]];
        $this->postWebhook($event)->assertOk();
        $this->assertSame('active', $subscription->fresh()->status);
        $event['id'] = 'evt_sub_deleted_correct';
        $event['account'] = $this->masjidA->stripe_account_id;
        $this->postWebhook($event)->assertOk();
        $this->assertSame('canceled', $subscription->fresh()->status);
    }

    public static function returnUrlFields(): array
    {
        return [
            'one-off success' => ['success_url', false], 'one-off cancel' => ['cancel_url', false],
            'recurring success' => ['success_url', true], 'recurring cancel' => ['cancel_url', true],
        ];
    }

    #[Test]
    #[DataProvider('returnUrlFields')]
    public function donation_checkout_refuses_return_urls_outside_the_forms_allowlist(string $field, bool $recurring): void
    {
        config(['forms.payment_return_origins' => ['https://giving.example.test']]);
        $this->stubCheckoutSession('cs_return_test', 'https://checkout.stripe.test/return_test', null);
        $this->postJson("/api/mobile/masjids/{$this->masjidA->id}/donations/checkout", [
            'fund_id' => $this->fundA->id, 'amount' => 5000, 'recurring' => $recurring,
            $field => 'https://elsewhere.example.test/return',
        ])->assertStatus(422)->assertJsonPath('message', 'This return address is not allowed.');
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('donation_subscriptions', 0);
    }

    public static function conflictingCommitments(): array
    {
        return [
            'invoice with different linked id' => ['invoice.payment_succeeded', true],
            'checkout with different linked id' => ['checkout.session.completed', true],
            'invoice naming another commitment' => ['invoice.payment_succeeded', false],
            'checkout naming another commitment' => ['checkout.session.completed', false],
        ];
    }

    #[Test]
    #[DataProvider('conflictingCommitments')]
    public function recurring_identifiers_must_name_the_same_commitment(string $type, bool $alreadyLinked): void
    {
        $subscription = $this->seedDonationSubscription($this->masjidA, $this->fundA, [
            'status' => 'pending', 'stripe_subscription_id' => $alreadyLinked ? 'sub_original_test' : null,
        ]);
        if (! $alreadyLinked) {
            $this->seedDonationSubscription($this->masjidB, $this->fundB, ['stripe_subscription_id' => 'sub_other_test']);
        }
        $before = $subscription->getAttributes();
        $object = [
            'id' => 'object_recurring_test', 'mode' => 'subscription', 'subscription' => 'sub_other_test',
            'amount_paid' => 5000,
            'metadata' => ['donation_subscription_uuid' => $subscription->uuid],
            'subscription_details' => ['metadata' => ['donation_subscription_uuid' => $subscription->uuid]],
        ];
        $this->postWebhook(['id' => 'evt_recurring_conflict', 'type' => $type,
            'account' => $this->masjidA->stripe_account_id, 'data' => ['object' => $object]])->assertOk();
        $this->assertSame($before, $subscription->fresh()->getAttributes());
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('donation_receipts', 0);
    }

    #[Test]
    public function donation_return_urls_use_configured_origins_and_only_the_organisations_confirmed_domains(): void
    {
        config(['forms.payment_return_origins' => ['https://giving.example.test']]);
        $domain = MasjidDomain::create([
            'masjid_id' => $this->masjidB->id, 'host' => 'donate.example.test',
            'kind' => MasjidDomain::KIND_CUSTOM, 'zone_apex' => 'example.test',
            'status' => MasjidDomain::STATUS_MANUAL, 'serving_confirmed_at' => now(),
        ]);
        $input = ['fund_id' => $this->fundA->id, 'amount' => 5000,
            'success_url' => 'https://giving.example.test/thank-you?paid=1',
            'cancel_url' => 'https://donate.example.test/cancelled'];
        $mock = Mockery::mock(DonationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('createCheckoutSession')->once()
            ->withArgs(fn (array $params, string $account, string $key) =>
                $params['success_url'] === $input['success_url'] && $params['cancel_url'] === $input['cancel_url']
                && $account === $this->masjidA->stripe_account_id)
            ->andReturn(['id' => 'cs_allowed_return', 'url' => 'https://checkout.stripe.test/allowed', 'payment_intent' => null]);
        $this->app->instance(DonationService::class, $mock);
        $this->postJson("/api/mobile/masjids/{$this->masjidA->id}/donations/checkout", $input)->assertStatus(422);
        $domain->update(['masjid_id' => $this->masjidA->id, 'serving_confirmed_at' => null]);
        $this->postJson("/api/mobile/masjids/{$this->masjidA->id}/donations/checkout", $input)->assertStatus(422);
        $domain->update(['serving_confirmed_at' => now()]);

        // Form-encoded, as on a public web donation page.
        $this->post("/api/mobile/masjids/{$this->masjidA->id}/donations/checkout", $input)->assertStatus(201);
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

    // ---------------------------------------- a bank debit: complete is not paid

    #[Test]
    public function a_bank_debit_still_clearing_leaves_the_donation_pending_with_no_receipt(): void
    {
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000);
        $session = ['id' => 'cs_bank_1', 'payment_intent' => 'pi_bank_1'];

        $this->postWebhook($this->checkoutCompletedEvent($donation, $session + ['payment_status' => 'unpaid']))->assertOk();
        $donation->refresh();
        $this->assertSame('pending', $donation->status);
        $this->assertSame('cs_bank_1', $donation->stripe_checkout_session_id);
        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count());

        // Days later the money lands; then the payment intent's own notice.
        $this->postWebhook($this->checkoutCompletedEvent($donation, $session + ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();
        $this->assertSame('succeeded', $donation->fresh()->status);
        $this->postWebhook($this->paymentIntentSucceededEvent($donation, ['id' => 'pi_bank_1']))->assertOk();

        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count());
    }

    #[Test]
    public function a_payment_intent_settles_a_clearing_bank_debit_with_one_receipt(): void
    {
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000);
        $session = ['id' => 'cs_bank_2', 'payment_intent' => 'pi_bank_2'];

        $this->postWebhook($this->checkoutCompletedEvent($donation, $session + ['payment_status' => 'unpaid']))->assertOk();
        $this->postWebhook($this->paymentIntentSucceededEvent($donation, ['id' => 'pi_bank_2']))->assertOk();
        $this->assertSame('succeeded', $donation->fresh()->status);
        $this->postWebhook($this->checkoutCompletedEvent($donation, $session + ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count());
    }

    #[Test]
    public function a_failed_bank_debit_leaves_the_donation_pending_and_says_so(): void
    {
        $donation = $this->makePendingDonation($this->masjidA, $this->fundA, 10000, 10000);
        $session = ['id' => 'cs_bank_3', 'payment_intent' => 'pi_bank_3'];
        Log::spy();

        $this->postWebhook($this->checkoutCompletedEvent($donation, $session + ['payment_status' => 'unpaid']))->assertOk();
        $this->postWebhook($this->checkoutCompletedEvent($donation, $session + ['type' => 'checkout.session.async_payment_failed', 'payment_status' => 'unpaid']))->assertOk();

        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'delayed donation payment failed'))
            ->once();
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
            // CRM is OFF by default; the admin funds/donations assertions here
            // assume access, so enable the gate for the test masjid. The public
            // mobile checkout path is not CRM-gated. Default-off + gate behavior
            // is covered separately in CrmFeatureGateTest.
            'crm_enabled' => true,
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
            'type' => $o['type'] ?? 'checkout.session.completed',
            'account' => Masjid::findOrFail($donation->masjid_id)->stripe_account_id,
            'data' => [
                'object' => [
                    'id' => $o['id'] ?? 'cs_' . uniqid(),
                    'object' => 'checkout.session',
                    'payment_status' => $o['payment_status'] ?? 'paid',
                    'status' => 'complete',
                    'amount_total' => $donation->charged_amount,
                    'currency' => $donation->currency,
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
            'account' => Masjid::findOrFail($donation->masjid_id)->stripe_account_id,
            'data' => [
                'object' => [
                    'id' => $o['id'] ?? 'pi_' . uniqid(),
                    'object' => 'payment_intent',
                    'amount_received' => $donation->charged_amount,
                    'currency' => $donation->currency,
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
