<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Services\Stripe\FormResponsePaymentService;
use App\Support\FormNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * What linking BISS's FORM card payments to Burlington Masjid's account does NOT change
 * (DECISIONS.md 2026-09-15):
 *
 *  - BISS still takes no donation (and, through the same canAcceptDonations() gate, no
 *    lunch order and no offering);
 *  - an organisation that is not linked, the holder included, opens exactly the session it
 *    always did, pins nothing, and publishes exactly the payment keys it always did;
 *  - a receipt of a registration paid on its own organisation's account reads as before.
 */
class FormLinkedIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://burlingtonmasjid.example.org';

    private const HOLDER_ACCOUNT = 'acct_1BurlingtonIsolation';

    /** @var array<int, array{params: array<string,mixed>, account: string}> */
    public static array $created = [];

    private Masjid $holder;

    private Masjid $biss;

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
        $this->stubStripe();

        $this->holder = $this->makeOrg('Burlington Masjid', ['stripe_account_id' => self::HOLDER_ACCOUNT, 'stripe_charges_enabled' => true]);
        $this->biss = $this->makeOrg('Burlington Islamic Sunday School');
        DB::table('masjids')->where('id', $this->biss->id)->update(['parent_id' => $this->holder->id, 'forms_card_via_masjid_id' => $this->holder->id]);
    }

    #[Test]
    public function a_linked_school_still_takes_no_donation(): void
    {
        $biss = $this->biss->fresh();
        $this->assertFalse($biss->canAcceptDonations());

        $fund = Fund::create([
            'masjid_id' => $biss->id,
            'name' => 'General Fund',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);

        $this->postJson("/api/mobile/masjids/{$biss->id}/donations/checkout", ['fund_id' => $fund->id, 'amount' => 5000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This masjid is not able to accept online donations yet.');

        $this->assertDatabaseCount('donations', 0);
    }

    #[Test]
    public function the_holders_own_form_opens_exactly_the_session_it_always_did_and_pins_nothing(): void
    {
        $form = $this->makeForm($this->holder);

        $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => ['fullName' => 'Amal Yusuf', 'email' => 'amal@example.com'],
            'return_path' => '/festival',
            'client_submission_key' => (string) Str::uuid(),
        ], ['masjid-id' => (string) $this->holder->id, 'Origin' => self::ORIGIN])->assertOk();

        $row = FormResponse::sole();
        $this->assertCount(1, self::$created);
        $params = self::$created[0]['params'];
        $routing = [
            'form_response_uuid' => $row->uuid,
            'masjid_id' => (string) $this->holder->id,
            'form_id' => (string) $form->id,
        ];

        $this->assertSame(self::HOLDER_ACCOUNT, self::$created[0]['account']);
        $this->assertSame(
            ['mode', 'payment_method_types', 'client_reference_id', 'metadata', 'line_items', 'payment_intent_data', 'expires_at', 'success_url', 'cancel_url', 'customer_email'],
            array_keys($params)
        );
        $this->assertSame($row->uuid, $params['client_reference_id']);
        $this->assertSame($routing, $params['metadata']);
        $this->assertSame(['metadata' => $routing], $params['payment_intent_data'], 'no description, no statement suffix, no reference');

        $this->assertArrayNotHasKey('adaptive_pricing', $params, 'Adaptive Pricing is left to the account for an unlinked charge');

        foreach (['charge_account_id', 'charge_masjid_id', 'charge_ref', 'charge_expires_at', 'charge_flag', 'charge_flagged_at', 'charge_refunded_minor'] as $column) {
            $this->assertNull($row->getAttribute($column), "{$column} is written only for a linked charge");
        }

        // Paid on its own account: the receipt line reads exactly as before.
        $row->markPaid('pi_own_1');
        $this->assertSame('Paid $100.00 by card', FormNotifier::paymentLine($row->fresh()));
    }

    #[Test]
    public function only_a_linked_forms_payload_gains_card_charged_by_and_it_never_carries_an_account(): void
    {
        $paymentKeys = ['online', 'available', 'allowFeeCoverage', 'staffEntry', 'unitMinor', 'currency', 'stripeFeePercentage', 'stripeFeeFixedMinor', 'requireFeeCoverage', 'officePayment', 'officeInstructions', 'countTiers'];

        $holderPage = $this->page($this->makeForm($this->holder), $this->holder);
        $this->assertSame($paymentKeys, array_keys($holderPage->json('data.sections.0.content.form.settings.payment')));

        $bissForm = $this->makeForm($this->biss);
        $bissPage = $this->page($bissForm, $this->biss);
        $payment = $bissPage->json('data.sections.0.content.form.settings.payment');

        $this->assertSame([...$paymentKeys, 'cardChargedBy'], array_keys($payment));
        $this->assertTrue($payment['available']);
        $this->assertSame('Burlington Masjid', $payment['cardChargedBy']);
        $this->assertStringNotContainsString('acct_', $bissPage->getContent());

        // Unlinked: the key is gone and card is unavailable.
        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => null]);
        $payment = $this->page($bissForm, $this->biss)->json('data.sections.0.content.form.settings.payment');
        $this->assertSame($paymentKeys, array_keys($payment));
        $this->assertFalse($payment['available']);
    }

    #[Test]
    public function a_charge_reference_is_a_form_event_and_every_other_object_keeps_its_route(): void
    {
        $this->assertTrue(FormResponsePaymentService::isFormResponseEvent(['metadata' => ['form_charge_ref' => 'fcr_x']]));
        $this->assertTrue(FormResponsePaymentService::isFormResponseEvent(['metadata' => ['form_response_uuid' => (string) Str::uuid()]]));
        $this->assertFalse(FormResponsePaymentService::isFormResponseEvent(['metadata' => ['form_charge_ref' => '']]));
        $this->assertFalse(FormResponsePaymentService::isFormResponseEvent(['metadata' => ['donation_uuid' => (string) Str::uuid()]]));
        $this->assertFalse(FormResponsePaymentService::isFormResponseEvent([]));
    }

    // ------------------------------------------------------------------- helpers

    private function page(Form $form, Masjid $masjid): TestResponse
    {
        $page = Page::create([
            'masjid_id' => $masjid->id,
            'slug' => 'register-' . uniqid(),
            'title' => 'Register',
            'is_active' => true,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $masjid->id,
            'section_type' => 'form',
            'title' => 'Registration',
            'content' => ['form_id' => $form->id],
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $this->withHeader('masjid-id', (string) $masjid->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertOk();
    }

    private function makeForm(Masjid $masjid): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'enrol-' . uniqid(),
            'name' => 'Registration 2026',
            'schema' => ['sections' => [['id' => 'contact', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
            ]]]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'notifyEmails' => ['office@example.org'],
                'fee' => ['amount' => 100, 'currency' => 'USD'],
                'payment' => ['online' => true],
            ],
            'is_active' => true,
        ]);
    }

    private function makeOrg(string $name, array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => $name,
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    private function stubStripe(): void
    {
        $this->app->bind(FormResponseCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    $n = count(FormLinkedIsolationTest::$created) + 1;
                    FormLinkedIsolationTest::$created[] = ['params' => $params, 'account' => $connectedAccountId];

                    return ['id' => "cs_iso_{$n}", 'url' => "https://checkout.stripe.test/pay/cs_iso_{$n}", 'payment_intent' => null];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    return ['status' => 'open', 'url' => "https://checkout.stripe.test/pay/{$sessionId}"];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                }
            };
        });
    }
}
