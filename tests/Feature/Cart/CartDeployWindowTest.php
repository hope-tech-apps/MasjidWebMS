<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
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
use RuntimeException;
use Tests\TestCase;

/**
 * The window between "the new code is live" and "migrate has run" (pre-merge fix B6).
 *
 * bin/deploy fast-forwards the code and installs it BEFORE `php artisan migrate --force`, and if
 * migrate fails the deploy stops there with the new code serving the old schema. In that window the
 * cart's tables do not exist, and three paths that run whether or not the cart is switched on used
 * to query them: the form registrations' refund arm (`whereNotExists` over order_items), the cart's
 * own refund arm (which logged a false error-level alarm on every refund) and a member's
 * "Delete account". Each now asks CartTables first. Here the tables are dropped, which is
 * what that window looks like.
 *
 * Round 3 adds the other direction: a check that THROWS (a database that cannot answer
 * information_schema) is a different thing from a table that is not there. `CartTables::has()`
 * fails SAFE and answers "absent" (the live form refund arm and the prune, where absent is
 * harmless); `CartTables::existsOrFail()` fails CLOSED and rethrows (every path that deletes or
 * moves data, where a false "absent" would erase or orphan a paid order). The tests below pin both.
 *
 * The daily `cart:prune` (03:41) is the third caller of the fail-safe question: with the tables absent
 * it logs one info line, exits 0 and touches nothing.
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

    // ------------------------------------------------------------ the prune

    #[Test]
    public function the_prune_in_the_window_logs_one_info_line_exits_zero_and_touches_no_cart_table(): void
    {
        $this->dropTheCartTables();

        $touched = [];
        DB::listen(function ($query) use (&$touched): void {
            // A double-quoted identifier: the table-exists question names its table in single quotes.
            if (preg_match('/"(carts|cart_items|orders|order_items)"/', (string) $query->sql) === 1) {
                $touched[] = (string) $query->sql;
            }
        });

        // Without the guard the sweep's first query failed on a table that is not there.
        $this->artisan('cart:prune')
            ->expectsOutputToContain('cart tables not migrated yet; nothing to prune')
            ->assertExitCode(0);

        $this->assertSame([], $touched, 'no statement named a cart table');

        Log::shouldHaveReceived('info')->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'cart tables not migrated yet; nothing to prune')
            ->once();
    }

    #[Test]
    public function the_prune_skips_the_night_and_deletes_nothing_when_the_table_check_itself_throws(): void
    {
        // An open basket that expired ten days ago: exactly what the sweep deletes when it runs.
        $basket = $this->cart($this->org());
        $basket->forceFill(['expires_at' => now()->subDays(10)])->save();
        $this->breakTheTableCheck('carts');

        // Skipping one night is harmless (the next run asks again); a stack trace in the scheduler is not.
        $this->artisan('cart:prune')->assertExitCode(0);

        $this->assertTrue(Cart::withoutMasjidScope()->whereKey($basket->id)->exists(), 'nothing was deleted');
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'cart tables not migrated yet; nothing to prune')
            ->once();
    }

    // ------------------------------------------------------------ a check that throws

    /**
     * The database cannot answer "does this table exist?" for ONE table: a `DB::listen` on the SQLite
     * grammar's `sqlite_master ... name = '<table>'` (Laravel 12.64, read in Grammars/SQLiteGrammar::
     * compileTableExists), the way CartRefundArmIsolationTest breaks `orders`. Returns the switch, so a
     * test can mend the database again.
     */
    private function breakTheTableCheck(string $table): object
    {
        $switch = (object) ['broken' => true];

        DB::listen(function ($query) use ($table, $switch): void {
            if ($switch->broken
                && str_contains((string) $query->sql, 'sqlite_master')
                && str_contains((string) $query->sql, "name = '{$table}'")) {
                throw new RuntimeException('the schema is unavailable');
            }
        });

        return $switch;
    }

    /** Run $work, which asserts nothing, and hand back the RuntimeException it threw, or null. */
    private function thrownBy(callable $work): ?RuntimeException
    {
        try {
            $work();
        } catch (RuntimeException $e) {
            return $e;
        }

        return null;
    }

    /** A PAID cart order held by this contact: a sale the organisation keeps. */
    private function paidOrderOf(Contact $contact): int
    {
        return (int) DB::table('orders')->insertGetId([
            'masjid_id' => $contact->masjid_id, 'contact_id' => $contact->id, 'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)), 'status' => 'paid', 'total_minor' => 5000, 'fee_minor' => 0,
            'currency' => 'usd', 'charge_account_id' => 'acct_1WindowOwn', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_live_form_refund_is_still_flagged_and_answered_200_when_the_order_items_check_itself_throws(): void
    {
        $row = $this->pinnedPaidRow();
        $this->breakTheTableCheck('order_items');

        // Without the fail-safe the exception left the form arm, and the webhook answered 500.
        $this->postWebhook($this->refund())->assertOk();

        $flagged = $row->fresh();
        $this->assertSame(FormResponse::CHARGE_FLAG_REFUNDED, $flagged->charge_flag, 'the cart exclusion is skipped and the row is flagged exactly as before the cart');
        $this->assertSame(320, $flagged->charge_refunded_minor);

        // Said once, by class only; and it is not the cart arm's error-level alarm.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'cart tables could not be looked up')
                && ($context['exception'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'unavailable'))
            ->once();
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function the_fail_safe_question_answers_absent_and_warns_once_and_a_failure_is_never_remembered(): void
    {
        $database = $this->breakTheTableCheck('order_items');

        $this->assertFalse(CartTables::has('order_items'), 'a check that throws answers "absent"');
        $this->assertFalse(CartTables::has('order_items'));
        $this->assertFalse(CartTables::has('order_items'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'cart tables could not be looked up'))
            ->once();

        $database->broken = false;

        $this->assertTrue(CartTables::has('order_items'), 'the failure was not memoised: the next call asked again, and the table is there');
    }

    #[Test]
    public function the_strict_question_lets_a_failed_check_propagate_and_says_false_only_for_a_table_that_is_missing(): void
    {
        $database = $this->breakTheTableCheck('orders');

        $thrown = $this->thrownBy(fn () => CartTables::existsOrFail('orders'));

        $this->assertNotNull($thrown, 'a check that cannot be answered is not "absent"');
        $this->assertSame('the schema is unavailable', $thrown->getMessage());

        $database->broken = false;

        $this->assertTrue(CartTables::existsOrFail('orders'), 'nothing was remembered from the failure');

        $this->dropTheCartTables();

        $this->assertFalse(CartTables::existsOrFail('orders'), 'a table that genuinely is not there is false');
    }

    #[Test]
    public function the_strict_question_does_not_trust_a_remembered_absence(): void
    {
        // The window: the table is missing, and the fail-safe question remembers that for 30 seconds.
        Schema::dropIfExists('order_items');
        CartTables::forget();
        $this->assertFalse(CartTables::has('order_items'));

        // Migrate finishes inside those 30 seconds.
        Schema::create('order_items', function ($table): void {
            $table->id();
        });

        $this->assertFalse(CartTables::has('order_items'), 'the fail-safe question is content with what it remembered');
        $this->assertTrue(CartTables::existsOrFail('order_items'), 'a path that deletes data asks again');
    }

    #[Test]
    public function what_an_account_deletion_keeps_a_member_for_is_not_decided_by_an_orders_check_that_threw(): void
    {
        $member = $this->appMember();
        $this->paidOrderOf($member);
        $this->breakTheTableCheck('orders');

        $deletion = app(MemberAccountDeletion::class);

        $this->assertNotNull(
            $this->thrownBy(fn () => $deletion->reasonsToKeep($member)),
            'a fail-safe "absent" would skip the look at orders and say nothing keeps this member',
        );

        $this->assertNotNull($this->thrownBy(fn () => $deletion->delete($member, MemberAccountDeletion::VIA_WEB)));
        $this->assertDatabaseHas('contacts', ['id' => $member->id]);
        $this->assertSame(1, DB::table('orders')->where('contact_id', $member->id)->where('status', 'paid')->count(), 'the sale is still theirs');
    }

    #[Test]
    public function an_account_deletion_whose_baskets_check_threw_is_rolled_back_whole(): void
    {
        // reasonsToKeep does not ask about carts, so the deletion gets as far as clearing the
        // member's tokens before the basket step asks, which is what a rollback has to undo.
        $member = $this->appMember();
        $member->createMemberToken();
        $basket = DB::table('carts')->insertGetId([
            'masjid_id' => $member->masjid_id, 'contact_id' => $member->id, 'token_hash' => hash('sha256', uniqid('', true)),
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, $member->tokens()->count(), 'premise');

        $this->breakTheTableCheck('carts');

        $thrown = $this->thrownBy(fn () => app(MemberAccountDeletion::class)->delete($member, MemberAccountDeletion::VIA_WEB));

        $this->assertNotNull($thrown, 'an unanswerable check stops the deletion');
        $this->assertDatabaseHas('contacts', ['id' => $member->id]);
        $this->assertSame(1, $member->tokens()->count(), 'the token cleared earlier in the same transaction is back');
        $this->assertDatabaseHas('carts', ['id' => $basket]);
    }

    #[Test]
    public function an_account_deletion_whose_unpaid_checkout_check_threw_is_rolled_back_whole(): void
    {
        $member = $this->appMember();
        $member->createMemberToken();
        $this->assertSame(1, $member->tokens()->count(), 'premise');

        // Genuinely missing, so every strict question reaches the database (a remembered absence is not trusted).
        $this->dropTheCartTables();

        // reasonsToKeep asks about `orders` first and is answered; the checkout clean-up asks again, and is not.
        $asked = 0;
        DB::listen(function ($query) use (&$asked): void {
            if (str_contains((string) $query->sql, 'sqlite_master')
                && str_contains((string) $query->sql, "name = 'orders'")
                && ++$asked === 2) {
                throw new RuntimeException('the schema is unavailable');
            }
        });

        $thrown = $this->thrownBy(fn () => app(MemberAccountDeletion::class)->delete($member, MemberAccountDeletion::VIA_WEB));

        $this->assertSame(2, $asked, 'premise: the second question about orders is the clean-up step\'s');
        $this->assertNotNull($thrown, 'an unanswerable check stops the deletion');
        $this->assertDatabaseHas('contacts', ['id' => $member->id]);
        $this->assertSame(1, $member->tokens()->count(), 'the token cleared earlier in the same transaction is back');
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
