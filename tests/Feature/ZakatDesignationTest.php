<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\DonationService;
use App\Support\ZakatDesignation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zakat designation on a gift (T-031).
 *
 * The claim under test is that the designation is the GIVER's restriction on one
 * gift and not a property of the fund it landed in — so both of the cases that
 * break the fund-derived shortcut are pinned here: zakat into a general fund,
 * and a non-zakat gift into a zakat fund.
 *
 * Also pinned: the designation is written BEFORE the Stripe redirect and is
 * still on the row after the webhook settles it (the redirect is never trusted,
 * .claude/rules/stripe-payments.md), and a NON-zakat gift's Checkout Session
 * parameters are byte-identical to what they were before this feature existed.
 *
 * Stripe is mocked exactly as DonationFlowTest mocks it: the only seam stubbed
 * is the outbound Session create, and the webhook signature verification is
 * real.
 */
class ZakatDesignationTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_secret';

    private Masjid $masjid;
    private Fund $generalFund;
    private Fund $zakatFund;

    /** Captured Checkout Session params, so the metadata claims are checkable. */
    private array $capturedParams = [];

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
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
        ]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->generalFund = $this->makeFund($this->masjid, ['name' => 'General', 'type' => 'general']);
        $this->zakatFund = $this->makeFund($this->masjid, ['name' => 'Zakat', 'type' => 'zakat']);
    }

    // ======================= the designation itself =======================

    #[Test]
    public function zakat_given_to_a_general_fund_is_recorded_as_zakat(): void
    {
        // The case a fund-derived design gets wrong, and the common one: a
        // masjid with a single "General" fund whose donor is paying zakat.
        $this->stubCheckoutSession();

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->generalFund->id,
            'amount' => 50000,
            'zakat' => true,
        ])->assertStatus(201)->assertJsonPath('data.is_zakat', true);

        $this->assertDatabaseHas('donations', [
            'fund_id' => $this->generalFund->id,
            'is_zakat' => true,
            'zakat_source' => ZakatDesignation::SOURCE_DONOR,
        ]);
    }

    #[Test]
    public function a_gift_to_the_zakat_fund_that_the_donor_says_is_not_zakat_is_not_zakat(): void
    {
        // The other direction: sadaqah toward the relief appeal the org happens
        // to have typed as a zakat fund. Counting it would overstate the
        // restricted pot the org must disburse.
        $this->stubCheckoutSession();

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->zakatFund->id,
            'amount' => 50000,
            'zakat' => false,
        ])->assertStatus(201)->assertJsonPath('data.is_zakat', false);

        $this->assertDatabaseHas('donations', [
            'fund_id' => $this->zakatFund->id,
            'is_zakat' => false,
            // Nothing to attribute about a gift that carries no designation.
            'zakat_source' => null,
        ]);
    }

    #[Test]
    public function an_undeclared_gift_defaults_to_the_funds_type(): void
    {
        $this->stubCheckoutSession();

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->zakatFund->id,
            'amount' => 50000,
        ])->assertStatus(201)->assertJsonPath('data.is_zakat', true);

        $this->assertDatabaseHas('donations', [
            'fund_id' => $this->zakatFund->id,
            'is_zakat' => true,
            // Flagged as an inference, not the donor's own words.
            'zakat_source' => ZakatDesignation::SOURCE_FUND_DEFAULT,
        ]);
    }

    #[Test]
    public function an_undeclared_gift_to_an_ordinary_fund_is_not_zakat(): void
    {
        $this->stubCheckoutSession();

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->generalFund->id,
            'amount' => 50000,
        ])->assertStatus(201)->assertJsonPath('data.is_zakat', false);

        $this->assertDatabaseHas('donations', [
            'fund_id' => $this->generalFund->id,
            'is_zakat' => false,
            'zakat_source' => null,
        ]);
    }

    // ===================== it survives the webhook path =====================

    #[Test]
    public function the_designation_is_on_the_settled_row_without_the_browser_redirect(): void
    {
        $this->stubCheckoutSession('cs_zakat', 'https://checkout.stripe.test/cs_zakat', 'pi_zakat');

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->generalFund->id,
            'amount' => 50000,
            'zakat' => true,
        ])->assertStatus(201);

        $donation = Donation::withoutMasjidScope()->firstOrFail();
        $this->assertSame('pending', $donation->status);

        // Nothing but a signed webhook happens between here and settlement —
        // the redirect is never trusted, so the designation cannot depend on it.
        $this->postWebhook($this->paymentIntentSucceededEvent($donation))->assertOk();

        $donation->refresh();
        $this->assertSame('succeeded', $donation->status);
        $this->assertTrue($donation->is_zakat);
        $this->assertSame(ZakatDesignation::SOURCE_DONOR, $donation->zakat_source);
    }

    // ===================== Stripe params stay unchanged =====================

    #[Test]
    public function a_non_zakat_gift_sends_no_zakat_metadata_to_stripe(): void
    {
        $this->stubCheckoutSession();

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->generalFund->id,
            'amount' => 50000,
        ])->assertStatus(201);

        // The whole point of the positive-only discipline: an org that takes no
        // zakat sees Session parameters identical to before T-031 shipped.
        $metadata = $this->capturedParams['payment_intent_data']['metadata'];

        $this->assertArrayNotHasKey('zakat', $metadata);
        $this->assertArrayNotHasKey('zakat_source', $metadata);
        $this->assertSame(['donation_uuid', 'masjid_id', 'fund_id'], array_keys($metadata));
    }

    #[Test]
    public function a_zakat_gift_carries_the_designation_in_the_payment_intent_metadata(): void
    {
        $this->stubCheckoutSession();

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->generalFund->id,
            'amount' => 50000,
            'zakat' => true,
        ])->assertStatus(201);

        $metadata = $this->capturedParams['payment_intent_data']['metadata'];

        $this->assertSame('true', $metadata['zakat']);
        $this->assertSame(ZakatDesignation::SOURCE_DONOR, $metadata['zakat_source']);
    }

    // ============================== recurring ==============================

    #[Test]
    public function a_recurring_commitment_designates_once_and_every_invoice_inherits_it(): void
    {
        $this->stubCheckoutSession('cs_sub', 'https://checkout.stripe.test/cs_sub', null);

        $this->postJson($this->checkoutUrl(), [
            'fund_id' => $this->generalFund->id,
            'amount' => 10000,
            'recurring' => true,
            'zakat' => true,
        ])->assertStatus(201)->assertJsonPath('data.is_zakat', true);

        // The bypass is the correct read here — a public checkout runs UNBOUND,
        // so there is no tenant to scope by — but it must not be allowed to hide
        // WHERE the commitment landed. The tenant column is asserted explicitly;
        // the cross-tenant guarantee itself lives in
        // DonationSubscriptionTenantIsolationTest.
        $subscription = DonationSubscription::withoutMasjidScope()->firstOrFail();
        $this->assertSame($this->masjid->id, (int) $subscription->masjid_id);
        $this->assertTrue($subscription->is_zakat);

        // The fund's type is edited afterwards; the booked charge must still
        // carry what the donor designated, not what the fund says today.
        $this->generalFund->forceFill(['type' => 'sadaqah'])->save();

        $donation = app(DonationService::class)->bookRecurringInvoice([
            'id' => 'in_test_1',
            'amount_paid' => 10000,
            'customer' => 'cus_test_1',
            'subscription' => 'sub_test_1',
            'subscription_details' => [
                'metadata' => ['donation_subscription_uuid' => $subscription->uuid],
            ],
        ]);

        $this->assertNotNull($donation);
        $this->assertTrue($donation->is_zakat);
        $this->assertSame(ZakatDesignation::SOURCE_DONOR, $donation->zakat_source);
    }

    // ========================= admin / offline gift =========================

    #[Test]
    public function an_admin_can_mark_a_cash_gift_as_zakat(): void
    {
        $admin = $this->makeAdminFor($this->masjid);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/donations", [
            'fund_id' => $this->generalFund->id,
            'amount' => 250.00,
            'payment_method' => 'cash',
            'donated_at' => '2026-08-01',
            'zakat' => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('donations', [
            'source' => 'offline',
            'is_zakat' => true,
            // Weaker provenance than the donor's own words, and stored as such.
            'zakat_source' => ZakatDesignation::SOURCE_ADMIN,
        ]);
    }

    // ====================== reporting surfaces it ======================

    #[Test]
    public function the_stats_summary_reports_zakat_as_a_subset_and_changes_no_existing_key(): void
    {
        $admin = $this->makeAdminFor($this->masjid);
        Sanctum::actingAs($admin);

        $this->makeSucceededGift(10000, true);
        $this->makeSucceededGift(30000, false);

        $res = $this->getJson("/api/admin/masjids/{$this->masjid->id}/donations/stats/summary")
            ->assertOk();

        // Existing keys untouched: the totals still cover every gift.
        $res->assertJsonPath('data.all_time.gross_cents', 40000);
        $res->assertJsonPath('data.all_time.gift_count', 2);
        // The additive zakat subset.
        $res->assertJsonPath('data.all_time.zakat_gross_cents', 10000);
        $res->assertJsonPath('data.all_time.zakat_gift_count', 1);
        // And the definition travels with the number.
        $res->assertJsonPath('meta.zakat.source', 'donations.is_zakat');
        $this->assertStringContainsString(
            'NOT "gifts to a fund of type zakat"',
            $res->json('meta.zakat.definition')
        );
    }

    #[Test]
    public function the_per_fund_breakdown_separates_the_bucket_from_the_designation(): void
    {
        $admin = $this->makeAdminFor($this->masjid);
        Sanctum::actingAs($admin);

        // A zakat-typed fund holding one gift the donor said was NOT zakat.
        Donation::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'fund_id' => $this->zakatFund->id,
            'type' => 'one_time',
            'source' => 'offline',
            'donated_at' => '2026-08-01',
            'is_zakat' => false,
            'intended_amount' => 20000,
            'charged_amount' => 20000,
            'currency' => 'usd',
            'donor_covers_fees' => false,
            'status' => 'succeeded',
            'idempotency_key' => 'offline_' . uniqid(),
        ]);

        $rows = collect($this->getJson("/api/admin/masjids/{$this->masjid->id}/donations/stats/by-fund")
            ->assertOk()
            ->json('data'));

        $zakatFundRow = $rows->firstWhere('fund_id', $this->zakatFund->id);

        $this->assertSame('zakat', $zakatFundRow['fund_type']);
        $this->assertSame(20000, $zakatFundRow['gross_cents']);
        // The bucket is zakat; the money in it is not.
        $this->assertSame(0, $zakatFundRow['zakat_gross_cents']);
    }

    #[Test]
    public function the_export_carries_the_designation_and_the_ledger_can_filter_on_it(): void
    {
        $admin = $this->makeAdminFor($this->masjid);
        Sanctum::actingAs($admin);

        $this->makeSucceededGift(10000, true);
        $this->makeSucceededGift(30000, false);

        $csv = $this->get("/api/admin/masjids/{$this->masjid->id}/donations/export")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Zakat', $csv);
        $this->assertStringContainsString(ZakatDesignation::SOURCE_ADMIN, $csv);

        // zakat=1 narrows the ledger, and zakat=0 is a real filter rather than
        // "no filter" — the case a `when()` would silently get wrong.
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/donations?zakat=1")
            ->assertOk()->assertJsonPath('data.total', 1);

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/donations?zakat=0")
            ->assertOk()->assertJsonPath('data.total', 1);

        // A cleared filter input means no filter, not false.
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/donations?zakat=")
            ->assertOk()->assertJsonPath('data.total', 2);
    }

    // ================================ helpers ================================

    private function checkoutUrl(): string
    {
        return "/api/mobile/masjids/{$this->masjid->id}/donations/checkout";
    }

    private function makeSucceededGift(int $cents, bool $isZakat): Donation
    {
        return Donation::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'fund_id' => $this->generalFund->id,
            'type' => 'one_time',
            'source' => 'offline',
            'donated_at' => now()->toDateString(),
            'is_zakat' => $isZakat,
            'zakat_source' => $isZakat ? ZakatDesignation::SOURCE_ADMIN : null,
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => 'usd',
            'donor_covers_fees' => false,
            'status' => 'succeeded',
            'idempotency_key' => 'offline_' . uniqid(),
        ]);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Zakat Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_Z',
            'stripe_charges_enabled' => true,
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

    /**
     * Partial DonationService whose only mocked method is the outbound Session
     * create — and which records the params, so the metadata assertions above
     * examine what would really have gone to Stripe.
     */
    private function stubCheckoutSession(
        string $id = 'cs_test',
        string $url = 'https://checkout.stripe.test/cs_test',
        ?string $paymentIntent = 'pi_test'
    ): void {
        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createCheckoutSession')
            ->andReturnUsing(function (array $params) use ($id, $url, $paymentIntent) {
                $this->capturedParams = $params;

                return ['id' => $id, 'url' => $url, 'payment_intent' => $paymentIntent];
            });

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

    private function paymentIntentSucceededEvent(Donation $donation): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => 'acct_Z',
            'data' => [
                'object' => [
                    'id' => 'pi_' . uniqid(),
                    'object' => 'payment_intent',
                    'metadata' => ['donation_uuid' => $donation->uuid],
                    'latest_charge' => [
                        'id' => 'ch_' . uniqid(),
                        'object' => 'charge',
                        'balance_transaction' => [
                            'id' => 'txn_' . uniqid(),
                            'object' => 'balance_transaction',
                            'fee' => 1480,
                            'net' => 48520,
                        ],
                    ],
                ],
            ],
        ];
    }
}
