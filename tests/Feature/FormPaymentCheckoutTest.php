<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Services\Stripe\FormCheckoutRefused;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\FormPayment;
use App\Support\FormPaymentReturn;
use App\Support\StripeFees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Card payment on a form, end to end through the HTTP layer the festival page uses
 * (DECISIONS.md 2026-09-11; festival brief, blocker 4 and "Return URLs").
 *
 * What is pinned here:
 *
 *  - a card registration is written UNPAID with its integer-cents snapshot, its
 *    idempotency key is on the row BEFORE Stripe is called, and nobody is emailed
 *    until the webhook says it is paid;
 *  - the session is a direct charge on the organisation's account carrying
 *    form_response_uuid on the session AND the payment intent, priced from the
 *    snapshot (entries × unit, plus the named card-fee line only when covered), living
 *    30 minutes, card only, with the platform fee only above zero and the payer's email only when
 *    Stripe will take it (retried once without it when Stripe refuses);
 *  - everything that could stop the page opening is refused BEFORE anything is
 *    written: Connect not live, an Origin off the allowlist (which fails closed), a
 *    return_path that is not a relative path, while a percent-encoded slug works;
 *  - amounts never come from the body, and the card-fee yes/no survives the browser's
 *    own encodings;
 *  - a double-tap gets the same page, and a changed replay is a 409;
 *  - the status read is minimal, holds the group link back until paid, is one 404
 *    across tenants, and is limited per registration, not per connection;
 *  - "Return to payment" hands back the open page, refuses a paid one, replaces an
 *    expired one on a new key, refuses cash and paid rows, and keeps the same origin
 *    rules;
 *  - a cancelled registration is never payable again: the status read says so, and
 *    "Return to payment", a replayed submit and a racing first page are all refused
 *    before Stripe is asked anything;
 *  - closeOpenSession(), the admin take-cash action's first step, closes an open page
 *    and asks Stripe again after a refused close.
 *
 * Stripe is stubbed through the service's protected seams; nothing here reaches it.
 */
class FormPaymentCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://mec.example.org';

    private const PATH = '/festival';

    private const ACCOUNT = 'acct_test_mec_festival';

    private const INVITE = 'FestivalGroup2026Xyz';

    private const UNKNOWN_UUID = '3f0b7d0e-8c7a-4d2b-9e61-0a1b2c3d4e5f';

    /** Every key the status read answers with while a registration is unpaid. */
    private const STATUS_KEYS = [
        'form_id', 'payment_status', 'payment_method', 'entry_count',
        'amount_due_minor', 'fee_covered_minor', 'total_minor', 'currency',
        'cancelled', 'can_pay', 'success_title', 'success_body', 'success_next_steps',
    ];

    /** @var array<int, array{params: array<string,mixed>, account: string, key: string, persisted: bool}> every page Stripe was asked for */
    public static array $created = [];

    /** @var array<string,string> session id => Stripe's status for it */
    public static array $pages = [];

    /** @var array<int,string> the sessions Stripe was told to expire */
    public static array $expired = [];

    /** 'paid' or 'still-open': how Stripe refuses the next close. */
    public static ?string $expireRefusal = null;

    /** Stripe refuses any page that carries customer_email. */
    public static bool $refuseEmail = false;

    /** Stripe cannot be reached. */
    public static bool $stripeDown = false;

    /** How many times the service asked Stripe about a page. */
    public static int $retrieved = 0;

    /** What another request commits while this one waits for the row lock (raceTheLock()). */
    public static ?\Closure $beforeLock = null;

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private Form $form;

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
            'forms.payment_return_origins' => [self::ORIGIN],
            'forms.submit_per_hour' => 100,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
        ]);

        Mail::fake();

        self::$created = [];
        self::$pages = [];
        self::$expired = [];
        self::$expireRefusal = null;
        self::$refuseEmail = false;
        self::$stripeDown = false;
        self::$retrieved = 0;
        self::$beforeLock = null;

        $this->stubStripe();

        $this->masjid = $this->makeMasjid(['stripe_account_id' => self::ACCOUNT, 'stripe_charges_enabled' => true]);
        $this->otherMasjid = $this->makeMasjid(['stripe_account_id' => 'acct_test_other_org', 'stripe_charges_enabled' => true]);
        $this->form = $this->makeForm($this->masjid);
    }

    // ------------------------------------------------------------------ the submit

    #[Test]
    public function a_card_registration_is_written_unpaid_and_sent_to_stripe_with_nobody_emailed(): void
    {
        $response = $this->submit()->assertOk()
            ->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.amount_due_minor', 3000)
            ->assertJsonPath('data.fee_covered_minor', 0)
            ->assertJsonPath('data.total_minor', 3000)
            ->assertJsonPath('data.currency', 'usd')
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_1');

        // The group link waits for the payment.
        $this->assertArrayNotHasKey('whatsapp_url', $response->json('data'));
        $this->assertStringNotContainsString(self::INVITE, $response->getContent());

        $row = FormResponse::sole();
        $this->assertSame($row->uuid, $response->json('data.uuid'));
        $this->assertSame(FormResponse::METHOD_ONLINE, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->payment_status);
        $this->assertNull($row->paid_at);
        $this->assertSame(3000, $row->amount_due_minor);
        $this->assertSame(0, $row->fee_covered_minor);
        $this->assertSame(3000, $row->total_minor);
        $this->assertSame('usd', $row->currency);
        $this->assertSame('30.00', (string) $row->amount_due, 'the legacy decimal is untouched');
        $this->assertSame('new', $row->status);
        $this->assertSame('cs_test_1', $row->stripe_checkout_session_id);
        $this->assertStringStartsWith('form_response_', (string) $row->idempotency_key);

        // One page, on the organisation's own account, with the key already on the row.
        $this->assertCount(1, self::$created);
        $this->assertSame(self::ACCOUNT, self::$created[0]['account']);
        $this->assertSame($row->idempotency_key, self::$created[0]['key']);
        $this->assertTrue(self::$created[0]['persisted'], 'the idempotency key must be on the row BEFORE Stripe is called');

        // Unpaid: no receipt and no coordinator email. The webhook sends both on payment.
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function the_session_is_priced_from_the_snapshot_and_carries_the_routing_keys_the_return_and_a_short_life(): void
    {
        $before = now()->getTimestamp();
        $this->submit([], $this->answers(3))->assertOk();
        $after = now()->getTimestamp();

        $row = FormResponse::sole();
        $params = self::$created[0]['params'];
        $routing = [
            'form_response_uuid' => $row->uuid,
            'masjid_id' => (string) $this->masjid->id,
            'form_id' => (string) $this->form->id,
        ];

        $this->assertSame('payment', $params['mode']);
        $this->assertSame(['card'], $params['payment_method_types'], 'card only: a bank debit would complete the page days before its money moved');
        $this->assertSame($row->uuid, $params['client_reference_id']);
        $this->assertSame($routing, $params['metadata']);
        $this->assertSame($routing, $params['payment_intent_data']['metadata'], 'the payment intent must route on its own');

        // Three attendees at $15: ONE line of three, the rows the amount was counted from.
        $this->assertSame([[
            'quantity' => 3,
            'price_data' => ['currency' => 'usd', 'unit_amount' => 1500, 'product_data' => ['name' => 'Fall Festival']],
        ]], $params['line_items']);
        FormPayment::assertLinesMatchTotal($params['line_items'], $row->total_minor);
        $this->assertSame(4500, $row->total_minor);

        // Thirty minutes (and Stripe's floor's slack), not Stripe's default day.
        $this->assertGreaterThanOrEqual($before + 30 * 60, $params['expires_at']);
        $this->assertLessThanOrEqual($after + 31 * 60, $params['expires_at']);

        $query = 'form=' . $this->form->id . '&form_response=' . $row->uuid;
        $this->assertSame(self::ORIGIN . self::PATH . '?' . $query . '&paid=1', $params['success_url']);
        $this->assertSame(self::ORIGIN . self::PATH . '?' . $query . '&cancelled=1', $params['cancel_url']);

        $this->assertSame('amal@example.com', $params['customer_email']);
        $this->assertArrayNotHasKey('application_fee_amount', $params['payment_intent_data'], 'no platform fee at 0%');
    }

    #[Test]
    public function the_line_is_named_after_the_tier_the_registration_was_priced_at(): void
    {
        $form = $this->makeForm($this->masjid, [], ['fee' => [
            'currency' => 'USD',
            'perEntryOfSection' => 'attendees',
            'tiers' => [
                ['label' => 'Early bird', 'amount' => 12, 'until' => now()->addMonth()->toDateString()],
                ['label' => 'Regular', 'amount' => 15],
            ],
        ]]);

        $this->submit([], $this->answers(2), $form)->assertOk()->assertJsonPath('data.total_minor', 2400);

        $this->assertSame([[
            'quantity' => 2,
            'price_data' => ['currency' => 'usd', 'unit_amount' => 1200, 'product_data' => ['name' => 'Fall Festival (Early bird)']],
        ]], self::$created[0]['params']['line_items']);
    }

    #[Test]
    public function the_card_fee_line_appears_only_when_the_payer_covers_it_and_the_form_offers_it(): void
    {
        $fee = StripeFees::coverage(3000);

        $this->submit(['cover_fees' => true])->assertOk()
            ->assertJsonPath('data.fee_covered_minor', $fee)
            ->assertJsonPath('data.total_minor', 3000 + $fee);

        $this->assertSame([
            ['quantity' => 2, 'price_data' => ['currency' => 'usd', 'unit_amount' => 1500, 'product_data' => ['name' => 'Fall Festival']]],
            ['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $fee, 'product_data' => ['name' => 'Card processing fee']]],
        ], self::$created[0]['params']['line_items']);

        $this->submit(['cover_fees' => false])->assertOk()->assertJsonPath('data.fee_covered_minor', 0);
        $this->assertCount(1, self::$created[1]['params']['line_items']);

        // Not offered on the form: a "yes" is ignored, since the organisation never
        // agreed to pass a fee on.
        $plain = $this->makeForm($this->masjid, [], ['payment' => ['online' => true]]);

        $this->submit(['cover_fees' => true], null, $plain)->assertOk()
            ->assertJsonPath('data.fee_covered_minor', 0)
            ->assertJsonPath('data.total_minor', 3000);
        $this->assertCount(1, self::$created[2]['params']['line_items']);
    }

    #[Test]
    public function the_platform_fee_is_sent_only_when_it_is_above_zero(): void
    {
        $this->submit()->assertOk();
        $this->assertArrayNotHasKey('application_fee_amount', self::$created[0]['params']['payment_intent_data']);

        config(['services.stripe.platform_fee_percentage' => 0.10]);

        $this->submit(['cover_fees' => true])->assertOk();

        $charged = FormResponse::query()->latest('id')->first()->total_minor;
        $this->assertSame(
            (int) round($charged * 0.10),
            self::$created[1]['params']['payment_intent_data']['application_fee_amount'],
            'computed on what the card is charged'
        );
    }

    #[Test]
    public function the_payers_email_is_prefilled_only_when_stripe_will_take_it(): void
    {
        // Passes the form's email:rfc rule; Stripe refuses a domain with no dot.
        $this->submit([], $this->answers(1, 'Amal Yusuf', 'amal@localhost'))->assertOk();
        $this->assertArrayNotHasKey('customer_email', self::$created[0]['params']);

        $this->submit([], $this->answers(1, 'Amal Yusuf', ''))->assertOk();
        $this->assertArrayNotHasKey('customer_email', self::$created[1]['params']);

        $this->submit([], $this->answers(1, 'Amal Yusuf', 'amal@example.com'))->assertOk();
        $this->assertSame('amal@example.com', self::$created[2]['params']['customer_email']);
    }

    #[Test]
    public function an_email_stripe_refuses_is_retried_once_without_it_on_a_new_key(): void
    {
        self::$refuseEmail = true;
        Log::spy();

        $this->submit()->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');

        $this->assertCount(2, self::$created);
        $this->assertSame('amal@example.com', self::$created[0]['params']['customer_email']);
        $this->assertArrayNotHasKey('customer_email', self::$created[1]['params']);
        $this->assertNotSame(self::$created[0]['key'], self::$created[1]['key'], 'a refused key belongs to the parameters it was sent with');
        $this->assertTrue(self::$created[1]['persisted']);

        $row = FormResponse::sole();
        $this->assertSame('cs_test_2', $row->stripe_checkout_session_id);
        $this->assertSame(self::$created[1]['key'], $row->idempotency_key);

        // Logged at production's level, and never with the address Stripe quoted.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'retrying once without it')
                && ! str_contains((string) json_encode($context), 'amal@example.com'))
            ->once();
    }

    #[Test]
    public function an_organisation_that_cannot_take_cards_is_refused_before_anything_is_written(): void
    {
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        $this->submit()->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);

        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, $this->form->fresh()->response_count);
        $this->assertSame([], self::$created);
        Mail::assertNothingOutgoing();

        // No connected account at all is the same refusal.
        $this->masjid->forceFill(['stripe_account_id' => null, 'stripe_charges_enabled' => true])->save();

        $this->submit()->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_return_address_off_the_allowlist_or_not_a_relative_path_is_refused_before_anything_is_written(): void
    {
        Log::spy();

        $refused = [
            'no Origin' => [null, self::PATH],
            'a stranger' => ['https://evil.example', self::PATH],
            'a look-alike' => ['https://mec.example.org.evil.example', self::PATH],
            'an origin with a path' => [self::ORIGIN . '/festival', self::PATH],
            'an opaque origin' => ['null', self::PATH],
            'plain http' => ['http://mec.example.org', self::PATH],
            'no path' => [self::ORIGIN, null],
            'an empty path' => [self::ORIGIN, ''],
            'no leading slash' => [self::ORIGIN, 'festival'],
            'protocol-relative' => [self::ORIGIN, '//evil.example/festival'],
            'an absolute URL' => [self::ORIGIN, 'https://evil.example/festival'],
            'a query' => [self::ORIGIN, '/festival?next=https://evil.example'],
            'a fragment' => [self::ORIGIN, '/festival#top'],
            'a space' => [self::ORIGIN, '/fall festival'],
            'a backslash' => [self::ORIGIN, '/\\evil.example'],
            'a broken triplet' => [self::ORIGIN, '/%zz'],
            'an array' => [self::ORIGIN, ['/festival']],
        ];

        foreach ($refused as $case => [$origin, $path]) {
            $response = $this->submit(['return_path' => $path], null, null, $origin);

            $this->assertSame(422, $response->status(), "{$case}: " . $response->getContent());
            $this->assertSame(FormPaymentReturn::REFUSED, $response->json('message'), $case);
        }

        $this->assertSame(0, FormResponse::count());
        $this->assertSame([], self::$created);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'refused a return address'))
            ->times(count($refused));

        // A percent-encoded slug (an Arabic page) is a path like any other.
        $arabic = '/ar/%D9%85%D9%87%D8%B1%D8%AC%D8%A7%D9%86';

        $this->submit(['return_path' => $arabic])->assertOk();
        $this->assertStringStartsWith(self::ORIGIN . $arabic . '?form=', self::$created[0]['params']['success_url']);

        $this->submit(['return_path' => '/'])->assertOk();
        $this->assertStringStartsWith(self::ORIGIN . '/?form=', self::$created[1]['params']['success_url']);
    }

    #[Test]
    public function with_no_allowlist_configured_no_card_payment_can_open(): void
    {
        config(['forms.payment_return_origins' => []]);

        $this->submit()->assertStatus(422)->assertJsonPath('message', FormPaymentReturn::REFUSED);

        // A wildcard is not an origin, and nothing reads it as "allow all".
        config(['forms.payment_return_origins' => ['*']]);

        $this->submit()->assertStatus(422)->assertJsonPath('message', FormPaymentReturn::REFUSED);
        $this->submit([], null, null, '*')->assertStatus(422);

        $this->assertSame(0, FormResponse::count());
        $this->assertSame([], self::$created);
    }

    #[Test]
    public function a_card_form_that_computes_nothing_owed_is_refused_with_no_row_and_no_stripe_call(): void
    {
        // A form older than the cross-check, whose attendee section demands nothing.
        $loose = $this->makeForm($this->masjid, ['schema' => $this->festivalSchema(0)]);

        $this->submit([], $this->answers(0), $loose)
            ->assertStatus(422)
            ->assertJsonPath('data.attendees.0', 'Add at least one entry.');

        // …and form-encoded, as the browser posts it, where an empty list cannot even
        // be expressed.
        $this->post("/api/v1/forms/{$loose->id}/responses", [
            'data' => ['fullName' => 'Amal Yusuf', 'email' => 'amal@example.com'],
            'return_path' => self::PATH,
            'client_submission_key' => 'empty-list-form-encoded-1',
        ], $this->headers() + ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, FormResponse::count());
        $this->assertSame([], self::$created);
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function the_body_cannot_state_its_own_price(): void
    {
        $this->submit([
            'amount_due' => 0,
            'amount_due_minor' => 1,
            'fee_covered_minor' => 0,
            'total_minor' => 1,
            'unit_minor' => 1,
            'currency' => 'jpy',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ], array_merge($this->answers(2), ['amount_due' => 0, 'total_minor' => 1]))
            ->assertOk()
            ->assertJsonPath('data.total_minor', 3000)
            ->assertJsonPath('data.payment_status', 'unpaid');

        $row = FormResponse::sole();
        $this->assertSame(3000, $row->total_minor);
        $this->assertSame('usd', $row->currency);
        $this->assertSame(FormResponse::METHOD_ONLINE, $row->payment_method);
        $this->assertNull($row->paid_at);
        $this->assertArrayNotHasKey('total_minor', $row->data, 'undeclared answers are dropped before storage');
        $this->assertSame(1500, self::$created[0]['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', self::$created[0]['params']['line_items'][0]['price_data']['currency']);
    }

    #[Test]
    public function a_double_tap_gets_the_same_payment_page_and_a_changed_replay_is_refused(): void
    {
        $key = ['client_submission_key' => 'render-key-0001'];

        $first = $this->submit($key)->assertOk();
        $second = $this->submit($key)->assertOk();

        $this->assertSame(1, FormResponse::count());
        $this->assertCount(1, self::$created, 'one page, never a second payable one');
        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertSame($first->json('data.checkout_url'), $second->json('data.checkout_url'));

        // Different answers under the same key, or a different card-fee choice: a
        // different registration, never the old page.
        $this->submit($key, $this->answers(3))->assertStatus(409);
        $this->submit($key + ['cover_fees' => true])->assertStatus(409);

        // The page has expired since: the replay gets a fresh one, for the same row.
        self::$pages['cs_test_1'] = 'expired';

        $this->submit($key)->assertOk()
            ->assertJsonPath('data.uuid', $first->json('data.uuid'))
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');

        $this->assertSame(1, FormResponse::count());
        $this->assertCount(2, self::$created);
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function the_card_fee_answer_survives_being_sent_as_strings(): void
    {
        $fee = StripeFees::coverage(3000);

        $this->submit(['cover_fees' => 'true'])->assertOk()->assertJsonPath('data.fee_covered_minor', $fee);
        $this->submit(['cover_fees' => 'false'])->assertOk()->assertJsonPath('data.fee_covered_minor', 0);

        // The coercion does not turn every unreadable value into a quiet "no".
        $this->submit(['cover_fees' => 'yes-please'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['cover_fees']]);

        $this->assertSame(2, FormResponse::count());
    }

    #[Test]
    public function a_card_registration_posted_form_encoded_like_the_browser_goes_through(): void
    {
        $fee = StripeFees::coverage(3000);
        $headers = $this->headers() + ['Accept' => 'application/json'];
        $body = fn (string $coverFees, string $key) => [
            'data' => [
                'fullName' => 'Amal Yusuf',
                'email' => 'amal@example.com',
                'attendees' => [['attendeeName' => 'Amal'], ['attendeeName' => 'Zaid']],
            ],
            'cover_fees' => $coverFees,
            'return_path' => self::PATH,
            'client_submission_key' => $key,
            'device_id' => 'browser-0001',
            'website' => '',
        ];

        $this->post("/api/v1/forms/{$this->form->id}/responses", $body('true', 'form-encoded-key-1'), $headers)
            ->assertOk()
            ->assertJsonPath('data.fee_covered_minor', $fee)
            ->assertJsonPath('data.total_minor', 3000 + $fee)
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_1');

        $this->post("/api/v1/forms/{$this->form->id}/responses", $body('false', 'form-encoded-key-2'), $headers)
            ->assertOk()
            ->assertJsonPath('data.fee_covered_minor', 0)
            ->assertJsonPath('data.total_minor', 3000);

        $this->assertCount(2, self::$created[0]['params']['line_items']);
        $this->assertCount(1, self::$created[1]['params']['line_items']);
        $this->assertSame(2, FormResponse::where('payment_status', FormResponse::PAYMENT_UNPAID)->count());
    }

    #[Test]
    public function a_stripe_failure_after_the_row_is_written_leaves_it_unpaid_and_payable_again(): void
    {
        self::$stripeDown = true;

        $response = $this->submit()->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::COULD_NOT_OPEN)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonMissingPath('data.checkout_url');

        $row = FormResponse::sole();
        $this->assertSame($row->uuid, $response->json('data.uuid'));
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->payment_status);
        $this->assertNull($row->stripe_checkout_session_id);
        Mail::assertNothingOutgoing();

        // "Try payment again", once Stripe is back.
        self::$stripeDown = false;

        $this->reopen($row->uuid)->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');
        $this->assertSame('cs_test_2', $row->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function a_staff_entry_on_a_card_form_is_cash_and_opens_no_page(): void
    {
        $form = $this->makeForm($this->masjid, [], ['payment' => ['online' => true, 'staffCodes' => true, 'allowFeeCoverage' => true]]);

        FormStaffCode::factory()->withCode('K7QM-2XWD')->create([
            'form_id' => $form->id,
            'masjid_id' => $this->masjid->id,
            'holder_name' => 'Najd Haddad',
            'expires_at' => now()->addDay(),
        ]);

        $token = $this->postJson("/api/v1/forms/{$form->id}/staff-session", [
            'staff_code' => 'K7QM-2XWD',
            'device_id' => 'phone-najd-0001',
        ], ['masjid-id' => (string) $this->masjid->id])->assertOk()->json('data.staff_token');

        // No Origin, no return path, and the fee box ticked: none of it applies to cash.
        $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => $this->answers(),
            'staff_token' => $token,
            'device_id' => 'phone-najd-0001',
            'cover_fees' => true,
            'client_submission_key' => 'card-form-cash-entry-1',
        ], ['masjid-id' => (string) $this->masjid->id])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.fee_covered_minor', 0)
            ->assertJsonPath('data.total_minor', 3000)
            ->assertJsonMissingPath('data.checkout_url');

        $this->assertSame([], self::$created);
    }

    // ------------------------------------------------------------- the status read

    #[Test]
    public function the_status_read_is_minimal_and_holds_the_group_link_back_until_paid(): void
    {
        $uuid = $this->submit()->assertOk()->json('data.uuid');

        $unpaid = $this->readStatus($uuid)->assertOk()
            ->assertJsonPath('data.form_id', $this->form->id)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.entry_count', 2)
            ->assertJsonPath('data.total_minor', 3000)
            ->assertJsonPath('data.can_pay', true)
            ->assertJsonPath('data.success_title', 'See you at the festival');

        $this->assertEqualsCanonicalizing(self::STATUS_KEYS, array_keys($unpaid->json('data')));

        // A bearer handle: nothing about who registered, and no group link yet.
        $this->assertNowhereIn($unpaid->getContent(), ['Amal Yusuf', 'amal@example.com', 'Guest 1', self::INVITE, 'cs_test_1']);

        FormResponse::sole()->markPaid('pi_test_1');

        $paid = $this->readStatus($uuid)->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.whatsapp_url', 'https://chat.whatsapp.com/' . self::INVITE)
            ->assertJsonPath('data.whatsapp_label', 'Join the festival group');

        $this->assertEqualsCanonicalizing([...self::STATUS_KEYS, 'whatsapp_url', 'whatsapp_label'], array_keys($paid->json('data')));
        $this->assertNowhereIn($paid->getContent(), ['Amal Yusuf', 'amal@example.com', 'pi_test_1']);
    }

    #[Test]
    public function the_status_read_is_one_404_for_unknown_foreign_and_moneyless_registrations(): void
    {
        $uuid = $this->submit()->assertOk()->json('data.uuid');

        $unknown = $this->readStatus(self::UNKNOWN_UUID)->assertNotFound();
        $foreign = $this->readStatus($uuid, $this->otherMasjid)->assertNotFound();
        $this->assertSame($unknown->getContent(), $foreign->getContent(), 'the same bytes, so nobody can probe which uuids exist where');

        // A free form's row has a uuid but no money leg, and nothing to report here.
        $free = $this->makeForm($this->masjid, [], ['fee' => null, 'payment' => null]);
        $this->submit([], null, $free)->assertOk();
        $freeUuid = FormResponse::where('form_id', $free->id)->sole()->uuid;

        $this->assertSame($unknown->getContent(), $this->readStatus($freeUuid)->assertNotFound()->getContent());

        // An offboarded organisation's registrations are not public either.
        $this->masjid->delete();
        $this->assertSame($unknown->getContent(), $this->readStatus($uuid)->assertNotFound()->getContent());

        $this->getJson("/api/v1/form-responses/{$uuid}")->assertStatus(400);
        $this->getJson('/api/v1/form-responses/not-a-uuid', ['masjid-id' => (string) $this->otherMasjid->id])->assertNotFound();
    }

    #[Test]
    public function the_status_read_is_limited_per_registration_not_per_connection(): void
    {
        $first = $this->submit()->assertOk()->json('data.uuid');
        $second = $this->submit()->assertOk()->json('data.uuid');

        for ($i = 1; $i <= 30; $i++) {
            $this->readStatus($first)->assertOk();
        }

        // The hour runs from the first read, and the answer says how much of it is left.
        $this->readStatus($first)->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many requests. Please try again in 60 minutes.');

        // The same connection, another registration: its own bucket.
        $this->readStatus($second)->assertOk();
    }

    // --------------------------------------------------------- return to payment

    #[Test]
    public function return_to_payment_hands_back_the_page_that_is_still_open(): void
    {
        $submitted = $this->submit()->assertOk();
        $row = FormResponse::sole();
        $key = $row->idempotency_key;

        $this->reopen($row->uuid)->assertOk()
            ->assertJsonPath('data.checkout_url', $submitted->json('data.checkout_url'))
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertCount(1, self::$created, 'the same page, never a second payable one');
        $row->refresh();
        $this->assertSame('cs_test_1', $row->stripe_checkout_session_id);
        $this->assertSame($key, $row->idempotency_key);
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function return_to_payment_replaces_an_expired_page_with_a_new_one_on_a_new_key(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();
        $oldKey = $row->idempotency_key;
        self::$pages['cs_test_1'] = 'expired';

        $this->reopen($row->uuid)->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');

        $row->refresh();
        $this->assertCount(2, self::$created);
        $this->assertSame('cs_test_2', $row->stripe_checkout_session_id);
        $this->assertNotSame($oldKey, $row->idempotency_key, 'Stripe would replay the old key');
        $this->assertSame($row->idempotency_key, self::$created[1]['key']);
        $this->assertTrue(self::$created[1]['persisted']);
        $this->assertSame([], self::$expired, 'an expired page needs no closing');
    }

    #[Test]
    public function return_to_payment_refuses_a_page_stripe_says_was_paid(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();
        self::$pages['cs_test_1'] = 'complete';

        $this->reopen($row->uuid)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::PAID_ON_STRIPE)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonMissingPath('data.checkout_url');

        $this->assertCount(1, self::$created);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->fresh()->payment_status, 'only the webhook marks it paid');
    }

    #[Test]
    public function a_page_stripe_says_was_paid_is_answered_as_confirming_and_never_payable(): void
    {
        // The payer paid on Stripe, the redirect back was lost on the venue wifi, and the
        // webhook has not landed yet.
        $key = ['client_submission_key' => 'render-key-confirming'];
        $this->submit($key)->assertOk();
        $row = FormResponse::sole();
        self::$pages['cs_test_1'] = 'complete';

        // "Return to payment"…
        $this->reopen($row->uuid)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::PAID_ON_STRIPE)
            ->assertJsonPath('data.confirming', true)
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonMissingPath('data.checkout_url');

        // …and the same submit sent again from the page the browser kept.
        $this->submit($key)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::PAID_ON_STRIPE)
            ->assertJsonPath('data.confirming', true)
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.uuid', $row->uuid)
            ->assertJsonMissingPath('data.checkout_url');

        // The status read the page polls says what the row says, until the webhook lands.
        $this->readStatus($row->uuid)->assertOk()->assertJsonMissingPath('data.confirming');

        // Once it has, every refusal is a plain "paid", never "confirming".
        $row->markPaid('pi_test_1');

        $this->reopen($row->uuid)->assertStatus(422)
            ->assertJsonPath('message', 'This registration has already been paid.')
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonMissingPath('data.confirming');
        $this->submit($key)->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonMissingPath('data.confirming');

        $this->assertCount(1, self::$created, 'no second page');
    }

    #[Test]
    public function return_to_payment_refuses_cash_and_paid_registrations(): void
    {
        $this->submit()->assertOk();
        $card = FormResponse::sole();
        $card->markPaid('pi_test_1');

        $this->reopen($card->uuid)->assertStatus(422)->assertJsonPath('message', 'This registration has already been paid.');

        $cash = $this->cashRow();
        $this->reopen($cash->uuid)->assertStatus(422)->assertJsonPath('message', 'This registration has already been paid.');

        $this->assertCount(1, self::$created);
    }

    #[Test]
    public function a_cancelled_registration_is_never_payable_again(): void
    {
        $key = ['client_submission_key' => 'render-key-cancelled'];
        $this->submit($key)->assertOk();
        $row = FormResponse::sole();

        // Declined at home, then walked up and paid cash at the gate: an admin cancels the
        // card registration as the duplicate.
        $row->update(['status' => FormResponse::STATUS_CANCELLED]);

        $this->readStatus($row->uuid)->assertOk()->assertJsonPath('data.can_pay', false);

        $this->reopen($row->uuid)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::CANCELLED)
            ->assertJsonMissingPath('data.checkout_url');

        // Nor once its page has expired, when "Return to payment" would otherwise open a
        // fresh one…
        self::$pages['cs_test_1'] = 'expired';

        $this->reopen($row->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::CANCELLED);

        // …nor by replaying the submit…
        $this->submit($key)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::CANCELLED);

        // …nor by a first page racing the cancel.
        try {
            app(FormResponseCheckoutService::class)->checkout($row, self::ORIGIN . self::PATH);
            $this->fail('a cancelled registration must never get a payment page');
        } catch (FormCheckoutRefused $e) {
            $this->assertSame(FormResponseCheckoutService::CANCELLED, $e->getMessage());
        }

        $this->assertCount(1, self::$created, 'the page the submit opened, and no other');
        $this->assertSame(0, self::$retrieved, 'refused before Stripe is asked anything');
        $this->assertSame([], self::$expired);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->fresh()->payment_status);
    }

    #[Test]
    public function a_cancelled_registration_says_so_and_never_carries_the_group_link(): void
    {
        $key = ['client_submission_key' => 'render-key-refunded'];
        $this->submit($key)->assertOk()->assertJsonPath('data.cancelled', false);
        $row = FormResponse::sole();
        $row->markPaid('pi_test_1');

        $this->readStatus($row->uuid)->assertOk()
            ->assertJsonPath('data.cancelled', false)
            ->assertJsonPath('data.whatsapp_url', 'https://chat.whatsapp.com/' . self::INVITE);

        // An admin cancels it and refunds it in Stripe. The payer later opens the return
        // link from their browser history.
        FormResponse::query()->whereKey($row->id)->update(['status' => FormResponse::STATUS_CANCELLED]);

        $read = $this->readStatus($row->uuid)->assertOk()
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.can_pay', false);

        $this->assertEqualsCanonicalizing(self::STATUS_KEYS, array_keys($read->json('data')), 'no group link');

        // The same submit sent again from the page the browser kept: the first row's
        // answer, which says cancelled and carries no link.
        $replay = $this->submit($key)->assertOk()
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonMissingPath('data.whatsapp_url')
            ->assertJsonMissingPath('data.whatsapp_label');

        $this->assertNowhereIn($read->getContent() . $replay->getContent(), [self::INVITE]);

        // Cancelled before it was paid: every answer says so, the refusals included.
        $unpaidKey = ['client_submission_key' => 'render-key-cancelled-unpaid'];
        $this->submit($unpaidKey)->assertOk();
        $unpaid = FormResponse::query()->latest('id')->first();
        FormResponse::query()->whereKey($unpaid->id)->update(['status' => FormResponse::STATUS_CANCELLED]);

        $this->readStatus($unpaid->uuid)->assertOk()
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.can_pay', false);
        $this->reopen($unpaid->uuid)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::CANCELLED)
            ->assertJsonPath('data.cancelled', true);
        $this->submit($unpaidKey)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::CANCELLED)
            ->assertJsonPath('data.cancelled', true);

        // A free form's answer keeps the keys it always had, and loses the link once an
        // admin cancels the registration.
        $free = $this->makeForm($this->masjid, [], ['fee' => null, 'payment' => null]);
        $freeKey = ['client_submission_key' => 'render-key-free'];

        $this->submit($freeKey, null, $free)->assertOk()
            ->assertJsonPath('data.whatsapp_url', 'https://chat.whatsapp.com/' . self::INVITE)
            ->assertJsonMissingPath('data.cancelled');

        FormResponse::query()->where('form_id', $free->id)->update(['status' => FormResponse::STATUS_CANCELLED]);

        $this->submit($freeKey, null, $free)->assertOk()->assertJsonMissingPath('data.whatsapp_url');
    }

    #[Test]
    public function a_refused_return_to_payment_describes_the_registration_as_the_lock_found_it(): void
    {
        $paidByHand = fn (FormResponse $row) => FormResponse::query()->whereKey($row->getKey())->update([
            'payment_method' => FormResponse::METHOD_CASH,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'fee_covered_minor' => 0,
            'total_minor' => 3000,
        ]);

        // An admin's "Take cash" commits while the payer's "Return to payment" waits for
        // the row.
        $this->submit()->assertOk();
        $row = FormResponse::sole();
        self::$beforeLock = $paidByHand;

        $this->reopen($row->uuid)->assertStatus(422)
            ->assertJsonPath('message', 'This registration has already been paid.')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.whatsapp_url', 'https://chat.whatsapp.com/' . self::INVITE)
            ->assertJsonMissingPath('data.checkout_url');

        // A cancel racing the same way never offers "Return to payment" beside its refusal.
        $this->submit()->assertOk();
        $cancelled = FormResponse::query()->latest('id')->first();
        self::$beforeLock = fn (FormResponse $row) => FormResponse::query()->whereKey($row->getKey())
            ->update(['status' => FormResponse::STATUS_CANCELLED]);

        $this->reopen($cancelled->uuid)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::CANCELLED)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.can_pay', false);

        // The submit's replay reads the row the same way.
        $key = ['client_submission_key' => 'render-key-raced'];
        $this->submit($key)->assertOk();
        self::$beforeLock = $paidByHand;

        $this->submit($key)->assertStatus(422)
            ->assertJsonPath('message', 'This registration has already been paid.')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.whatsapp_url', 'https://chat.whatsapp.com/' . self::INVITE);

        $this->assertCount(3, self::$created, 'one page per submit, and none for a refusal');
    }

    #[Test]
    public function return_to_payment_keeps_the_submits_origin_rules_and_is_one_404_across_tenants(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();

        // Expired, so any request that got through WOULD open a page.
        self::$pages['cs_test_1'] = 'expired';

        $this->reopen($row->uuid, origin: 'https://evil.example')->assertStatus(422)->assertJsonPath('message', FormPaymentReturn::REFUSED);
        $this->reopen($row->uuid, origin: null)->assertStatus(422)->assertJsonPath('message', FormPaymentReturn::REFUSED);
        $this->reopen($row->uuid, path: '//evil.example')->assertStatus(422)->assertJsonPath('message', FormPaymentReturn::REFUSED);
        $this->reopen($row->uuid, masjid: $this->otherMasjid)->assertNotFound();
        $this->reopen(self::UNKNOWN_UUID)->assertNotFound();

        $this->assertCount(1, self::$created, 'nothing opened on a refused address');

        // Connect switched off since: refused, not a page on an account that cannot take it.
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        $this->reopen($row->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->readStatus($row->uuid)->assertOk()->assertJsonPath('data.can_pay', false);
        $this->assertCount(1, self::$created);
    }

    #[Test]
    public function return_to_payment_has_its_own_limit_per_registration(): void
    {
        $uuid = $this->submit()->assertOk()->json('data.uuid');

        for ($i = 1; $i <= 20; $i++) {
            $this->reopen($uuid)->assertOk();
        }

        $this->reopen($uuid)->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many payment attempts. Please try again in 60 minutes.');
        $this->assertCount(1, self::$created, 'twenty presses, one page');

        // The same connection, another registration: its own twenty. A limit per
        // connection would refuse this one as well.
        $second = $this->submit()->assertOk()->json('data.uuid');
        $this->reopen($second)->assertOk();
    }

    #[Test]
    public function made_up_uuids_on_the_venue_wifi_never_stop_a_payer_reading_their_own(): void
    {
        $uuid = $this->submit()->assertOk()->json('data.uuid');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        // Someone on the same wifi reads made-up registrations until the per-connection
        // guard shuts…
        for ($i = 1; $i <= 300; $i++) {
            $this->readStatus((string) Str::uuid())->assertNotFound();
        }

        $this->readStatus((string) Str::uuid())->assertStatus(429);

        // …while the payer behind the same address reads theirs against its own thirty,
        // none of which the flood spent.
        for ($i = 1; $i <= 30; $i++) {
            $this->readStatus($uuid)->assertOk();
        }

        $this->readStatus($uuid)->assertStatus(429);
    }

    #[Test]
    public function made_up_uuids_never_stop_a_payer_returning_to_payment_nor_spend_their_twenty(): void
    {
        $uuid = $this->submit()->assertOk()->json('data.uuid');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        for ($i = 1; $i <= 120; $i++) {
            $this->reopen((string) Str::uuid())->assertNotFound();
        }

        $this->reopen((string) Str::uuid())->assertStatus(429);

        for ($i = 1; $i <= 20; $i++) {
            $this->reopen($uuid)->assertOk();
        }

        $this->reopen($uuid)->assertStatus(429);
        $this->assertCount(1, self::$created, 'twenty presses, one page');
    }

    #[Test]
    public function a_second_checkout_for_a_row_that_already_has_an_open_page_hands_that_page_back(): void
    {
        // The double-tap that raced past the replay guard reaches checkout() second:
        // under the row lock it finds the first request's page and gets that one.
        $this->submit()->assertOk();
        $row = FormResponse::sole();

        $again = app(FormResponseCheckoutService::class)->checkout($row, self::ORIGIN . self::PATH);

        $this->assertSame('https://checkout.stripe.test/pay/cs_test_1', $again['checkout_url']);
        $this->assertCount(1, self::$created);
    }

    // ------------------------------------------------ closing a page to take cash

    #[Test]
    public function closing_the_open_page_for_an_admin_taking_cash_expires_it_once(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();
        $service = app(FormResponseCheckoutService::class);

        $this->assertSame('expired', $service->closeOpenSession($row));
        $this->assertSame(['cs_test_1'], self::$expired);
        $this->assertSame('cs_test_1', $row->fresh()->stripe_checkout_session_id, 'kept, so a late payment is still recorded against it');

        $this->assertSame('expired', $service->closeOpenSession($row));
        $this->assertSame(['cs_test_1'], self::$expired, 'a closed page is not closed twice');
    }

    #[Test]
    public function a_page_paid_a_moment_before_the_close_is_complete_and_one_still_open_is_an_error(): void
    {
        $this->submit()->assertOk();
        $service = app(FormResponseCheckoutService::class);

        // Stripe refuses the close because the payer has just paid; asked again, it says so.
        self::$expireRefusal = 'paid';
        $this->assertSame('complete', $service->closeOpenSession(FormResponse::sole()));

        // Refused and still open: the page may still be payable, so it is never
        // reported closed.
        $this->submit()->assertOk();
        $open = FormResponse::query()->latest('id')->first();
        self::$expireRefusal = 'still-open';

        try {
            $service->closeOpenSession($open);
            $this->fail('a page that is still open must never be reported closed');
        } catch (FormCheckoutRefused) {
            $this->assertSame('open', self::$pages[$open->stripe_checkout_session_id]);
        }
    }

    #[Test]
    public function there_is_nothing_to_close_without_a_page_or_once_paid(): void
    {
        $service = app(FormResponseCheckoutService::class);

        $this->assertNull($service->closeOpenSession($this->cashRow()));

        $this->submit()->assertOk();
        $card = FormResponse::where('payment_method', FormResponse::METHOD_ONLINE)->sole();
        $card->markPaid('pi_test_1');

        $this->assertNull($service->closeOpenSession($card));
        $this->assertSame([], self::$expired);
    }

    #[Test]
    public function both_public_routes_carry_their_own_named_limiter(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/form-responses/'))
            ->mapWithKeys(fn ($route) => [$route->methods()[0] . ' ' . $route->uri() => $route->gatherMiddleware()]);

        $this->assertCount(2, $routes);
        $this->assertContains('throttle:form-status', $routes['GET api/v1/form-responses/{uuid}']);
        $this->assertContains('throttle:form-checkout', $routes['POST api/v1/form-responses/{uuid}/checkout']);
    }

    // ------------------------------------------------------------------- helpers

    /** @param  array<string,mixed>  $extra  cover_fees, return_path, a client key… (a fresh key per call unless one is given) */
    private function submit(array $extra = [], ?array $data = null, ?Form $form = null, ?string $origin = self::ORIGIN): TestResponse
    {
        $form ??= $this->form;

        return $this->postJson("/api/v1/forms/{$form->id}/responses", array_merge([
            'data' => $data ?? $this->answers(),
            'return_path' => self::PATH,
            // One per render, as the renderer sends it: a form that takes payment requires it.
            'client_submission_key' => (string) Str::uuid(),
        ], $extra), $this->headers($origin, (int) $form->masjid_id));
    }

    private function readStatus(string $uuid, ?Masjid $masjid = null): TestResponse
    {
        return $this->getJson("/api/v1/form-responses/{$uuid}", ['masjid-id' => (string) ($masjid ?? $this->masjid)->id]);
    }

    private function reopen(string $uuid, ?string $origin = self::ORIGIN, string $path = self::PATH, ?Masjid $masjid = null): TestResponse
    {
        return $this->postJson(
            "/api/v1/form-responses/{$uuid}/checkout",
            ['return_path' => $path],
            $this->headers($origin, (int) ($masjid ?? $this->masjid)->id)
        );
    }

    /** @return array<string,string> */
    private function headers(?string $origin = self::ORIGIN, ?int $masjidId = null): array
    {
        $headers = ['masjid-id' => (string) ($masjidId ?? $this->masjid->id)];

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        return $headers;
    }

    private function answers(int $attendees = 2, string $name = 'Amal Yusuf', string $email = 'amal@example.com'): array
    {
        $rows = [];

        for ($i = 1; $i <= $attendees; $i++) {
            $rows[] = ['attendeeName' => "Guest {$i}"];
        }

        return ['fullName' => $name, 'email' => $email, 'attendees' => $rows];
    }

    /** The festival's shape: you, then the attendees the $15 is charged per entry of. */
    private function festivalSchema(int $minEntries = 1): array
    {
        return ['sections' => [
            ['id' => 'contact', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
            ]],
            ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => $minEntries, 'maxEntries' => 10, 'fields' => [
                ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ]],
        ]];
    }

    /** $15 per attendee, card payment on with the fee offered, a group link for the paid. */
    private function makeForm(Masjid $masjid, array $overrides = [], array $settings = []): Form
    {
        return Form::create(array_merge([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => $this->festivalSchema(),
            'settings' => array_replace([
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'notifyEmails' => ['festival-office@mec.test'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true, 'allowFeeCoverage' => true],
                'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE,
                'whatsappLabel' => 'Join the festival group',
                'successTitle' => 'See you at the festival',
                'successBody' => 'Your registration is confirmed.',
                'successNextSteps' => ['Bring your receipt to the gate.'],
            ], $settings),
            'is_active' => true,
        ], $overrides));
    }

    /** A walk-up a staff member took cash for. */
    private function cashRow(): FormResponse
    {
        $row = new FormResponse([
            'form_id' => $this->form->id,
            'masjid_id' => $this->masjid->id,
            'data' => $this->answers(),
            'entry_count' => 2,
            'amount_due' => 30,
            'status' => 'new',
            'submitted_at' => now(),
        ]);

        $row->forceFill([
            'payment_method' => FormResponse::METHOD_CASH,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'currency' => 'usd',
            'amount_due_minor' => 3000,
            'total_minor' => 3000,
        ])->save();

        return $row;
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
        ], $overrides));
    }

    /** @param  array<int,string>  $needles */
    private function assertNowhereIn(string $haystack, array $needles): void
    {
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $haystack);
        }
    }

    /**
     * Run self::$beforeLock once, if a test set it: what another request (an admin taking
     * cash, a cancel) commits after this one read the row and before it takes the lock.
     */
    public static function raceTheLock(FormResponse $response): void
    {
        $race = self::$beforeLock;
        self::$beforeLock = null;

        if ($race !== null) {
            $race($response);
        }
    }

    /**
     * Stripe, as far as the service can tell: pages numbered cs_test_1, cs_test_2…,
     * each open until a test says otherwise. Every create records whether the key it
     * was sent with was already on the row, which is the whole point of persisting it
     * first.
     */
    private function stubStripe(): void
    {
        $this->app->bind(FormResponseCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
            {
                public function checkout(FormResponse $response, string $returnTo): array
                {
                    FormPaymentCheckoutTest::raceTheLock($response);

                    return parent::checkout($response, $returnTo);
                }

                public function reopen(FormResponse $response, string $returnTo): array
                {
                    FormPaymentCheckoutTest::raceTheLock($response);

                    return parent::reopen($response, $returnTo);
                }

                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    $n = count(FormPaymentCheckoutTest::$created) + 1;

                    FormPaymentCheckoutTest::$created[] = [
                        'params' => $params,
                        'account' => $connectedAccountId,
                        'key' => $idempotencyKey,
                        'persisted' => FormResponse::query()
                            ->where('uuid', $params['client_reference_id'] ?? null)
                            ->where('idempotency_key', $idempotencyKey)
                            ->exists(),
                    ];

                    if (FormPaymentCheckoutTest::$stripeDown) {
                        throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                    }

                    if (FormPaymentCheckoutTest::$refuseEmail && isset($params['customer_email'])) {
                        throw \Stripe\Exception\InvalidRequestException::factory('Invalid email address: ' . $params['customer_email'], 400);
                    }

                    FormPaymentCheckoutTest::$pages["cs_test_{$n}"] = 'open';

                    return ['id' => "cs_test_{$n}", 'url' => "https://checkout.stripe.test/pay/cs_test_{$n}", 'payment_intent' => null];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    FormPaymentCheckoutTest::$retrieved++;

                    $status = FormPaymentCheckoutTest::$pages[$sessionId] ?? 'expired';

                    return ['status' => $status, 'url' => $status === 'open' ? "https://checkout.stripe.test/pay/{$sessionId}" : null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    switch (FormPaymentCheckoutTest::$expireRefusal) {
                        case 'paid':
                            FormPaymentCheckoutTest::$pages[$sessionId] = 'complete';

                            throw \Stripe\Exception\InvalidRequestException::factory('This Checkout Session is not in an expirable state.');
                        case 'still-open':
                            throw \Stripe\Exception\InvalidRequestException::factory('Refused.');
                    }

                    FormPaymentCheckoutTest::$expired[] = $sessionId;
                    FormPaymentCheckoutTest::$pages[$sessionId] = 'expired';
                }
            };
        });
    }
}
