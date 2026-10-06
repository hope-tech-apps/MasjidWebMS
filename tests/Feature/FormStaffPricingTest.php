<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\Forms\StoreFormRequest;
use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Services\Stripe\FormResponsePaymentService;
use App\Support\FormCashTotals;
use App\Support\FormInsights;
use App\Support\FormNotifier;
use App\Support\FormStaffCodes;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

class FormStaffPricingTest extends TestCase
{
    use RefreshDatabase;

    public static array $pages = [];

    private Form $form;

    private FormStaffCode $code;

    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        config(['forms.payment_return_origins' => [config('app.url')]]);
        Mail::fake();
        self::$pages = [];
        $this->email = fake()->safeEmail();
        $org = Masjid::create([
            'name' => 'Test organisation', 'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(), 'country_id' => '1', 'city_id' => '1',
            'address' => fake()->address(), 'latitude' => 0, 'longitude' => 0,
        ]);
        $org->forceFill(['stripe_account_id' => 'acct_test_staff', 'stripe_charges_enabled' => true])->save();
        $this->form = Form::create([
            'masjid_id' => $org->id, 'name' => 'Test entry form', 'slug' => 'staff-entry', 'is_active' => true,
            'schema' => ['sections' => [
                ['id' => 'contact', 'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
                ]],
                ['id' => 'attendees', 'repeatable' => true, 'minEntries' => 1, 'maxEntries' => 10, 'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => [
                'identity' => ['name' => 'name', 'email' => 'email'], 'notifyEmails' => [fake()->unique()->safeEmail()],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true, 'staffCodes' => true, 'staffPriceOverride' => true, 'allowFeeCoverage' => true],
            ],
        ]);
        $this->code = FormStaffCode::factory()->withCode('K7QM-2XWD')->create([
            'form_id' => $this->form->id, 'masjid_id' => $org->id,
            'holder_name' => 'Staff member', 'expires_at' => now()->addDay(),
        ]);
        $this->app->bind(FormResponseCheckoutService::class, fn ($app) => new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
        {
            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                $id = 'cs_test_staff_'.(count(FormStaffPricingTest::$pages) + 1);
                FormStaffPricingTest::$pages[$id] = ['params' => $params, 'account' => $connectedAccountId, 'key' => $idempotencyKey];

                return ['id' => $id, 'url' => config('app.url').'/checkout/'.$id, 'payment_intent' => null];
            }

            protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
            {
                $status = FormStaffPricingTest::$pages[$sessionId]['status'] ?? 'open';

                return ['status' => $status, 'url' => $status === 'open' ? config('app.url').'/checkout/'.$sessionId : null];
            }

            protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void {}
        });
    }

    #[Test]
    public function audit_columns_are_nullable_guarded_and_do_not_reprice_legacy_rows(): void
    {
        $fields = ['list_unit_price_minor', 'staff_unit_price_minor', 'staff_holder_name', 'staff_payment_method'];
        $this->assertTrue(Schema::hasColumns('form_responses', $fields));
        $row = FormResponse::create(['form_id' => $this->form->id, 'masjid_id' => $this->form->masjid_id, 'data' => [], 'submitted_at' => now(), 'amount_due' => '15.00']);
        foreach ($fields as $field) {
            $this->assertNull($row->fresh()->getAttribute($field));
            $this->assertFalse($row->isFillable($field));
        }
        $this->assertSame('15.00', $row->fresh()->amount_due);
        $migration = require database_path('migrations/2026_10_06_000001_add_staff_pricing_to_form_responses.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('form_responses', 'list_unit_price_minor'));
        $migration->up();
        foreach ($fields as $field) {
            $this->assertNull($row->fresh()->getAttribute($field));
        }
    }

    #[Test]
    public function cash_override_snapshots_the_priced_unit_and_effective_decimal(): void
    {
        $answer = $this->submit(['staff_unit_price_minor' => 1000])->assertOk()
            ->assertJsonPath('data.amount_due_minor', 2000)->assertJsonPath('data.total_minor', 2000)
            ->assertJsonPath('data.price_breakdown.list_unit_minor', 1500)
            ->assertJsonPath('data.price_breakdown.staff_unit_minor', 1000);
        $row = FormResponse::sole();
        $this->assertSame('20.00', $row->amount_due);
        $this->assertSame(1000, $row->unit_price_minor);
        $this->assertSame(1500, $row->list_unit_price_minor);
        $this->assertSame(1000, $row->staff_unit_price_minor);
        $this->assertSame('cash', $row->staff_payment_method);
        $this->assertSame('Staff member', $row->staff_holder_name);
        $this->assertSame('cash', $row->paid_via);
        $this->assertSame('paid', $row->payment_status);
        $this->assertSame(1, $this->code->fresh()->use_count);
        $this->assertStringNotContainsString('Staff member', $answer->getContent());
        $this->assertSame([], self::$pages);
    }

    #[Test]
    public function plain_staff_cash_uses_the_locked_price_for_the_decimal_with_the_override_switch_off(): void
    {
        $this->payment(['staffPriceOverride' => false]);
        $settings = $this->form->settings;
        $settings['fee']['amount'] = 17.35;

        // Save a price change at the transaction boundary, before the locked re-read.
        $changed = false;
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $events = clone $dispatcher;
        $connection->setEventDispatcher($events);
        $events->listen(TransactionBeginning::class, function () use ($settings, &$changed): void {
            if ($changed) {
                return;
            }

            $changed = true;
            Form::whereKey($this->form->id)->update(['settings' => json_encode($settings)]);
        });

        try {
            $answer = $this->submit(formEncoded: true)->assertOk()
                ->assertJsonPath('data.amount_due_minor', 3470)
                ->assertJsonPath('data.total_minor', 3470);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        $this->assertTrue($changed, 'The price changed after the initial form read.');
        $row = FormResponse::sole();
        $this->assertSame('cash', $row->paid_via);
        $this->assertSame('paid', $row->payment_status);
        $this->assertSame(3470, $row->amount_due_minor);
        $this->assertSame(3470, $row->total_minor);
        $this->assertSame(1735, $row->unit_price_minor);
        $this->assertNull($row->staff_payment_method);
        $this->assertNull($row->list_unit_price_minor);
        $this->assertSame(1, $this->code->fresh()->use_count);
        $this->assertSame('34.70', $row->amount_due);
        $this->assertEquals(34.70, $answer->json('data.amount_due'));
    }

    #[Test]
    public function the_switch_off_refuses_an_override_but_preserves_an_old_client(): void
    {
        $this->payment(['staffPriceOverride' => false]);
        $this->submit(['staff_unit_price_minor' => 1000])->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
        $this->submit()->assertOk()->assertJsonPath('data.total_minor', 3000)->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonMissingPath('data.price_breakdown');
        $this->assertNull(FormResponse::sole()->list_unit_price_minor);
    }

    #[Test]
    public function an_explicit_complimentary_cash_entry_is_paid_and_owes_no_cash(): void
    {
        $this->submit(['staff_unit_price_minor' => 0, 'staff_pay_with' => 'cash'])->assertOk()
            ->assertJsonPath('data.total_minor', 0)->assertJsonPath('data.payment_status', 'paid');
        $totals = FormCashTotals::for($this->form->responses(), collect([$this->code->fresh()]));
        $this->assertSame(0, $totals['totals']['cash_minor']);
        $this->assertSame(1, $totals['totals']['submissions']);
        $this->assertSame('0.00', FormResponse::sole()->amount_due);
    }

    #[Test]
    public function zero_card_is_refused_even_when_a_fee_would_make_the_total_positive(): void
    {
        $this->payment(['requireFeeCoverage' => true]);
        $this->submit(['staff_unit_price_minor' => 0, 'staff_pay_with' => 'card'])->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
        $this->assertSame([], self::$pages);
    }

    #[Test]
    public function invalid_minor_units_and_prices_above_list_never_write_a_row(): void
    {
        foreach ([-1, 1501, 1.5, true, '1.0', '1e3', '999999999999999999999999', [], ''] as $value) {
            // Blank means no override, so it must remain a list-price submission.
            if ($value === '') {
                continue;
            }
            $this->submit(['staff_unit_price_minor' => $value])->assertStatus(422);
        }
        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, $this->code->fresh()->use_count);
    }

    #[Test]
    public function staff_controls_without_a_credential_are_refused_not_applied(): void
    {
        $this->submit(['staff_code' => null, 'staff_unit_price_minor' => 1000])->assertStatus(422);
        $this->submit(['staff_code' => null, 'staff_pay_with' => 'cash'])->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function staff_route_conflicts_and_unoffered_cards_are_refused(): void
    {
        $this->submit(['staff_pay_with' => 'card', 'pay_with' => 'office'])->assertStatus(422);
        $this->submit(['staff_pay_with' => 'cash', 'pay_with' => 'card'])->assertStatus(422);
        $this->submit(['staff_pay_with' => 'invalid'])->assertStatus(422);
        $this->payment(['online' => false]);
        $this->submit(['staff_pay_with' => 'card'])->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function staff_card_uses_hosted_checkout_and_waits_for_the_webhook(): void
    {
        $this->submit(['staff_unit_price_minor' => 1000, 'staff_pay_with' => 'card', 'cover_fees' => true])->assertOk()
            ->assertJsonPath('data.payment_method', 'online')->assertJsonPath('data.payment_status', 'unpaid');
        $row = FormResponse::sole();
        $this->assertSame($this->code->id, $row->staff_code_id);
        $this->assertSame('card', $row->staff_payment_method);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->paid_via);
        $this->assertSame(1, $this->code->fresh()->use_count);
        $page = array_values(self::$pages)[0];
        $this->assertSame('acct_test_staff', $page['account']);
        $this->assertSame(1000, $page['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(2, $page['params']['line_items'][0]['quantity']);
        $this->assertGreaterThan(0, $row->fee_covered_minor);
        $this->assertSame(2000 + $row->fee_covered_minor, $row->total_minor);
        Mail::assertNothingOutgoing();
        $this->assertSame(0, FormCashTotals::for($this->form->responses(), collect([$this->code]))['totals']['cash_minor']);
        $this->pay($row);
        $this->pay($row->fresh());
        $this->assertSame('paid', $row->fresh()->payment_status);
        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Mail::assertQueued(FormResponseSubmitted::class, 1);
    }

    #[Test]
    public function staff_card_below_the_card_minimum_is_refused_before_writing(): void
    {
        $this->submit(['staff_unit_price_minor' => 1, 'staff_pay_with' => 'card'])->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_staff_retry_uses_the_original_snapshot_after_price_and_switch_changes(): void
    {
        $key = (string) Str::uuid();
        $extra = ['staff_unit_price_minor' => 1000, 'staff_pay_with' => 'card', 'client_submission_key' => $key];
        $first = $this->submit($extra)->assertOk();
        $settings = $this->form->settings;
        $settings['fee']['amount'] = 5;
        $settings['payment']['staffPriceOverride'] = false;
        $this->form->update(['settings' => $settings]);
        $again = $this->submit($extra)->assertOk();
        $this->assertSame($first->json('data'), $again->json('data'));
        $this->submit(array_replace($extra, ['staff_unit_price_minor' => 500]))->assertStatus(409);
        $this->submit(array_replace($extra, ['staff_pay_with' => 'cash']))->assertStatus(409);
        $this->assertSame(1, FormResponse::count());
        $this->assertCount(1, self::$pages);
        $this->assertSame(1, $this->code->fresh()->use_count);
    }

    #[Test]
    public function a_form_encoded_minor_unit_is_canonical_for_replays(): void
    {
        $key = (string) Str::uuid();
        $this->submit(['staff_unit_price_minor' => '01000', 'client_submission_key' => $key], formEncoded: true)->assertOk()
            ->assertJsonPath('data.total_minor', 2000);
        $this->submit(['staff_unit_price_minor' => 1000, 'client_submission_key' => $key])->assertOk();
        $this->assertSame(1, FormResponse::count());
    }

    #[Test]
    public function a_family_tier_override_changes_the_registration_not_each_person(): void
    {
        $settings = $this->form->settings;
        unset($settings['fee']['amount']);
        $settings['fee']['countTiers'] = [['min' => 1, 'amount' => 30, 'label' => 'Family']];
        $this->form->update(['settings' => $settings]);
        $this->submit(['staff_unit_price_minor' => 2000])->assertOk()->assertJsonPath('data.total_minor', 2000);
        $row = FormResponse::sole();
        $this->assertSame(1, $row->price_quantity);
        $this->assertSame(3000, $row->list_unit_price_minor);
        $this->assertSame('Family', $row->price_label);
    }

    #[Test]
    public function customer_mail_and_public_status_show_price_context_without_the_holder(): void
    {
        $this->submit(['staff_unit_price_minor' => 1000])->assertOk();
        $row = FormResponse::sole();
        $this->getJson('/api/v1/form-responses/'.$row->uuid, ['masjid-id' => (string) $this->form->masjid_id])
            ->assertOk()->assertJsonPath('data.price_breakdown.list_unit_minor', 1500)
            ->assertJsonPath('data.total_minor', 2000);
        Mail::assertQueued(FormSubmissionReceipt::class, function ($mail) {
            $this->assertSame('$10.00 × 2', $mail->breakdownLine);
            $html = $mail->render();
            $this->assertStringContainsString('$20.00', $html);
            $this->assertStringContainsString('$30.00', $html);
            $this->assertStringNotContainsString('Staff member', $html);

            return true;
        });
        Mail::assertQueued(FormResponseSubmitted::class, fn ($mail) => str_contains($mail->render(), 'Staff member'));
        $this->assertStringContainsString('$20.00', FormNotifier::paymentLine($row));
    }

    #[Test]
    public function an_admin_taking_cash_for_staff_card_owes_it_instead_of_the_code_holder(): void
    {
        $this->submit(['staff_unit_price_minor' => 1000, 'staff_pay_with' => 'card'])->assertOk();
        $row = FormResponse::sole();
        $admin = User::factory()->create(['phone' => fake()->phoneNumber(), 'type' => 'SuperAdmin', 'name' => 'Test administrator']);
        $this->assertTrue($row->settleCashBy($admin));
        $totals = FormCashTotals::for($this->form->responses(), collect([$this->code]));
        $this->assertSame(0, collect($totals['holders'])->firstWhere('kind', 'code')['cash_minor']);
        $this->assertSame(2000, collect($totals['holders'])->firstWhere('kind', 'admin')['cash_minor']);
        $this->pay($row->fresh());
        $this->assertSame('cash', $row->fresh()->payment_method);
    }

    #[Test]
    public function the_admin_payload_keeps_the_original_setter_after_a_code_is_renamed(): void
    {
        $this->submit(['staff_unit_price_minor' => 1000])->assertOk();
        $this->code->update(['holder_name' => 'Changed holder']);
        Sanctum::actingAs(User::factory()->create(['phone' => fake()->phoneNumber(), 'type' => 'SuperAdmin']));
        $this->getJson('/api/admin/masjids/'.$this->form->masjid_id.'/forms/'.$this->form->id.'/responses')
            ->assertOk()->assertJsonPath('data.data.0.staff_code.holder_name', 'Staff member')
            ->assertJsonPath('data.data.0.staff_payment_method', 'cash');
    }

    #[Test]
    public function csv_and_roster_exports_append_staff_price_audit_columns(): void
    {
        $this->submit(['staff_unit_price_minor' => 1000])->assertOk();
        Sanctum::actingAs(User::factory()->create(['phone' => fake()->phoneNumber(), 'type' => 'SuperAdmin']));
        $base = '/api/admin/masjids/'.$this->form->masjid_id.'/forms/'.$this->form->id.'/responses';
        foreach (['/export', '/roster/export'] as $suffix) {
            $csv = $this->get($base.$suffix)->assertOk()->streamedContent();
            $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
            $this->assertSame(['List unit price', 'Staff unit price', 'Staff payment choice', 'Price set by'], array_slice($lines[0], -4));
            $this->assertSame(['15.00', '10.00', 'cash', 'Staff member'], array_slice($lines[1], -4));
        }
    }

    #[Test]
    public function a_form_cannot_enable_price_overrides_without_staff_codes(): void
    {
        $settings = $this->form->settings;
        $settings['payment']['staffCodes'] = false;
        $problems = StoreFormRequest::crossCheck($this->form->schema, $settings);
        $this->assertArrayHasKey('settings.payment.staffPriceOverride', $problems);
    }

    #[Test]
    public function insights_keep_their_workflow_semantics_at_the_effective_price(): void
    {
        $this->submit(['staff_unit_price_minor' => 1000])->assertOk();
        $insights = FormInsights::for($this->form)->build($this->form->responses()->get());
        $this->assertEquals(20, $insights['totals']['amount_due_total']);
        $this->assertEquals(20, $insights['totals']['amount_due_outstanding']);
    }

    #[Test]
    public function an_orphaned_staff_audit_is_not_proof_that_no_payment_was_recorded(): void
    {
        $this->submit(['staff_pay_with' => 'card', 'staff_unit_price_minor' => 1000])->assertOk()->assertJsonPath('data.payment_method', 'online');
        $row = FormResponse::sole();
        $row->forceFill(['staff_code_id' => null])->save();
        $this->assertFalse($row->neverRecordedAPayment());
    }

    #[Test]
    public function an_expired_staff_page_reopens_from_its_original_unit_snapshot(): void
    {
        $this->submit(['staff_pay_with' => 'card', 'staff_unit_price_minor' => 1000])->assertOk()->assertJsonPath('data.payment_method', 'online');
        $row = FormResponse::sole();
        self::$pages[$row->stripe_checkout_session_id]['status'] = 'expired';
        $settings = $this->form->settings;
        $settings['fee']['amount'] = 5;
        $this->form->update(['settings' => $settings]);
        $this->postJson('/api/v1/form-responses/'.$row->uuid.'/checkout', ['return_path' => '/registration'],
            ['masjid-id' => (string) $this->form->masjid_id, 'Origin' => config('app.url')])->assertOk();
        $pages = array_values(self::$pages);
        $this->assertCount(2, $pages);
        $this->assertNotSame($pages[0]['key'], $pages[1]['key']);
        $this->assertSame(1000, $pages[1]['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(2, $pages[1]['params']['line_items'][0]['quantity']);
    }

    #[Test]
    public function signed_webhook_event_orders_settle_staff_card_once_and_never_flip_cash(): void
    {
        config(['services.stripe.connect_webhook_secret' => 'whsec_test_staff']);
        foreach (['card', 'cash'] as $method) {
            foreach ([['checkout.session.completed', 'payment_intent.succeeded'], ['payment_intent.succeeded', 'checkout.session.completed']] as $order) {
                $this->submit(['staff_pay_with' => $method, 'staff_unit_price_minor' => 1000])->assertOk()
                    ->assertJsonPath('data.payment_method', $method === 'card' ? 'online' : 'cash');
                $row = FormResponse::latest('id')->first();
                $intent = 'pi_test_staff_'.$row->id;
                foreach ($order as $type) {
                    $metadata = ['form_response_uuid' => $row->uuid, 'masjid_id' => (string) $row->masjid_id, 'form_id' => (string) $row->form_id];
                    $object = $type === 'checkout.session.completed'
                        ? ['id' => $row->stripe_checkout_session_id ?? 'cs_test_stray', 'object' => 'checkout.session', 'mode' => 'payment',
                            'payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => $intent, 'client_reference_id' => $row->uuid,
                            'currency' => 'usd', 'amount_total' => $row->total_minor, 'metadata' => $metadata]
                        : ['id' => $intent, 'object' => 'payment_intent', 'status' => 'succeeded', 'currency' => 'usd',
                            'amount' => $row->total_minor, 'amount_received' => $row->total_minor, 'metadata' => $metadata];
                    $event = ['id' => 'evt_'.Str::random(24), 'object' => 'event', 'type' => $type,
                        'account' => 'acct_test_staff', 'data' => ['object' => $object]];
                    $payload = json_encode($event);
                    $timestamp = time();
                    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_staff');
                    foreach (range(1, 2) as $delivery) {
                        $this->call('POST', '/api/stripe/webhook', [], [], [], [
                            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json',
                        ], $payload)->assertOk();
                    }
                }
                $this->assertSame('paid', $row->fresh()->payment_status);
                $this->assertSame($method === 'card' ? 'online' : 'cash', $row->fresh()->payment_method);
            }
        }
        Mail::assertQueued(FormSubmissionReceipt::class, 4);
        Mail::assertQueued(FormResponseSubmitted::class, 4);
        $this->assertSame(4, $this->code->fresh()->use_count);
    }

    #[Test]
    public function a_staff_token_can_charge_list_price_with_the_override_switch_off(): void
    {
        $this->payment(['staffPriceOverride' => false, 'requireFeeCoverage' => true]);
        $token = $this->postJson('/api/v1/forms/'.$this->form->id.'/staff-session', [
            'staff_code' => 'K7QM-2XWD', 'device_id' => 'staff-device',
        ], ['masjid-id' => (string) $this->form->masjid_id])->assertOk()->json('data.staff_token');
        $this->submit(['staff_code' => null, 'staff_token' => $token, 'staff_pay_with' => 'card', 'cover_fees' => false])
            ->assertOk()->assertJsonPath('data.payment_method', 'online')->assertJsonPath('data.amount_due_minor', 3000);
        $row = FormResponse::sole();
        $this->assertGreaterThan(0, $row->fee_covered_minor);
        $this->assertNull($row->staff_unit_price_minor);
        $this->assertSame(1500, $row->list_unit_price_minor);
        $this->assertSame($this->code->id, $row->staff_code_id);
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function invalid_credentials_keep_the_uniform_refusal_before_staff_field_validation(): void
    {
        $this->submit(['staff_code' => 'ZZZZ-ZZZZ', 'staff_pay_with' => [], 'staff_unit_price_minor' => -1])
            ->assertStatus(422)->assertExactJson(['status' => 'failed', 'data' => ['staff_code' => [FormStaffCodes::REFUSED]]]);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_staff_card_paid_after_code_revocation_remains_a_card_payment(): void
    {
        $this->submit(['staff_pay_with' => 'card', 'staff_unit_price_minor' => 1000])->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid');
        $row = FormResponse::sole();
        $this->code->revoke();
        $this->pay($row);
        $this->assertSame('paid', $row->fresh()->payment_status);
        $this->assertSame('online', $row->fresh()->payment_method);
        $this->assertSame(0, FormCashTotals::for($this->form->responses(), collect([$this->code]))['totals']['cash_minor']);
    }

    #[Test]
    public function linked_staff_card_replays_and_webhooks_use_the_original_account_and_amount(): void
    {
        $org = Masjid::find($this->form->masjid_id);
        $parent = $org->replicate();
        $parent->name = 'Test parent organisation';
        $parent->email = fake()->unique()->safeEmail();
        $parent->phone = fake()->unique()->phoneNumber();
        $parent->forceFill(['stripe_account_id' => 'acct_test_parent', 'stripe_charges_enabled' => true])->save();
        $org->forceFill(['stripe_account_id' => null, 'stripe_charges_enabled' => false,
            'parent_id' => $parent->id, 'forms_card_via_masjid_id' => $parent->id])->save();
        $key = (string) Str::uuid();
        $extra = ['staff_pay_with' => 'card', 'staff_unit_price_minor' => 1000, 'client_submission_key' => $key];
        $first = $this->submit($extra)->assertOk()->assertJsonPath('data.payment_method', 'online');
        $row = FormResponse::sole();
        $this->assertSame('acct_test_parent', $row->charge_account_id);
        $this->assertNotNull($row->charge_ref);
        $this->assertStringNotContainsString('acct_test_parent', $first->getContent());
        $this->assertStringNotContainsString($row->charge_ref, $first->getContent());
        $parent->forceFill(['stripe_charges_enabled' => false])->save();
        $again = $this->submit($extra)->assertOk();
        $this->assertSame($first->json('data'), $again->json('data'));
        $pi = ['id' => 'pi_test_linked_staff', 'metadata' => ['form_charge_ref' => $row->charge_ref],
            'amount_received' => 2000, 'currency' => 'usd'];
        $service = app(FormResponsePaymentService::class);
        $service->handlePaymentIntentSucceeded(array_replace($pi, ['amount_received' => 1999]), 'acct_test_parent');
        $this->assertSame('unpaid', $row->fresh()->payment_status);
        $service->handlePaymentIntentSucceeded($pi, 'acct_test_staff');
        $this->assertSame('unpaid', $row->fresh()->payment_status);
        $service->handlePaymentIntentSucceeded($pi, 'acct_test_parent');
        $this->assertSame('paid', $row->fresh()->payment_status);
        $service->handleChargeFlag(['id' => 'ch_test_staff', 'payment_intent' => $pi['id'],
            'amount_refunded' => 1000, 'currency' => 'usd', 'metadata' => $pi['metadata']], 'acct_test_parent', FormResponse::CHARGE_FLAG_REFUNDED);
        $this->assertSame(1000, $row->fresh()->charge_refunded_minor);
        $this->assertSame(2000, $row->fresh()->total_minor);
        $this->assertSame('paid', $row->fresh()->payment_status);
    }

    private function payment(array $changes): void
    {
        $settings = $this->form->settings;
        $settings['payment'] = array_replace($settings['payment'], $changes);
        $this->form->update(['settings' => $settings]);
    }

    private function submit(array $extra = [], bool $formEncoded = false)
    {
        $body = array_replace([
            'data' => ['name' => 'Test registrant', 'email' => $this->email, 'attendees' => [['name' => 'Guest 1'], ['name' => 'Guest 2']]],
            'staff_code' => 'K7QM-2XWD', 'device_id' => 'staff-device',
            'client_submission_key' => (string) Str::uuid(), 'return_path' => '/registration',
        ], $extra);
        $headers = ['masjid-id' => (string) $this->form->masjid_id, 'Origin' => config('app.url'), 'Accept' => 'application/json'];
        $path = '/api/v1/forms/'.$this->form->id.'/responses';

        return $formEncoded ? $this->post($path, $body, $headers) : $this->postJson($path, $body, $headers);
    }

    private function pay(FormResponse $row): void
    {
        app(FormResponsePaymentService::class)->handlePaymentIntentSucceeded([
            'id' => 'pi_test_staff', 'metadata' => ['form_response_uuid' => $row->uuid],
            'amount_received' => $row->total_minor, 'currency' => 'usd',
        ], 'acct_test_staff');
    }
}
