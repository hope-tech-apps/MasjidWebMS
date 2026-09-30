<?php

namespace Tests\Feature\Cart;

use App\Models\Contact;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Services\Member\MemberAccountDeletion;
use App\Support\CartTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The window between "the new code is live" and "migrate has run" (pre-merge fix B6).
 *
 * bin/deploy fast-forwards the code and installs it BEFORE `php artisan migrate --force`, and if
 * migrate fails the deploy stops there with the new code serving the old schema. In that window the
 * cart's tables do not exist, and three paths that run whether or not the cart is switched on used
 * to query them: the form registrations' refund arm (`whereNotExists` over order_items), the cart's
 * own refund arm (which logged a false error-level alarm on every refund) and a member's
 * "Delete account". Each now asks CartTables::has() first. Here the tables are dropped, which is
 * what that window looks like.
 */
class CartDeployWindowTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;

    private const CONNECT_SECRET = 'whsec_window_connect';

    private const HOLDER_ACCOUNT = 'acct_1WindowHolder';

    private const INTENT = 'pi_window_1';

    protected function setUp(): void
    {
        parent::setUp();

        // The memo is per process, and a test that drops tables must not inherit or leave one.
        CartTables::forget();

        config([
            'services.stripe.webhook_secret' => 'whsec_window_platform',
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
        ]);

        Mail::fake();
        Log::spy();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CartTables::forget();

        parent::tearDown();
    }

    /** What migrate has not done yet. SQLite rolls the DDL back with the test's own transaction. */
    private function dropTheCartTables(): void
    {
        foreach (CartTables::NAMES as $table) {
            Schema::dropIfExists($table);
        }

        CartTables::forget();

        foreach (CartTables::NAMES as $table) {
            $this->assertFalse(Schema::hasTable($table), "premise: {$table} does not exist");
        }
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

    private function pinnedPaidRow(): FormResponse
    {
        $holder = $this->org(['stripe_account_id' => self::HOLDER_ACCOUNT]);
        $child = $this->org(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $holder->id, 'forms_card_via_masjid_id' => $holder->id]);
        $form = $this->ticketForm($child);

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
            'stripe_checkout_session_id' => 'cs_window_1',
            'stripe_payment_intent_id' => self::INTENT,
            'charge_account_id' => self::HOLDER_ACCOUNT,
            'charge_masjid_id' => $holder->id,
            'charge_ref' => 'fcr_' . str_repeat('b', 32),
            'charge_expires_at' => now()->addMinutes(31),
        ])->save();

        return $row->fresh();
    }

    private function refund(): array
    {
        return [
            'id' => 'evt_' . Str::random(24),
            'type' => 'charge.refunded',
            'account' => self::HOLDER_ACCOUNT,
            'data' => ['object' => [
                'id' => 'ch_window_1',
                'object' => 'charge',
                'amount' => 10320,
                'currency' => 'usd',
                'payment_intent' => self::INTENT,
                'amount_refunded' => 320,
                'refunded' => false,
            ]],
        ];
    }

    // ------------------------------------------------------------ the form refund arm

    #[Test]
    public function a_form_refund_in_the_window_flags_its_row_and_raises_no_false_alarm(): void
    {
        $row = $this->pinnedPaidRow();
        $this->dropTheCartTables();

        $this->postWebhook($this->refund())->assertOk();

        $flagged = $row->fresh();
        $this->assertSame(FormResponse::CHARGE_FLAG_REFUNDED, $flagged->charge_flag, 'the live form refund still works with no order_items table');
        $this->assertSame(320, $flagged->charge_refunded_minor);

        // The cart's arm had nothing to look in and said nothing: not an error line per refund.
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function the_same_refund_still_skips_a_row_a_basket_settled_once_the_tables_exist(): void
    {
        // The guard must not switch the cart-settled exclusion off when order_items IS there.
        $row = $this->pinnedPaidRow();
        DB::table('orders')->insert([
            'id' => 1, 'masjid_id' => $row->masjid_id, 'uuid' => (string) Str::uuid(), 'order_number' => 'WINDOW01',
            'status' => 'paid', 'total_minor' => 10320, 'fee_minor' => 0, 'currency' => 'usd',
            'charge_account_id' => self::HOLDER_ACCOUNT, 'charge_ref' => 'cref_' . str_repeat('c', 32),
            'stripe_payment_intent_id' => self::INTENT, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => 1, 'masjid_id' => $row->masjid_id, 'buyable_type' => 'form', 'buyable_id' => $row->form_id,
            'recorded_as' => 'registration', 'label' => 'Ticket', 'quantity' => 1, 'unit_amount_minor' => 10320,
            'total_minor' => 10320, 'currency' => 'usd', 'record_type' => 'form_response', 'record_id' => $row->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postWebhook($this->refund())->assertOk();

        $this->assertNull($row->fresh()->charge_flag, 'a cart-settled row is the cart\'s: flagged on the order, never per line');
        $this->assertSame('partially_refunded', DB::table('orders')->where('id', 1)->value('charge_flag'), 'the cart arm flagged the order instead');
    }

    // ------------------------------------------------------------ Delete account

    private function appMember(): Contact
    {
        $address = 'member-' . uniqid() . '@example.org';
        $contact = Contact::factory()->create(['masjid_id' => $this->org()->id]);
        $contact->forceFill([
            'phone' => null,
            'notes' => null,
            'signup_source' => 'app',
            'email' => $address,
            'login_email' => $address,
        ])->save();

        return $contact->refresh();
    }

    #[Test]
    public function deleting_an_account_in_the_window_does_not_touch_tables_that_do_not_exist(): void
    {
        $member = $this->appMember();
        $this->dropTheCartTables();

        $deletion = app(MemberAccountDeletion::class);

        // Neither the decision nor the deletion may ask for orders or carts.
        $this->assertSame([], $deletion->reasonsToKeep($member), 'nothing keeps an app member the office holds nothing about');

        $result = $deletion->delete($member, MemberAccountDeletion::VIA_WEB);

        $this->assertSame(MemberAccountDeletion::OUTCOME_ERASED, $result['outcome']);
        $this->assertDatabaseMissing('contacts', ['id' => $member->id]);
    }

    // ------------------------------------------------------------ the memo

    #[Test]
    public function the_answer_is_asked_of_the_database_once_per_table_and_a_missing_table_is_asked_again_later(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', 'UTC'));

        $asked = 0;
        DB::listen(function ($query) use (&$asked): void {
            if (str_contains((string) $query->sql, 'sqlite_master')) {
                $asked++;
            }
        });

        $this->assertTrue(CartTables::has('order_items'));
        $perAsk = $asked; // however many statements the schema builder needs for one question
        $this->assertGreaterThan(0, $perAsk, 'premise: the question reached the database');
        $this->assertTrue(CartTables::has('order_items'));
        $this->assertTrue(CartTables::has('order_items'));
        $this->assertSame($perAsk, $asked, 'a table that exists is remembered for the life of the process');

        // A table that is missing is remembered too, but only for 30 seconds: a long-lived worker
        // that saw the window must not go on believing in it after migrate has finished.
        Schema::dropIfExists('order_items');
        CartTables::forget();
        $asked = 0;

        $this->assertFalse(CartTables::has('order_items'));
        $this->assertFalse(CartTables::has('order_items'));
        $this->assertSame($perAsk, $asked, 'asked once, then remembered');

        Schema::create('order_items', function ($table): void {
            $table->id();
        });

        Carbon::setTestNow(now()->addSeconds(10));
        $this->assertFalse(CartTables::has('order_items'), 'still inside the 30 seconds');

        Carbon::setTestNow(now()->addSeconds(25));
        $this->assertTrue(CartTables::has('order_items'), 'asked again, and now it is there');
        $this->assertSame(2 * $perAsk, $asked);
    }
}
