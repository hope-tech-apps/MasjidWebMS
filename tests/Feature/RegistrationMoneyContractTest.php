<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\RegistrationPayment;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ==========================================================================
 * ONE FIELD, ONE MEANING, ON EVERY DOOR THAT PUBLISHES IT
 * ==========================================================================
 *
 * `amount_due_minor` appears on three endpoints of one public API — `quote`,
 * `register`, `checkout`. Round six gave it a precise meaning ("the charges
 * that have not been made yet", per plan kind, through `perChargeMinor()`) and
 * implemented that on ONE of them; the other two went on publishing
 * `(int) $registration->adjusted_total_minor`, which answers a different
 * question. Measured on this branch before the fix:
 *
 *   FULL OFFERING, $150 one-time plan
 *     register  200 "This offering is full — you have been added to the
 *                    waitlist."   amount_due_minor 15000
 *     quote     200 amount_due_minor 0, requires_payment false
 *     checkout  422
 *
 *   9 × $100.00 INSTALLMENT, 10¢ of aid granted while pending
 *     checkout  200 amount_due_minor 89990, opening a subscription that bills
 *                   9998 nine times = 89982
 *     quote     200 amount_due_minor 89982
 *
 * The first tells a family on a waiting list that she owes $150. The second
 * makes the door money actually moves through name a number 8¢ away from the
 * one Stripe is asked for, and 8¢ away from the preview rendered beside it.
 *
 * This file pins the CONTRACT rather than the arithmetic — the arithmetic is
 * RegistrationMoneySurfaceTest's. Every case here asks the same question of
 * every door at the same instant and asserts they answer it the same way.
 */
class RegistrationMoneyContractTest extends TestCase
{
    use RefreshDatabase;

    private string $platformSecret = 'whsec_platform_secret';

    private Masjid $masjid;

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
            'services.stripe.webhook_secret' => $this->platformSecret,
            'services.stripe.connect_webhook_secret' => 'whsec_connect_secret',
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
            'services.stripe.registration_checkout_window_minutes' => 30,
        ]);

        // /api/v1 never runs the tenant middleware.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->stubSession('cs_contract', 'https://stripe.test/contract', 'pi_contract');
    }

    // =====================================================================
    // F-M1 — the three doors publish one number
    // =====================================================================

    /**
     * Every plan kind, at the one instant all three doors can answer for the
     * same row: pending, chargeable, nothing settled.
     *
     * Written as one test over a table rather than four, because the property is
     * "these doors agree", and a per-kind test asserts a per-kind constant —
     * which is exactly how the two that were wrong went on passing.
     */
    #[Test]
    public function register_checkout_and_quote_publish_the_same_amount_due(): void
    {
        $plans = [
            'one_time' => fn () => FeePlan::factory(),
            'installment' => fn () => FeePlan::factory()->installment(9, 10000),
            'recurring' => fn () => FeePlan::factory()->recurring('month', 5000),
        ];

        foreach ($plans as $kind => $make) {
            [$offering, $registration, $registerData] = $this->registerThroughTheDoor($make());

            $quote = $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
                ->assertOk()
                ->json('data');

            $checkout = $this->postCheckout($registration)->assertOk()->json('data');

            $this->assertSame(
                $quote['amount_due_minor'],
                $registerData['amount_due_minor'],
                "{$kind}: register and quote must publish the same amount_due_minor"
            );

            $this->assertSame(
                $quote['amount_due_minor'],
                $checkout['amount_due_minor'],
                "{$kind}: checkout and quote must publish the same amount_due_minor"
            );

            // …and "what it cost" is available at every door too, so nothing was
            // taken away by giving the other field its own meaning.
            $this->assertSame(
                (int) $registration->adjusted_total_minor,
                $registerData['adjusted_total_minor'],
                "{$kind}: register publishes the snapshot"
            );
            $this->assertSame(
                (int) $registration->adjusted_total_minor,
                $checkout['adjusted_total_minor'],
                "{$kind}: checkout publishes the snapshot"
            );
        }
    }

    /**
     * THE FREE PATH, where there is no Stripe leg at all. `checkout` refuses it
     * (there is never a $0 session), so the agreement is between the two doors
     * that answer.
     */
    #[Test]
    public function a_free_registration_owes_nothing_at_every_door(): void
    {
        [$offering, $registration, $registerData] = $this->registerThroughTheDoor(FeePlan::factory()->free());

        $this->assertSame('confirmed', $registration->status);
        $this->assertSame(0, $registerData['amount_due_minor']);
        $this->assertSame(0, $registerData['adjusted_total_minor']);
        $this->assertNull($registerData['checkout_url']);

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.amount_due_minor', 0)
            ->assertJsonPath('data.requires_payment', false);

        $this->postCheckout($registration)->assertStatus(422);
    }

    /**
     * A WAITING LIST IS NOT A BILL.
     *
     * The measured case: a full offering, a $150 plan, and the door she came
     * through told her she owed $150.00 in the same 200 that told her she was on
     * a list. She holds no seat, has no payment leg (`payment_status: none`) and
     * `checkout` refuses her — every other surface already said zero.
     */
    #[Test]
    public function a_waitlisted_registration_is_told_it_owes_nothing(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->atCapacity(1)->create();
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $data = $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Wait Lister', 'email' => 'wl@example.test'],
            'data' => ['full_name' => 'Wait Lister'],
        ])->assertOk()->json('data');

        $registration = Registration::withoutMasjidScope()
            ->where('uuid', $data['registration_uuid'])
            ->firstOrFail();

        $this->assertSame(Registration::STATUS_WAITLISTED, $registration->status);
        $this->assertSame(Registration::PAYMENT_NONE, $registration->payment_status);

        $this->assertSame(0, $data['amount_due_minor'], 'A place on a list is not a debt.');
        // …and what the place WOULD cost is still there, under the name that
        // means that. Removing the number was never the fix; naming it was.
        $this->assertSame(15000, $data['adjusted_total_minor']);
        $this->assertNull($data['checkout_url']);

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.amount_due_minor', 0)
            ->assertJsonPath('data.adjusted_total_minor', 15000)
            ->assertJsonPath('data.requires_payment', false);
    }

    /**
     * AID THAT LEAVES A REMAINDER: the door money moves through must name the
     * number Stripe is asked for.
     *
     * `perChargeMinor()` is `intdiv(89990, 9) = 9998` and the rounding is dropped
     * in the payer's favour, so nine charges are 89982. `checkout` published the
     * 89990 snapshot — 8¢ more than the session it had just minted, and 8¢ more
     * than the quote beside it.
     */
    #[Test]
    public function the_checkout_door_names_the_amount_stripe_is_asked_for(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThroughService($offering, $plan);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            10,
            'A dime, to force the remainder'
        );

        $registration->refresh();

        $perCharge = RegistrationCheckoutService::perChargeMinor($registration, $plan);
        $this->assertSame(9998, $perCharge);
        $this->assertSame(89990, (int) $registration->adjusted_total_minor);

        $checkout = $this->postCheckout($registration)->assertOk()->json('data');

        $this->assertSame(
            $perCharge * 9,
            $checkout['amount_due_minor'],
            'checkout must publish the sum of the charges Stripe will make'
        );
        $this->assertSame(89982, $checkout['amount_due_minor']);
        $this->assertSame(89990, $checkout['adjusted_total_minor'], 'the snapshot is never restated');

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.amount_due_minor', 89982);
    }

    /**
     * THE INVARIANT UNDER ALL OF IT: no door hands back a payment link beside a
     * zero.
     *
     * The risk this fix creates, stated as a test rather than as a paragraph.
     * `amount_due_minor` is now derived from `payment_status`, so if a state ever
     * arises where `checkout()` succeeds on a row whose money state is not one of
     * awaiting/active/past_due, the two endpoints would publish "$0.00 — Pay
     * now". Reading the code says that state cannot persist (`preflight()`
     * requires `pending`, and the only writer of `pending` + `none`,
     * `promoteFromWaitlist()`, confirms in the same transaction). This asserts it
     * on the wire for every kind, so a future writer of that state fails here.
     */
    #[Test]
    public function no_door_offers_a_payment_link_beside_a_zero(): void
    {
        foreach ([
            'one_time' => fn () => FeePlan::factory(),
            'installment' => fn () => FeePlan::factory()->installment(9, 10000),
            'recurring' => fn () => FeePlan::factory()->recurring('month', 5000),
            'free' => fn () => FeePlan::factory()->free(),
        ] as $kind => $make) {
            [$offering, $registration, $registerData] = $this->registerThroughTheDoor($make());

            if ($registerData['checkout_url'] !== null) {
                $this->assertGreaterThan(
                    0,
                    $registerData['amount_due_minor'],
                    "{$kind}: register handed back a payment link beside a zero"
                );
            }

            $checkout = $this->postCheckout($registration);

            if ($checkout->status() === 200) {
                $this->assertGreaterThan(
                    0,
                    $checkout->json('data.amount_due_minor'),
                    "{$kind}: checkout handed back a payment link beside a zero"
                );
            }

            $quote = $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
                ->assertOk()
                ->json('data');

            if ($quote['can_pay_now'] === true) {
                $this->assertGreaterThan(
                    0,
                    $quote['amount_due_minor'],
                    "{$kind}: the quote promised a payment beside a zero"
                );
            }
        }
    }

    // =====================================================================
    // F-M2 — a subscription in arrears owes every interval it has missed
    // =====================================================================

    /**
     * MEASURED, ON THE WIRE, every transition driven by a signed webhook. Before
     * this fix the `due` column read 5000 on all three of the failing rows, and
     * 0 on the last:
     *
     *   after session.completed            paid 0      due 5000    failed 0
     *   invoice 1 succeeded                paid 5000   due 0       failed 0
     *   invoice 2 failed                   paid 5000   due 5000    failed 1
     *   invoice 3 failed                   paid 5000   due 5000    failed 2   <- owes 10000
     *   invoice 4 failed                   paid 5000   due 5000    failed 3   <- owes 15000
     *   invoice 2 recovers                 paid 10000  due 0       failed 2   <- owes 10000
     *
     * Round six declared the one-interval bound in a docblock and in the rules
     * file, so it was stated rather than hidden — but a declaration is read by
     * the next engineer and the number is read by the family, and when the bound
     * bit there was no surface that would ever say so.
     *
     * The last row is the cell the finding did not name and the worse one: a
     * partial recovery returns `payment_status` to `active`, so the
     * "`past_due` means one interval" rule reported that NOTHING was owed while
     * two invoices sat uncollected.
     */
    #[Test]
    public function a_subscription_in_arrears_owes_every_interval_it_has_missed(): void
    {
        [$offering, $registration] = $this->runningSubscription();

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']));
        $registration->refresh();
        $this->assertDue($offering, $registration, 0, 'a settled interval owes nothing');

        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_2']));
        $registration->refresh();
        $this->assertSame(Registration::PAYMENT_PAST_DUE, $registration->payment_status);
        $this->assertDue($offering, $registration, 5000, 'one uncollected invoice');

        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_3']));
        $registration->refresh();
        $this->assertDue($offering, $registration, 10000, 'two uncollected invoices — this used to read 5000');

        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_4']));
        $registration->refresh();
        $this->assertDue($offering, $registration, 15000, 'three uncollected invoices');
    }

    /** A retry that succeeds clears exactly the invoice it settled, and no more. */
    #[Test]
    public function recovering_one_invoice_leaves_the_rest_of_the_arrears_standing(): void
    {
        [$offering, $registration] = $this->runningSubscription();

        foreach (['in_1', 'in_2', 'in_3'] as $invoice) {
            $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => $invoice]));
        }

        $registration->refresh();
        $this->assertDue($offering, $registration, 15000);

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_2']));
        $registration->refresh();

        // Stripe's own signal: a settled invoice returns the subscription to
        // `active`. That is NOT "nothing is outstanding" — two invoices are
        // still uncollected, and this cell read 0 before the fix.
        $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->payment_status);
        $this->assertDue($offering, $registration, 10000, 'the two invoices Stripe still cannot collect');

        foreach (['in_1', 'in_3'] as $invoice) {
            $this->postWebhook($this->invoicePaidEvent($registration, ['id' => $invoice]));
        }

        $registration->refresh();
        $this->assertDue($offering, $registration, 0, 'fully recovered');
    }

    /**
     * THE FLOOR UNDER THE LEDGER, which is the part that stays a bound.
     *
     * The arrears are exact to the extent the webhook arrived. An
     * `invoice.payment_failed` we were never sent is an invoice this cannot know
     * about — so `past_due` on its own still owes at least one interval, and the
     * failure mode is "short by the invoices we were not told about", never
     * "zero while she is in arrears".
     */
    #[Test]
    public function past_due_owes_an_interval_even_with_no_ledger_row_to_key(): void
    {
        [$offering, $registration] = $this->runningSubscription();

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']));

        // A dunning event carrying no invoice id books no row (there is no key
        // that could dedupe a redelivery) but still moves the payer to past_due.
        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => null]));
        $registration->refresh();

        $this->assertSame(Registration::PAYMENT_PAST_DUE, $registration->payment_status);
        $this->assertSame(
            0,
            RegistrationPayment::withoutMasjidScope()
                ->where('registration_id', $registration->id)
                ->where('status', RegistrationPayment::STATUS_FAILED)
                ->count(),
            'nothing keyable was booked'
        );

        $this->assertDue($offering, $registration, 5000, 'the floor: at least the interval');
    }

    /**
     * A FINITE PLAN MUST NOT DOUBLE-COUNT. An installment plan's outstanding is
     * already `per-charge × N − paid`, which counts every charge that has not
     * settled, failed ones included — so the arrears sum is deliberately not
     * applied there.
     */
    #[Test]
    public function a_failed_instalment_is_counted_once_and_not_twice(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThroughService($offering, $plan);
        $this->postWebhook($this->subscriptionSessionEvent($registration));

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1', 'amount_paid' => 10000]));
        $registration->refresh();
        $this->assertDue($offering, $registration, 80000, 'eight of the nine charges remain');

        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_2', 'amount_due' => 10000]));
        $registration->refresh();

        $this->assertDue(
            $offering,
            $registration,
            80000,
            'a failed instalment was already inside the balance; counting it again would bill it twice'
        );
    }

    // ------------------------------------------------------------- fixtures

    private function assertDue(Offering $offering, Registration $registration, int $expected, string $why = ''): void
    {
        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.amount_due_minor', $expected, $why);
    }

    /** A confirmed, running $50/month subscription with nothing settled yet. */
    private function runningSubscription(): array
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->recurring('month', 5000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThroughService($offering, $plan);
        $this->postWebhook($this->subscriptionSessionEvent($registration));
        $registration->refresh();

        return [$offering, $registration];
    }

    /**
     * Through the PUBLIC door, so the register payload under test is the one a
     * family actually receives.
     *
     * @return array{0:Offering,1:Registration,2:array}
     */
    private function registerThroughTheDoor($planFactory): array
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = $planFactory->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $data = $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf', 'email' => 'amal-' . uniqid() . '@example.test'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertOk()->json('data');

        $registration = Registration::withoutMasjidScope()
            ->where('uuid', $data['registration_uuid'])
            ->firstOrFail();

        return [$offering, $registration, $data];
    }

    /** Through the service, for the states that need no Stripe leg minted yet. */
    private function registerThroughService(Offering $offering, FeePlan $plan): Registration
    {
        return app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjid->id]),
            ['full_name' => 'Amal Yusuf']
        );
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

    private function stubSession(string $id, string $url, ?string $paymentIntent): void
    {
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createCheckoutSession')
            ->andReturn(['id' => $id, 'url' => $url, 'payment_intent' => $paymentIntent]);

        $this->app->instance(RegistrationCheckoutService::class, $service);
    }

    private function postQuote(Offering $offering, array $body): TestResponse
    {
        return $this->postJson(
            "/api/v1/offerings/{$offering->slug}/quote",
            $body,
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    private function postRegister(Offering $offering, array $body): TestResponse
    {
        return $this->postJson(
            "/api/v1/offerings/{$offering->slug}/register",
            $body,
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    private function postCheckout(Registration $registration): TestResponse
    {
        return $this->postJson(
            "/api/v1/registrations/{$registration->uuid}/checkout",
            [],
            ['masjid-id' => (string) $registration->masjid_id]
        );
    }

    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->platformSecret);

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

    /** `checkout.session.completed` in SUBSCRIPTION mode — books no payment row. */
    private function subscriptionSessionEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'cs_sub_1',
                'object' => 'checkout.session',
                'mode' => 'subscription',
                'payment_status' => 'paid',
                'status' => 'complete',
                'subscription' => $o['subscription'] ?? 'sub_reg_1',
                'client_reference_id' => $registration->uuid,
                'metadata' => ['registration_uuid' => $registration->uuid],
            ]],
        ];
    }

    /** Our uuid rides on the SUBSCRIPTION's metadata, not the invoice's own. */
    private function invoicePaidEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'invoice.payment_succeeded',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'in_1',
                'object' => 'invoice',
                'amount_paid' => $o['amount_paid'] ?? 5000,
                'subscription' => $o['subscription'] ?? 'sub_reg_1',
                'subscription_details' => [
                    'metadata' => ['registration_uuid' => $registration->uuid],
                ],
            ]],
        ];
    }

    private function invoiceFailedEvent(Registration $registration, array $o = []): array
    {
        $object = [
            'object' => 'invoice',
            'amount_due' => $o['amount_due'] ?? 5000,
            'amount_paid' => 0,
            'subscription' => $o['subscription'] ?? 'sub_reg_1',
            'subscription_details' => [
                'metadata' => ['registration_uuid' => $registration->uuid],
            ],
        ];

        if (array_key_exists('id', $o) && $o['id'] === null) {
            // Deliberately id-less: the shape that books no keyable row.
        } else {
            $object['id'] = $o['id'] ?? 'in_1';
        }

        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'invoice.payment_failed',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => $object],
        ];
    }
}
