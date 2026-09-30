<?php

namespace Tests\Feature\Cart;

use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\CartTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The cart's refund arm cannot break the live form refund (pre-merge fix B5).
 *
 * StripeWebhookController::handleChargeFlag asks the cart first (CartPaymentService::handleChargeFlag,
 * a basket's one charge is flagged on its ORDER) and the form arm second. The form arm is live
 * production money handling that predates the cart; the cart arm reads a table that may be missing
 * (the deploy window), locked or broken. What keeps the two apart is a catch-all around the cart
 * arm, and nothing tested it: delete the try/catch and every existing test stayed green while a
 * cart-side failure turned every form refund into a 500 that Stripe retries and, in the end, loses.
 *
 * Here the cart arm's own first query throws, and the form arm must still flag its row. So does
 * the cart arm's table check (`CartTables::existsOrFail('orders')`, the deploy-window guard), which sits
 * INSIDE that catch-all: a database that cannot answer it is the cart arm's failure too.
 */
class CartRefundArmIsolationTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;

    private const CONNECT_SECRET = 'whsec_isolation_connect';

    private const HOLDER_ACCOUNT = 'acct_1IsolationHolder';

    private const INTENT = 'pi_isolation_1';

    private Masjid $holder;

    private FormResponse $row;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => 'whsec_isolation_platform',
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
        ]);

        Mail::fake();
        Log::spy();

        // The table guard remembers a table it has seen for the life of the process, so a test that
        // breaks its question must start with nothing remembered.
        CartTables::forget();

        $this->holder = $this->org(['stripe_account_id' => self::HOLDER_ACCOUNT]);
        $child = $this->org(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $this->holder->id, 'forms_card_via_masjid_id' => $this->holder->id]);

        $this->row = $this->paidPinnedRow($this->ticketForm($child));
    }

    protected function tearDown(): void
    {
        CartTables::forget();

        parent::tearDown();
    }

    /** A registration paid through another organisation's account, as the FORM arm flags it: no basket anywhere. */
    private function paidPinnedRow($form): FormResponse
    {
        $row = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['tickets' => [['attendeeName' => 'Amal Yusuf']]],
            'respondent_name' => 'Amal Yusuf',
            'respondent_email' => 'amal@example.com',
            'entry_count' => 1,
            'amount_due' => 100,
            'status' => 'new',
            'submitted_at' => now(),
        ]);

        $row->forceFill([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'currency' => 'usd',
            'amount_due_minor' => 10000,
            'fee_covered_minor' => 320,
            'total_minor' => 10320,
            'idempotency_key' => 'form_response_' . Str::uuid(),
            'stripe_checkout_session_id' => 'cs_isolation_1',
            'stripe_payment_intent_id' => self::INTENT,
            'charge_account_id' => self::HOLDER_ACCOUNT,
            'charge_masjid_id' => $this->holder->id,
            'charge_ref' => 'fcr_' . str_repeat('a', 32),
            'charge_expires_at' => now()->addMinutes(31),
        ])->save();

        return $row->fresh();
    }

    private function chargeEvent(string $type, array $object): array
    {
        return [
            'id' => 'evt_' . Str::random(24),
            'type' => $type,
            'account' => self::HOLDER_ACCOUNT,
            'data' => ['object' => array_merge([
                'id' => $type === 'charge.refunded' ? 'ch_isolation_1' : 'dp_isolation_1',
                'object' => $type === 'charge.refunded' ? 'charge' : 'dispute',
                'amount' => 10320,
                'currency' => 'usd',
                'payment_intent' => self::INTENT,
            ], $object)],
        ];
    }

    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::CONNECT_SECRET);

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** The cart arm's own first question, the one lookup of orders by payment intent, now fails. */
    private function breakTheCartArm(): void
    {
        DB::listen(function ($query): void {
            if (str_starts_with((string) $query->sql, 'select * from "orders"')) {
                throw new RuntimeException('the orders table is unavailable');
            }
        });
    }

    /**
     * The cart arm's deploy-window guard, `Schema::hasTable('orders')`, is what fails: the
     * database cannot answer it (an information_schema error, say). Only that one table's
     * question, so the FORM arm's own guard on `order_items` still answers.
     */
    private function breakTheCartArmsTableCheck(): void
    {
        DB::listen(function ($query): void {
            if (str_contains((string) $query->sql, 'sqlite_master') && str_contains((string) $query->sql, "name = 'orders'")) {
                throw new RuntimeException('the schema is unavailable');
            }
        });
    }

    private function assertTheCartArmSaidSoByClassOnly(): void
    {
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'could not be recorded on its order')
                && ($context['exception'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'unavailable'))
            ->once();
    }

    #[Test]
    public function premise_the_form_arm_flags_its_row_when_the_cart_arm_works(): void
    {
        $this->postWebhook($this->chargeEvent('charge.refunded', ['amount_refunded' => 320, 'refunded' => false]))->assertOk();

        $this->assertSame(FormResponse::CHARGE_FLAG_REFUNDED, $this->row->fresh()->charge_flag);
        $this->assertSame(320, $this->row->fresh()->charge_refunded_minor);
    }

    #[Test]
    public function a_refund_still_flags_the_form_row_when_the_cart_arm_throws(): void
    {
        $this->breakTheCartArm();

        $this->postWebhook($this->chargeEvent('charge.refunded', ['amount_refunded' => 320, 'refunded' => false]))
            ->assertOk(); // not the 500 that would make Stripe retry, and in the end give up

        $flagged = $this->row->fresh();
        $this->assertSame(FormResponse::CHARGE_FLAG_REFUNDED, $flagged->charge_flag, 'the live form refund is unaffected by a broken cart arm');
        $this->assertSame(320, $flagged->charge_refunded_minor);
        $this->assertSame(FormResponse::PAYMENT_PAID, $flagged->payment_status);

        // ...and the cart arm said so, by class only.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'could not be recorded on its order')
                && ($context['exception'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'unavailable'))
            ->once();
    }

    #[Test]
    public function a_dispute_still_flags_the_form_row_when_the_cart_arm_throws(): void
    {
        $this->breakTheCartArm();

        $this->postWebhook($this->chargeEvent('charge.dispute.created', []))->assertOk();

        $this->assertSame(FormResponse::CHARGE_FLAG_DISPUTED, $this->row->fresh()->charge_flag);
    }

    #[Test]
    public function a_refund_still_flags_the_form_row_when_the_cart_arms_table_check_throws(): void
    {
        $this->breakTheCartArmsTableCheck();

        $this->postWebhook($this->chargeEvent('charge.refunded', ['amount_refunded' => 320, 'refunded' => false]))
            ->assertOk(); // the guard's failure is the cart arm's, not a 500 for the form refund

        $flagged = $this->row->fresh();
        $this->assertSame(FormResponse::CHARGE_FLAG_REFUNDED, $flagged->charge_flag, 'the form arm still runs after a cart arm that threw before its own try');
        $this->assertSame(320, $flagged->charge_refunded_minor);

        $this->assertTheCartArmSaidSoByClassOnly();
    }

    #[Test]
    public function a_dispute_still_flags_the_form_row_when_the_cart_arms_table_check_throws(): void
    {
        $this->breakTheCartArmsTableCheck();

        $this->postWebhook($this->chargeEvent('charge.dispute.created', []))->assertOk();

        $this->assertSame(FormResponse::CHARGE_FLAG_DISPUTED, $this->row->fresh()->charge_flag);
        $this->assertTheCartArmSaidSoByClassOnly();
    }
}
