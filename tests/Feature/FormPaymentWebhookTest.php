<?php

namespace Tests\Feature;

use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\StripeWebhookEvent;
use App\Services\Stripe\FormResponsePaymentService;
use App\Services\Stripe\MealOrderPaymentService;
use App\Services\Stripe\RegistrationPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The signed webhook is the only thing that marks a card registration paid, and the
 * moment its receipt and coordinator email go (DECISIONS.md 2026-09-11). Every event
 * here is signed the way Stripe signs it and posted through the real route, so the
 * dispatch arm, the event dedup and FormResponsePaymentService are all in play.
 *
 * What is pinned:
 *
 *  - a paid session settles the row once, with exactly one receipt ("Paid $X by card",
 *    carrying the group link) and one coordinator email, and the other success event,
 *    in either order, changes nothing;
 *  - an unpaid completion (a delayed method, which Stripe still marks `complete`)
 *    records the session id and nothing else, and the payment intent settles it when
 *    the money lands;
 *  - tenancy is the connected account's: none, an unknown one and another
 *    organisation's all record nothing, and each says so at warning;
 *  - a card payment never flips a cash or staff-recorded row, and a second payment on
 *    a paid one is logged, never recorded over the first;
 *  - money landing on a cancelled registration is recorded and logged; an offboarded
 *    organisation's is recorded and emails nobody; an expired page changes nothing;
 *  - a form event never books a donation or a registration payment.
 */
class FormPaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_form_platform';

    private const CONNECT_SECRET = 'whsec_form_connect';

    private const ACCOUNT = 'acct_test_mec_festival';

    private const OTHER_ACCOUNT = 'acct_test_other_org';

    private const INVITE = 'FestivalGroup2026Xyz';

    private const PAYER = 'amal@example.com';

    private const OFFICE = 'festival-office@mec.test';

    private Masjid $masjid;

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
            'services.stripe.webhook_secret' => self::SECRET,
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
        ]);

        Mail::fake();
        Log::spy();

        $this->masjid = $this->makeMasjid(['stripe_account_id' => self::ACCOUNT, 'stripe_charges_enabled' => true]);
        $this->form = $this->makeForm($this->masjid);
    }

    // ------------------------------------------------------------ settling once

    #[Test]
    public function a_paid_session_settles_the_registration_with_one_receipt_and_one_coordinator_email(): void
    {
        $row = $this->cardRow(['fee_covered_minor' => 117, 'total_minor' => 3117]);
        $event = $this->completed($row);

        $this->postWebhook($event)->assertOk();

        $row->refresh();
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
        $this->assertSame(FormResponse::METHOD_ONLINE, $row->payment_method);
        $this->assertNotNull($row->paid_at);
        $this->assertSame('pi_form_1', $row->stripe_payment_intent_id);
        $this->assertSame('cs_form_1', $row->stripe_checkout_session_id);
        $this->assertSame(3117, $row->total_minor, 'the snapshot is what was charged; nothing is repriced');
        $this->assertNotNull(StripeWebhookEvent::where('stripe_event_id', $event['id'])->value('processed_at'));

        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Mail::assertQueued(FormResponseSubmitted::class, 1);

        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->hasTo(self::PAYER)
            && $mail->responseId === $row->id
            && $mail->paymentLine === 'Paid $31.17 by card'
            && $mail->whatsappUrl === 'https://chat.whatsapp.com/' . self::INVITE
            && $mail->whatsappLabel === 'Join the festival group'
            && $mail->paymentNote === null);

        Mail::assertQueued(FormResponseSubmitted::class, fn (FormResponseSubmitted $mail) => $mail->hasTo(self::OFFICE)
            && $mail->responseId === $row->id
            && $mail->paymentLine === 'Paid $31.17 by card');

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_payment_intent_after_the_session_changes_nothing(): void
    {
        $row = $this->cardRow();

        $this->postWebhook($this->completed($row))->assertOk();
        $paidAt = $row->fresh()->paid_at;

        // Later enough that a second stamp would show.
        $this->travel(5)->minutes();
        $this->postWebhook($this->succeeded($row))->assertOk();

        $fresh = $row->fresh();
        $this->assertTrue($paidAt->equalTo($fresh->paid_at), 'paid_at is stamped once');
        $this->assertSame('pi_form_1', $fresh->stripe_payment_intent_id);

        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Mail::assertQueued(FormResponseSubmitted::class, 1);
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_session_after_the_payment_intent_changes_nothing(): void
    {
        $row = $this->cardRow();

        $this->postWebhook($this->succeeded($row))->assertOk();
        $paidAt = $row->fresh()->paid_at;
        $this->assertNotNull($paidAt, 'the payment intent settles on its own');

        $this->travel(5)->minutes();
        $this->postWebhook($this->completed($row))->assertOk();

        $fresh = $row->fresh();
        $this->assertTrue($paidAt->equalTo($fresh->paid_at));
        $this->assertSame('pi_form_1', $fresh->stripe_payment_intent_id);
        $this->assertSame('cs_form_1', $fresh->stripe_checkout_session_id);

        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Mail::assertQueued(FormResponseSubmitted::class, 1);
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function an_unpaid_completion_records_the_session_and_the_money_settles_it_when_it_lands(): void
    {
        // The row never heard back from Stripe about its page.
        $row = $this->cardRow(['stripe_checkout_session_id' => null]);

        // A bank debit: Stripe completes the session while the money is still moving.
        $this->postWebhook($this->completed($row, ['payment_status' => 'unpaid']))->assertOk();

        $row->refresh();
        $this->assertSame('cs_form_1', $row->stripe_checkout_session_id);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->payment_status);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->stripe_payment_intent_id, 'only the session id is recorded');
        Mail::assertNothingOutgoing();

        // The debit clears.
        $this->postWebhook($this->succeeded($row))->assertOk();

        $row->refresh();
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
        $this->assertSame('pi_form_1', $row->stripe_payment_intent_id);
        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Mail::assertQueued(FormResponseSubmitted::class, 1);
    }

    // ------------------------------------------------------------------ tenancy

    #[Test]
    public function no_account_an_unknown_one_or_another_organisations_records_nothing_and_says_so(): void
    {
        $other = $this->makeMasjid(['stripe_account_id' => self::OTHER_ACCOUNT, 'stripe_charges_enabled' => true]);
        $row = $this->cardRow();

        $this->postWebhook($this->completed($row, ['account' => null]))->assertOk();
        $this->postWebhook($this->completed($row, ['account' => 'acct_nobody']))->assertOk();
        // The metadata still names the right masjid. The account decides, and it is someone else's.
        $this->postWebhook($this->succeeded($row, ['account' => self::OTHER_ACCOUNT]))->assertOk();

        $row->refresh();
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->payment_status);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->stripe_payment_intent_id);
        $this->assertSame('cs_form_1', $row->stripe_checkout_session_id);
        Mail::assertNothingOutgoing();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'without a connected account')
                && ($context['form_response_uuid'] ?? null) === $row->uuid)
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'unknown connected account')
                && ($context['account'] ?? null) === 'acct_nobody')
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'does not belong to the organisation')
                && ($context['form_response_uuid'] ?? null) === $row->uuid
                && (int) ($context['masjid_id'] ?? 0) === (int) $other->id
                && ($context['account'] ?? null) === self::OTHER_ACCOUNT)
            ->once();
    }

    // ------------------------------------------------------ rows not waiting for a card

    #[Test]
    public function a_card_payment_never_flips_cash_taken_at_the_gate_or_a_payment_staff_recorded(): void
    {
        foreach ([FormResponse::METHOD_CASH, FormResponse::METHOD_EXTERNAL] as $method) {
            $row = $this->settledRow($method);
            $paidAt = $row->paid_at;

            $this->postWebhook($this->completed($row, [
                'id' => "cs_stray_{$method}",
                'payment_intent' => "pi_stray_{$method}",
            ]))->assertOk();

            $fresh = $row->fresh();
            $this->assertSame($method, $fresh->payment_method, "a {$method} row stays {$method}");
            $this->assertSame(FormResponse::PAYMENT_PAID, $fresh->payment_status);
            $this->assertTrue($paidAt->equalTo($fresh->paid_at));
            $this->assertSame(3000, $fresh->total_minor);
            $this->assertSame(0, $fresh->fee_covered_minor);
            $this->assertSame("pi_stray_{$method}", $fresh->stripe_payment_intent_id, 'recorded, so the organisation can find the charge and refund it');

            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'not paid by card')
                    && ($context['form_response_uuid'] ?? null) === $row->uuid
                    && ($context['payment_method'] ?? null) === $method
                    && ($context['payment_intent'] ?? null) === "pi_stray_{$method}")
                ->once();
        }

        // Nobody is told they paid by card: they did not, as far as this row is concerned.
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function a_second_payment_on_a_paid_registration_is_logged_and_never_recorded_over_the_first(): void
    {
        $row = $this->cardRow();

        $this->postWebhook($this->completed($row))->assertOk();
        $this->postWebhook($this->succeeded($row, ['id' => 'pi_form_2']))->assertOk();

        $this->assertSame('pi_form_1', $row->fresh()->stripe_payment_intent_id);
        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Mail::assertQueued(FormResponseSubmitted::class, 1);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'second card payment')
                && ($context['form_response_uuid'] ?? null) === $row->uuid
                && ($context['payment_intent'] ?? null) === 'pi_form_2'
                && ($context['recorded_payment_intent'] ?? null) === 'pi_form_1')
            ->once();
    }

    #[Test]
    public function the_emails_name_the_price_the_registration_was_made_at_when_its_payment_lands_after_a_cut_off(): void
    {
        // Early bird until 10 October, Standard until the 16th, then the day-of price, on
        // MEC's clock.
        $this->masjid->forceFill(['timezone' => 'America/New_York'])->save();
        $settings = $this->form->settings;
        $settings['fee'] = ['currency' => 'USD', 'perEntryOfSection' => 'attendees', 'tiers' => [
            ['label' => 'Early bird', 'amount' => 20, 'until' => '2026-10-10'],
            ['label' => 'Standard', 'amount' => 25, 'until' => '2026-10-16'],
            ['label' => 'Day of', 'amount' => 30],
        ]];
        $this->form->forceFill(['settings' => $settings])->save();

        // Two attendees at 23:50 on the last early-bird evening: $40, which is what the
        // hosted page charges (FormResponseCheckoutService prices it at submitted_at).
        $this->travelTo(Carbon::parse('2026-10-10 23:50', 'America/New_York'));
        $row = $this->cardRow(['amount_due_minor' => 4000, 'total_minor' => 4000], ['amount_due' => 40]);

        // The card goes through at 00:05, with Standard in force.
        $this->travelTo(Carbon::parse('2026-10-11 00:05', 'America/New_York'));
        $this->postWebhook($this->completed($row))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);
        Mail::assertQueued(FormSubmissionReceipt::class, fn ($mail) => $mail->responseId === $row->id
            && $mail->amountLine === '$40.00'
            && $mail->tierLabel === 'Early bird');
        Mail::assertQueued(FormResponseSubmitted::class, fn ($mail) => $mail->responseId === $row->id
            && $mail->amountLine === '$40.00'
            && $mail->tierLabel === 'Early bird');
    }

    #[Test]
    public function money_landing_on_a_cancelled_registration_is_recorded_and_said_out_loud(): void
    {
        $row = $this->cardRow([], ['status' => FormResponse::STATUS_CANCELLED]);

        $this->postWebhook($this->completed($row))->assertOk();

        $fresh = $row->fresh();
        $this->assertSame(FormResponse::PAYMENT_PAID, $fresh->payment_status, 'the money moved, so it is recorded');
        $this->assertSame(FormResponse::STATUS_CANCELLED, $fresh->status, 'the triage is the admin\'s, never the webhook\'s');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'cancelled form registration was paid')
                && ($context['form_response_uuid'] ?? null) === $row->uuid)
            ->once();

        // The payer is still told their money arrived.
        Mail::assertQueued(FormSubmissionReceipt::class, 1);
    }

    #[Test]
    public function an_offboarded_organisations_payment_is_recorded_and_emails_nobody(): void
    {
        $row = $this->cardRow();
        $this->masjid->delete();

        $this->postWebhook($this->completed($row))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);
        Mail::assertNothingOutgoing();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'offboarded')
                && ($context['form_response_uuid'] ?? null) === $row->uuid)
            ->once();
    }

    #[Test]
    public function an_expired_page_is_acknowledged_and_changes_nothing(): void
    {
        // Not routed in v1: a form holds no place for an abandoned page to give back.
        $row = $this->cardRow();

        $this->postWebhook($this->expired($row))->assertOk();

        $fresh = $row->fresh();
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $fresh->payment_status);
        $this->assertSame('cs_form_1', $fresh->stripe_checkout_session_id);
        $this->assertSame('new', $fresh->status);
        Mail::assertNothingOutgoing();
    }

    // ------------------------------------------------------------------ dispatch

    #[Test]
    public function a_form_event_routes_only_to_forms_and_never_books_a_donation_or_a_registration_payment(): void
    {
        $object = ['metadata' => ['form_response_uuid' => 'w']];

        $this->assertTrue(FormResponsePaymentService::isFormResponseEvent($object));
        $this->assertFalse(MealOrderPaymentService::isOrderEvent($object));
        $this->assertFalse(RegistrationPaymentService::isRegistrationEvent($object));

        foreach (['order_uuid', 'registration_uuid', 'donation_uuid'] as $key) {
            $this->assertFalse(FormResponsePaymentService::isFormResponseEvent(['metadata' => [$key => 'x']]), $key);
        }

        $this->assertFalse(FormResponsePaymentService::isFormResponseEvent([]));
        $this->assertFalse(FormResponsePaymentService::isFormResponseEvent(['metadata' => ['form_response_uuid' => '']]));

        $row = $this->cardRow();

        $this->postWebhook($this->completed($row))->assertOk();
        $this->postWebhook($this->succeeded($row))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status, 'the form arm handled it');
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('donation_receipts', 0);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    // ------------------------------------------------------------------ helpers

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

    /** The festival: $15 per attendee by card, with a group link and a note on how to pay. */
    private function makeForm(Masjid $masjid): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => ['sections' => [
                ['id' => 'contact', 'title' => 'You', 'fields' => [
                    ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ]],
                ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'fields' => [
                    ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'notifyEmails' => [self::OFFICE],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true, 'allowFeeCoverage' => true],
                'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE,
                'whatsappLabel' => 'Join the festival group',
                'paymentNote' => 'Pay by card online, or in cash at the gate.',
            ],
            'is_active' => true,
        ]);
    }

    /** A card registration as the submit leaves it: two attendees at $15, unpaid, its page open. */
    private function cardRow(array $money = [], array $attributes = []): FormResponse
    {
        $row = new FormResponse(array_merge([
            'form_id' => $this->form->id,
            'masjid_id' => $this->masjid->id,
            'data' => [
                'fullName' => 'Amal Yusuf',
                'email' => self::PAYER,
                'attendees' => [['attendeeName' => 'Amal'], ['attendeeName' => 'Zaid']],
            ],
            'respondent_name' => 'Amal Yusuf',
            'respondent_email' => self::PAYER,
            'entry_count' => 2,
            'amount_due' => 30,
            'status' => 'new',
            'submitted_at' => now(),
        ], $attributes));

        $row->forceFill(array_merge([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'currency' => 'usd',
            'amount_due_minor' => 3000,
            'fee_covered_minor' => 0,
            'total_minor' => 3000,
            'idempotency_key' => 'form_response_' . Str::uuid(),
            'stripe_checkout_session_id' => 'cs_form_1',
        ], $money))->save();

        return $row->fresh();
    }

    /** A registration already paid another way: cash at the gate, or recorded by staff. */
    private function settledRow(string $method): FormResponse
    {
        return $this->cardRow([
            'payment_method' => $method,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now()->subHour(),
            'idempotency_key' => null,
            'stripe_checkout_session_id' => null,
        ]);
    }

    private function completed(FormResponse $row, array $o = []): array
    {
        return $this->event('checkout.session.completed', $o, [
            'id' => $o['id'] ?? 'cs_form_1',
            'object' => 'checkout.session',
            'mode' => 'payment',
            // Every completed session is `complete`, paid or not; payment_status is what says.
            'status' => 'complete',
            'payment_status' => $o['payment_status'] ?? 'paid',
            'amount_total' => (int) $row->total_minor,
            'payment_intent' => $o['payment_intent'] ?? 'pi_form_1',
            'client_reference_id' => $row->uuid,
            'metadata' => $this->routing($row),
        ]);
    }

    private function succeeded(FormResponse $row, array $o = []): array
    {
        return $this->event('payment_intent.succeeded', $o, [
            'id' => $o['id'] ?? 'pi_form_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => (int) $row->total_minor,
            'amount_received' => (int) $row->total_minor,
            'metadata' => $this->routing($row),
        ]);
    }

    private function expired(FormResponse $row): array
    {
        return $this->event('checkout.session.expired', [], [
            'id' => 'cs_form_1',
            'object' => 'checkout.session',
            'status' => 'expired',
            'payment_status' => 'unpaid',
            'client_reference_id' => $row->uuid,
            'metadata' => $this->routing($row),
        ]);
    }

    /** What FormResponseCheckoutService puts on the session and on the payment intent. */
    private function routing(FormResponse $row): array
    {
        return [
            'form_response_uuid' => $row->uuid,
            'masjid_id' => (string) $row->masjid_id,
            'form_id' => (string) $row->form_id,
        ];
    }

    /** An event raised on the organisation's connected account, unless the test says otherwise. */
    private function event(string $type, array $o, array $object): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . Str::random(24),
            'type' => $type,
            'account' => array_key_exists('account', $o) ? $o['account'] : self::ACCOUNT,
            'data' => ['object' => $object],
        ];
    }

    /** Signed exactly as Stripe signs, with the Connect endpoint's secret: these are direct charges. */
    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::CONNECT_SECRET);

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
}
