<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Services\Stripe\FormResponseCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The OUTBOUND leg for an organisation charged through its parent's account
 * (DECISIONS.md 2026-09-15, D2-D5, D8): BISS Sunday School through Burlington Masjid, end
 * to end through the public submit and "Return to payment".
 *
 * Every Stripe seam records WHICH account it was called on, and that is asserted:
 *
 *  - the page opens on the parent's account, with the account, its holder, a random
 *    reference and the page's expiry pinned on the row BEFORE Stripe is called;
 *  - the session's metadata, payment intent and client_reference_id carry no public handle
 *    (no uuid, no masjid id); the payment intent names BISS, the statement suffix is "BISS"
 *    and Adaptive Pricing is off. KNOWN EXCEPTION, asserted as such: success_url and
 *    cancel_url still carry the uuid, and the holder's Stripe users can read them;
 *  - a platform application fee, when configured, is sent as on any direct charge;
 *  - reopening reads and replaces the page on the PIN, and a holder that re-onboards moves
 *    the next page (and its key) to the new account; the key is dropped whenever the
 *    account changes and kept when it does not;
 *  - after an unlink no new page opens, but a pinned page that was paid still answers
 *    "confirming" (reopen and a replayed submit alike) and one still open is handed back,
 *    on the pin even once the holder has moved to a new account; the status read keeps
 *    "Return to payment" until the pinned expiry, without calling Stripe;
 *  - an account Stripe refuses outright (403) switches off the organisation that holds
 *    exactly that account, so the next family is refused before anything is written; an
 *    account the holder has since left switches nobody off; a bad platform key (401) is
 *    Stripe failing, never a disconnected holder;
 *  - closing a page for cash uses the pin, and an account Stripe no longer lets the platform
 *    act on is answered 'unreachable', never thrown.
 */
class FormLinkedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://sundayschool.example.org';

    private const PATH = '/register';

    private const HOLDER_ACCOUNT = 'acct_1BurlingtonCheckout';

    /** @var array<int, array{params: array<string,mixed>, account: string, key: string, persisted: bool}> */
    public static array $created = [];

    /** @var array<int, array{seam: string, account: string, session: string}> every retrieve and expire */
    public static array $asked = [];

    /** @var array<string,string> session id => Stripe's status */
    public static array $pages = [];

    /** Stripe no longer lets the platform read or close pages (the holder disconnected). */
    public static bool $unreachable = false;

    /** Stripe refuses to OPEN a page on the account (403). */
    public static bool $createUnreachable = false;

    /** The platform's own key is refused (401) on every read. */
    public static bool $authFailure = false;

    private Masjid $holder;

    private Masjid $biss;

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
        self::$asked = [];
        self::$pages = [];
        self::$unreachable = false;
        self::$createUnreachable = false;
        self::$authFailure = false;
        $this->stubStripe();

        $this->holder = $this->makeOrg('Burlington Masjid', ['stripe_account_id' => self::HOLDER_ACCOUNT, 'stripe_charges_enabled' => true]);
        $this->biss = $this->makeOrg('Burlington Islamic Sunday School');
        DB::table('masjids')->where('id', $this->biss->id)->update(['parent_id' => $this->holder->id, 'forms_card_via_masjid_id' => $this->holder->id]);

        $this->form = $this->makeForm($this->biss);
    }

    // ------------------------------------------------------------ opening a page

    #[Test]
    public function a_linked_registration_opens_its_page_on_the_parents_account_with_the_pin_saved_first_and_no_public_handle(): void
    {
        $before = now()->getTimestamp();
        $response = $this->submit()->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_1');

        $row = FormResponse::sole();
        $this->assertSame('cs_test_1', $row->stripe_checkout_session_id);
        $this->assertSame(self::HOLDER_ACCOUNT, $row->charge_account_id);
        $this->assertSame($this->holder->id, (int) $row->charge_masjid_id);
        $this->assertMatchesRegularExpression('/^fcr_[0-9a-f]{32}$/', (string) $row->charge_ref);
        $this->assertNull($row->charge_flag);

        $this->assertCount(1, self::$created);
        $created = self::$created[0];
        $params = $created['params'];

        $this->assertSame(self::HOLDER_ACCOUNT, $created['account'], 'a direct charge on the holder\'s account');
        $this->assertSame($row->idempotency_key, $created['key']);
        $this->assertTrue($created['persisted'], 'the pin and the key are on the row BEFORE Stripe is called');
        $this->assertSame($params['expires_at'], $row->charge_expires_at->getTimestamp());
        $this->assertGreaterThanOrEqual($before + 30 * 60, $params['expires_at']);

        $routing = ['form_charge_ref' => $row->charge_ref, 'form_id' => (string) $this->form->id];
        $this->assertSame($routing, $params['metadata']);
        $this->assertSame($routing, $params['payment_intent_data']['metadata']);
        $this->assertSame('Burlington Islamic Sunday School — Registration 2026', $params['payment_intent_data']['description']);
        $this->assertSame('BISS', $params['payment_intent_data']['statement_descriptor_suffix']);
        $this->assertArrayNotHasKey('client_reference_id', $params);
        $this->assertSame(['enabled' => false], $params['adaptive_pricing'], 'the session reports exactly the row\'s total in the row\'s currency');
        $this->assertSame(['card'], $params['payment_method_types']);

        // KNOWN EXPOSURE (DECISIONS.md 2026-09-15): the return URLs carry the row's uuid, and
        // the holder's Stripe users can read them on the session. Asserted, not hidden.
        $this->assertStringContainsString('form_response=' . $row->uuid, $params['success_url']);
        $this->assertStringContainsString('form_response=' . $row->uuid, $params['cancel_url']);

        // Everything else the holder's Stripe users read: no bearer handle, no masjid id.
        $rest = $params;
        unset($rest['success_url'], $rest['cancel_url']);
        $sent = json_encode($rest);
        $this->assertStringNotContainsString((string) $row->uuid, $sent);
        $this->assertStringNotContainsString('form_response_uuid', $sent);
        $this->assertStringNotContainsString('"masjid_id"', $sent);

        // The payer is never shown the holder's account or the reference, and BISS copies nothing.
        $this->assertStringNotContainsString('acct_', $response->getContent());
        $this->assertStringNotContainsString((string) $row->charge_ref, $response->getContent());
        $this->assertArrayNotHasKey('charge_account_id', $row->toArray());
        $this->assertNull($this->biss->fresh()->stripe_account_id);
        $this->assertFalse($this->biss->fresh()->canAcceptDonations());
    }

    #[Test]
    public function return_to_payment_reads_and_replaces_the_page_on_the_pin(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();
        $ref = $row->charge_ref;
        $firstKey = $row->idempotency_key;

        $this->reopen($row->uuid)->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_1');
        $this->assertSame([['seam' => 'retrieve', 'account' => self::HOLDER_ACCOUNT, 'session' => 'cs_test_1']], self::$asked);
        $this->assertCount(1, self::$created, 'an open page is handed back, never doubled');

        self::$pages['cs_test_1'] = 'expired';
        $this->reopen($row->uuid)->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');

        $row->refresh();
        $this->assertCount(2, self::$created);
        $this->assertSame(self::HOLDER_ACCOUNT, self::$created[1]['account']);
        $this->assertTrue(self::$created[1]['persisted']);
        $this->assertSame($ref, $row->charge_ref, 'one reference per registration');
        $this->assertSame($ref, self::$created[1]['params']['metadata']['form_charge_ref']);
        $this->assertNotSame($firstKey, $row->idempotency_key, 'a replaced page gets a new key');
        $this->assertSame('cs_test_2', $row->stripe_checkout_session_id);
    }

    #[Test]
    public function a_holder_that_re_onboards_moves_the_next_page_and_its_key_to_the_new_account(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();
        $firstKey = $row->idempotency_key;

        self::$pages['cs_test_1'] = 'expired';
        $this->holder->forceFill(['stripe_account_id' => 'acct_1BurlingtonReonboarded'])->save();

        $this->reopen($row->uuid)->assertOk();

        // The old page is asked about where it lives; the new one opens on the live account.
        $this->assertSame(self::HOLDER_ACCOUNT, self::$asked[0]['account']);
        $this->assertSame('acct_1BurlingtonReonboarded', self::$created[1]['account']);
        $this->assertTrue(self::$created[1]['persisted']);

        $row->refresh();
        $this->assertSame('acct_1BurlingtonReonboarded', $row->charge_account_id);
        $this->assertNotSame($firstKey, $row->idempotency_key);
        $this->assertSame(self::$created[1]['key'], $row->idempotency_key);
    }

    // ------------------------------------------------------------ after an unlink (D8)

    #[Test]
    public function after_an_unlink_no_new_page_opens_but_a_paid_or_open_pinned_page_is_answered_from_stripe_first(): void
    {
        $paidKey = (string) Str::uuid();
        $this->submit(['client_submission_key' => $paidKey], $this->answers('Paid Family', 'paid@example.com'))->assertOk();
        $this->submit([], $this->answers('Open Family', 'open@example.com'))->assertOk();
        $this->submit([], $this->answers('Expired Family', 'expired@example.com'))->assertOk();

        [$paid, $open, $expired] = FormResponse::query()->orderBy('id')->get()->all();
        self::$pages['cs_test_1'] = 'complete';
        self::$pages['cs_test_3'] = 'expired';

        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => null]);

        // And the holder has since moved to a new account: every page is still asked about on its pin.
        $this->holder->forceFill(['stripe_account_id' => 'acct_1BurlingtonMovedAway'])->save();

        // A new registration: refused before anything is written.
        $this->submit([], $this->answers('New Family', 'new@example.com'))
            ->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->assertSame(3, FormResponse::count());

        // Paid on Stripe, not yet recorded: "confirming", from reopen and from a replayed submit.
        $this->reopen($paid->uuid)->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::PAID_ON_STRIPE)
            ->assertJsonPath('data.confirming', true)
            ->assertJsonPath('data.can_pay', false);

        $this->submit(['client_submission_key' => $paidKey], $this->answers('Paid Family', 'paid@example.com'))
            ->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::PAID_ON_STRIPE)
            ->assertJsonPath('data.confirming', true)
            ->assertJsonPath('data.uuid', $paid->uuid);

        // Still open: handed back. Expired: now it is unavailable.
        $this->reopen($open->uuid)->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');
        $this->reopen($expired->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);

        // Every read went to the pin.
        $this->assertNotEmpty(self::$asked);
        $this->assertSame([self::HOLDER_ACCOUNT], array_values(array_unique(array_column(self::$asked, 'account'))));

        // An account Stripe no longer lets the platform read: unavailable, never an exception.
        self::$unreachable = true;
        $this->reopen($open->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);

        // Nobody holds the pinned account any more, so nobody is switched off.
        $this->assertTrue((bool) $this->holder->fresh()->stripe_charges_enabled);
        $this->assertNull($this->holder->fresh()->stripe_deauthorized_at);

        $this->assertCount(3, self::$created, 'no page was opened after the unlink');
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $paid->fresh()->payment_status, 'only the webhook records a payment');
    }

    #[Test]
    public function after_an_unlink_the_status_read_keeps_return_to_payment_until_the_pinned_page_expires_without_calling_stripe(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();

        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => null]);
        $asked = count(self::$asked);

        $this->statusRead($row->uuid)->assertOk()->assertJsonPath('data.can_pay', true);
        $this->assertCount($asked, self::$asked, 'the status read never calls Stripe');

        // The button leads to the page the family already has.
        $this->reopen($row->uuid)->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_1')
            ->assertJsonPath('data.can_pay', true);
        $this->assertCount(1, self::$created);

        // Past the pinned expiry the page can take nothing, and card payment is unavailable.
        $this->travel(32)->minutes();
        $this->statusRead($row->uuid)->assertOk()->assertJsonPath('data.can_pay', false);

        // A row never pinned is answered exactly as before: unavailable means no button.
        $this->travelBack();
        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => $this->holder->id]);
        $unpinned = $this->bareRow(['stripe_checkout_session_id' => 'cs_never_pinned', 'charge_expires_at' => now()->addMinutes(20)]);
        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => null]);
        $this->statusRead($unpinned->uuid)->assertOk()->assertJsonPath('data.can_pay', false);
    }

    #[Test]
    public function a_holder_whose_charges_go_off_refuses_new_registrations_by_card(): void
    {
        $this->holder->forceFill(['stripe_charges_enabled' => false])->save();

        $this->submit()->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->assertSame(0, FormResponse::count());
        $this->assertSame([], self::$created);
    }

    #[Test]
    public function a_platform_application_fee_is_sent_on_a_linked_charge_as_on_any_direct_charge(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0.01]);

        $this->submit()->assertOk();
        $row = FormResponse::sole();

        $this->assertSame(self::HOLDER_ACCOUNT, self::$created[0]['account']);
        $this->assertSame((int) round((int) $row->total_minor * 0.01), self::$created[0]['params']['payment_intent_data']['application_fee_amount']);
    }

    #[Test]
    public function a_pinned_page_on_an_account_stripe_refuses_switches_its_holder_off_so_the_next_family_is_refused_before_anything_is_written(): void
    {
        Log::spy();
        $bystander = $this->makeOrg('Bystander Masjid', ['stripe_account_id' => 'acct_1BystanderCheckout', 'stripe_charges_enabled' => true, 'stripe_payouts_enabled' => true]);

        $this->submit()->assertOk();
        $row = FormResponse::sole();
        self::$unreachable = true;

        // The page may still be open until its pinned expiry: nothing new, and card is refused.
        $this->reopen($row->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);

        $holder = $this->holder->fresh();
        $this->assertFalse((bool) $holder->stripe_charges_enabled, 'switched off, though the refusal rolled the row back');
        $this->assertFalse((bool) $holder->stripe_payouts_enabled);
        $this->assertNotNull($holder->stripe_deauthorized_at);
        $this->assertSame(self::HOLDER_ACCOUNT, $holder->stripe_account_id, 'the account id stays: pinned pages still name it');
        $this->assertTrue((bool) $bystander->fresh()->stripe_charges_enabled, 'nobody else is touched');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'switched off until it reconnects')
                && ($context['account'] ?? null) === self::HOLDER_ACCOUNT
                && ($context['masjid_ids'] ?? null) === [$this->holder->id])
            ->once();

        // The next family: refused before anything is written, never an ONLINE row that cannot open.
        $this->submit([], $this->answers('Next Family', 'next@example.com'))
            ->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->assertSame(1, FormResponse::count());

        // Past its expiry the pinned page is not replaced: card payment is off.
        $this->travel(32)->minutes();
        $this->reopen($row->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->assertCount(1, self::$created);
    }

    #[Test]
    public function a_pinned_page_on_an_account_the_holder_has_since_left_is_replaced_only_after_its_pinned_expiry_and_switches_nobody_off(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();

        $this->holder->forceFill(['stripe_account_id' => 'acct_1BurlingtonReonboarded'])->save();
        self::$unreachable = true;

        $this->reopen($row->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->assertCount(1, self::$created);
        $this->assertTrue((bool) $this->holder->fresh()->stripe_charges_enabled, 'the refused account is not the holder\'s current one');

        $this->travel(32)->minutes();

        $this->reopen($row->uuid)->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');
        $this->assertSame(self::HOLDER_ACCOUNT, self::$asked[0]['account'], 'the old page is asked about on its pin');
        $this->assertSame('acct_1BurlingtonReonboarded', self::$created[1]['account']);
        $this->assertSame('acct_1BurlingtonReonboarded', $row->fresh()->charge_account_id);
    }

    #[Test]
    public function a_linked_page_stripe_refuses_to_open_switches_the_holder_off_and_pins_nothing(): void
    {
        self::$createUnreachable = true;

        $this->submit()->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::COULD_NOT_OPEN);

        $row = FormResponse::sole();
        $this->assertNull($row->stripe_checkout_session_id);
        $this->assertNull($row->charge_account_id, 'the pin rolled back with the failed attempt');
        $this->assertFalse((bool) $this->holder->fresh()->stripe_charges_enabled);
        $this->assertNotNull($this->holder->fresh()->stripe_deauthorized_at);

        self::$createUnreachable = false;

        $this->submit([], $this->answers('Next Family', 'next@example.com'))
            ->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::UNAVAILABLE);
        $this->assertSame(1, FormResponse::count());
    }

    #[Test]
    public function a_bad_platform_key_is_stripe_failing_never_a_disconnected_holder(): void
    {
        $this->submit()->assertOk();
        $row = FormResponse::sole();
        self::$authFailure = true;

        $this->reopen($row->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::COULD_NOT_OPEN);

        // Not even past the pinned expiry is the page treated as gone.
        $this->travel(32)->minutes();
        $this->reopen($row->uuid)->assertStatus(422)->assertJsonPath('message', FormResponseCheckoutService::COULD_NOT_OPEN);

        $this->assertCount(1, self::$created);
        $holder = $this->holder->fresh();
        $this->assertTrue((bool) $holder->stripe_charges_enabled);
        $this->assertNull($holder->stripe_deauthorized_at);
    }

    #[Test]
    public function the_idempotency_key_is_dropped_whenever_the_account_a_page_opens_on_changes_and_kept_when_it_does_not(): void
    {
        $service = app(FormResponseCheckoutService::class);
        $returnTo = self::ORIGIN . self::PATH;

        // A key saved by an attempt that recorded no page, pinned to the holder's old account.
        $moved = $this->bareRow([
            'idempotency_key' => 'form_response_old_account',
            'charge_account_id' => self::HOLDER_ACCOUNT,
            'charge_masjid_id' => $this->holder->id,
            'charge_ref' => 'fcr_' . str_repeat('a', 32),
            'charge_expires_at' => now()->subMinute(),
        ]);
        $this->holder->forceFill(['stripe_account_id' => 'acct_1BurlingtonKeys'])->save();

        $service->checkout($moved, $returnTo);

        $this->assertSame('acct_1BurlingtonKeys', self::$created[0]['account']);
        $this->assertNotSame('form_response_old_account', self::$created[0]['key'], 'keys are scoped per account');
        $this->assertSame(self::$created[0]['key'], $moved->fresh()->idempotency_key);
        $this->assertSame('acct_1BurlingtonKeys', $moved->fresh()->charge_account_id);

        // Pinned to the account the page opens on: the key is kept, so Stripe replays that attempt.
        $same = $this->bareRow([
            'idempotency_key' => 'form_response_same_account',
            'charge_account_id' => 'acct_1BurlingtonKeys',
            'charge_masjid_id' => $this->holder->id,
            'charge_ref' => 'fcr_' . str_repeat('b', 32),
            'charge_expires_at' => now()->subMinute(),
        ]);

        $service->checkout($same, $returnTo);
        $this->assertSame('form_response_same_account', self::$created[1]['key']);

        // Never pinned, now charged through the holder: a key from before is dropped.
        $before = $this->bareRow(['idempotency_key' => 'form_response_before_the_link']);

        $service->checkout($before, $returnTo);
        $this->assertNotSame('form_response_before_the_link', self::$created[2]['key']);
        $this->assertSame(self::$created[2]['key'], $before->fresh()->idempotency_key);
    }

    // ------------------------------------------------------------ closing a page

    #[Test]
    public function closing_a_pinned_page_expires_it_on_the_pin_even_after_an_unlink_and_an_unreachable_account_is_its_own_answer(): void
    {
        $service = app(FormResponseCheckoutService::class);

        $this->submit()->assertOk();
        $first = FormResponse::sole();

        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => null]);
        $this->assertSame('expired', $service->closeOpenSession($first));
        $this->assertSame(['seam' => 'expire', 'account' => self::HOLDER_ACCOUNT, 'session' => 'cs_test_1'], end(self::$asked));

        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => $this->holder->id]);
        $this->submit([], $this->answers('Second Family', 'second@example.com'))->assertOk();
        $second = FormResponse::query()->latest('id')->first();

        self::$unreachable = true;
        $this->assertSame(FormResponseCheckoutService::UNREACHABLE, $service->closeOpenSession($second));
        $this->assertSame(self::HOLDER_ACCOUNT, end(self::$asked)['account']);
        $this->assertSame('cs_test_2', $second->fresh()->stripe_checkout_session_id);
        $this->assertFalse((bool) $this->holder->fresh()->stripe_charges_enabled, 'the holder of the refused account is switched off');
    }

    // ------------------------------------------------------------------- helpers

    private function submit(array $extra = [], ?array $data = null): TestResponse
    {
        return $this->postJson("/api/v1/forms/{$this->form->id}/responses", array_merge([
            'data' => $data ?? $this->answers(),
            'return_path' => self::PATH,
            'client_submission_key' => (string) Str::uuid(),
        ], $extra), ['masjid-id' => (string) $this->biss->id, 'Origin' => self::ORIGIN]);
    }

    private function reopen(string $uuid): TestResponse
    {
        return $this->postJson("/api/v1/form-responses/{$uuid}/checkout", ['return_path' => self::PATH], [
            'masjid-id' => (string) $this->biss->id,
            'Origin' => self::ORIGIN,
        ]);
    }

    /** GET the public status read (PHPUnit's own status() is final, hence the name). */
    private function statusRead(string $uuid): TestResponse
    {
        return $this->getJson("/api/v1/form-responses/{$uuid}", ['masjid-id' => (string) $this->biss->id]);
    }

    private function answers(string $name = 'Amal Yusuf', string $email = 'amal@example.com'): array
    {
        return ['fullName' => $name, 'email' => $email];
    }

    /** An unpaid BISS card row written by hand: $100, no fee, no page unless $attributes add one. */
    private function bareRow(array $attributes = []): FormResponse
    {
        $row = new FormResponse([
            'form_id' => $this->form->id,
            'masjid_id' => $this->biss->id,
            'data' => $this->answers(),
            'respondent_name' => 'Amal Yusuf',
            'respondent_email' => 'amal@example.com',
            'entry_count' => 1,
            'amount_due' => 100,
            'status' => 'new',
            'submitted_at' => now(),
        ]);

        $row->forceFill(array_merge([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'currency' => 'usd',
            'amount_due_minor' => 10000,
            'fee_covered_minor' => 0,
            'total_minor' => 10000,
        ], $attributes))->save();

        return $row->fresh();
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
                'notifyEmails' => ['biss-office@example.org'],
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

    /** Stripe as far as the service can tell, recording the account of every call. */
    private function stubStripe(): void
    {
        $this->app->bind(FormResponseCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    if (FormLinkedCheckoutTest::$createUnreachable) {
                        throw \Stripe\Exception\PermissionException::factory('The provided key does not have access to account.', 403);
                    }

                    $n = count(FormLinkedCheckoutTest::$created) + 1;
                    $ref = $params['metadata']['form_charge_ref'] ?? null;

                    FormLinkedCheckoutTest::$created[] = [
                        'params' => $params,
                        'account' => $connectedAccountId,
                        'key' => $idempotencyKey,
                        'persisted' => $ref !== null && FormResponse::query()
                            ->where('charge_ref', $ref)
                            ->where('idempotency_key', $idempotencyKey)
                            ->where('charge_account_id', $connectedAccountId)
                            ->whereNotNull('charge_masjid_id')
                            ->whereNotNull('charge_expires_at')
                            ->exists(),
                    ];

                    FormLinkedCheckoutTest::$pages["cs_test_{$n}"] = 'open';

                    return ['id' => "cs_test_{$n}", 'url' => "https://checkout.stripe.test/pay/cs_test_{$n}", 'payment_intent' => null];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    FormLinkedCheckoutTest::$asked[] = ['seam' => 'retrieve', 'account' => $connectedAccountId, 'session' => $sessionId];

                    if (FormLinkedCheckoutTest::$authFailure) {
                        throw \Stripe\Exception\AuthenticationException::factory('Invalid API Key provided.', 401);
                    }

                    if (FormLinkedCheckoutTest::$unreachable) {
                        throw \Stripe\Exception\PermissionException::factory('The provided key does not have access to account.', 403);
                    }

                    $status = FormLinkedCheckoutTest::$pages[$sessionId] ?? 'expired';

                    return ['status' => $status, 'url' => $status === 'open' ? "https://checkout.stripe.test/pay/{$sessionId}" : null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    FormLinkedCheckoutTest::$asked[] = ['seam' => 'expire', 'account' => $connectedAccountId, 'session' => $sessionId];

                    if (FormLinkedCheckoutTest::$unreachable) {
                        throw \Stripe\Exception\PermissionException::factory('The provided key does not have access to account.', 403);
                    }

                    FormLinkedCheckoutTest::$pages[$sessionId] = 'expired';
                }
            };
        });
    }
}
