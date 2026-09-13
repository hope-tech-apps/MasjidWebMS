<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\FormResponseCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Settling by hand a registration whose card page was pinned to another organisation's
 * account (DECISIONS.md 2026-09-15, D9, D16): BISS Sunday School's staff at the office,
 * BISS's card pages on Burlington Masjid's Stripe account.
 *
 *  - take cash and mark paid close the page ON THE PIN (the seams record the account), even
 *    once the holder has moved to a new account;
 *  - a page Stripe no longer lets the platform check (a 403 on the read, or
 *    `account_invalid` on the close) is 409 `page_unreachable`, naming the holder and the
 *    page's expiry, switches the holder of that account off, and records nothing, until
 *    the page has expired AND the admin confirms checking the holder's dashboard
 *    (form-encoded "true"/"1" work);
 *  - a bad PLATFORM key (401) is never "unreachable": 503, nothing recorded, nobody switched
 *    off, whatever the admin confirms;
 *  - a page paid on the holder's account refuses cash and mark paid; a cancel still
 *    commits and tells the admin that only the holder can refund it;
 *  - the row says whose account charged it, its payment intent, and what the holder did
 *    to the charge, and never an account id.
 */
class FormLinkedHandSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const HOLDER_ACCOUNT = 'acct_1BurlingtonDoor';

    /** @var array<string,string> Stripe's view of each page */
    public static array $pages = [];

    /** @var array<int, array{seam: string, account: string, session: string}> */
    public static array $asked = [];

    public static bool $unreachable = false;

    /** null | 'authentication' (401 on the read) | 'account_invalid_on_expire' (400 account_invalid on the close only) */
    public static ?string $failure = null;

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

        Mail::fake();

        self::$pages = [];
        self::$asked = [];
        self::$unreachable = false;
        self::$failure = null;
        $this->stubStripe();

        $this->holder = $this->makeOrg('Burlington Masjid', ['stripe_account_id' => self::HOLDER_ACCOUNT, 'stripe_charges_enabled' => true]);
        $this->biss = $this->makeOrg('Burlington Islamic Sunday School');
        DB::table('masjids')->where('id', $this->biss->id)->update(['parent_id' => $this->holder->id, 'forms_card_via_masjid_id' => $this->holder->id]);

        $this->form = $this->makeForm($this->biss);

        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]));
    }

    #[Test]
    public function taking_cash_closes_the_pinned_page_on_the_holders_account(): void
    {
        $row = $this->pinnedRow();
        self::$pages[$row->stripe_checkout_session_id] = 'open';

        // The holder has since re-onboarded: the page still lives where it was opened.
        $this->holder->forceFill(['stripe_account_id' => 'acct_1BurlingtonNewDoor'])->save();

        $this->postJson($this->url("/{$row->id}/take-cash"))->assertOk()->assertJsonPath('message', 'Cash recorded.');

        $this->assertSame(['retrieve', 'expire'], array_column(self::$asked, 'seam'));
        $this->assertSame([self::HOLDER_ACCOUNT], array_values(array_unique(array_column(self::$asked, 'account'))));
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);
        $this->assertSame(FormResponse::METHOD_CASH, $row->fresh()->payment_method);
    }

    #[Test]
    public function an_unreachable_page_that_can_still_take_a_payment_is_never_settled_by_hand(): void
    {
        self::$unreachable = true;
        $row = $this->pinnedRow(['charge_expires_at' => now()->addMinutes(20)]);

        foreach ([
            $this->postJson($this->url("/{$row->id}/take-cash")),
            $this->postJson($this->url("/{$row->id}/take-cash"), ['confirm_holder_checked' => true]),
            $this->postJson($this->url("/{$row->id}/mark-paid-external"), ['via' => 'zelle', 'confirm_holder_checked' => true]),
        ] as $response) {
            $response->assertStatus(409)
                ->assertJsonPath('status', 'failed')
                ->assertJsonPath('code', 'page_unreachable')
                ->assertJsonPath('holder_name', 'Burlington Masjid')
                ->assertJsonPath('expires_at', $row->charge_expires_at->toIso8601String());

            $this->assertStringContainsString('until it expires', $response->json('message'));
            $this->assertStringNotContainsString('acct_', $response->getContent());
        }

        $this->assertStillUnpaid($row);
        $this->assertSame([self::HOLDER_ACCOUNT], array_values(array_unique(array_column(self::$asked, 'account'))));
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function every_way_stripe_says_the_account_is_gone_is_a_409_and_a_bad_platform_key_is_a_503_that_blames_nobody(): void
    {
        // A 403 on the read.
        self::$unreachable = true;
        $refused = $this->pinnedRow();

        $this->postJson($this->url("/{$refused->id}/take-cash"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'page_unreachable');
        $this->assertStillUnpaid($refused);
        $this->assertHolderSwitchedOff();

        // `account_invalid`, answered only by the close: the read said the page was open.
        $this->reconnectHolder();
        self::$unreachable = false;
        self::$failure = 'account_invalid_on_expire';
        self::$asked = [];
        $invalid = $this->pinnedRow();
        self::$pages[$invalid->stripe_checkout_session_id] = 'open';

        $this->postJson($this->url("/{$invalid->id}/mark-paid-external"), ['via' => 'zelle'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'page_unreachable');
        $this->assertSame(['retrieve', 'expire'], array_column(self::$asked, 'seam'), 'an unreachable close is not re-read as a refused one');
        $this->assertStillUnpaid($invalid);
        $this->assertHolderSwitchedOff();

        // A 401 is the platform's own key: Stripe failing. Nothing recorded and nobody switched
        // off, even past the pinned expiry with the check confirmed.
        $this->reconnectHolder();
        self::$failure = 'authentication';
        $badKey = $this->pinnedRow(['charge_expires_at' => now()->subMinute()]);

        $this->postJson($this->url("/{$badKey->id}/take-cash"), ['confirm_holder_checked' => true])
            ->assertStatus(503)
            ->assertJsonPath('status', 'failed')
            ->assertJsonMissingPath('code');
        $this->assertStillUnpaid($badKey);

        $this->putJson($this->url("/{$badKey->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('card_page', 'unconfirmed');

        $holder = $this->holder->fresh();
        $this->assertTrue((bool) $holder->stripe_charges_enabled);
        $this->assertNull($holder->stripe_deauthorized_at);
    }

    #[Test]
    public function an_expired_unreachable_page_is_settled_only_once_the_admin_confirms_checking_the_holders_dashboard(): void
    {
        self::$unreachable = true;
        $cash = $this->pinnedRow(['charge_expires_at' => now()->subMinute()]);
        $zelle = $this->pinnedRow(['charge_expires_at' => now()->subMinute()]);

        $this->postJson($this->url("/{$cash->id}/take-cash"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'page_unreachable')
            ->assertJsonPath('holder_name', 'Burlington Masjid');
        $this->postJson($this->url("/{$cash->id}/take-cash"), ['confirm_holder_checked' => false])->assertStatus(422);
        $this->postJson($this->url("/{$cash->id}/take-cash"), ['confirm_holder_checked' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
        $this->assertStillUnpaid($cash);

        // Form-encoded, as a plain browser form sends it.
        $this->post($this->url("/{$cash->id}/take-cash"), ['confirm_holder_checked' => 'true'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Cash recorded.');
        $this->assertSame(FormResponse::PAYMENT_PAID, $cash->fresh()->payment_status);

        $this->post($this->url("/{$zelle->id}/mark-paid-external"), ['via' => 'zelle', 'confirm_holder_checked' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Marked paid.');
        $this->assertSame(FormResponse::METHOD_EXTERNAL, $zelle->fresh()->payment_method);
        $this->assertSame('zelle', $zelle->fresh()->paid_via);
    }

    #[Test]
    public function a_page_paid_on_the_holders_account_refuses_cash_and_mark_paid_and_a_cancel_commits_naming_the_holder(): void
    {
        $row = $this->pinnedRow();
        self::$pages[$row->stripe_checkout_session_id] = 'complete';

        $this->postJson($this->url("/{$row->id}/take-cash"), ['confirm_holder_checked' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration has just been paid by card, and Stripe is confirming it. Do not take a second payment.');
        $this->postJson($this->url("/{$row->id}/mark-paid-external"), ['via' => 'check'])->assertStatus(422);
        $this->assertStillUnpaid($row);

        $response = $this->putJson($this->url("/{$row->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('card_page', 'paid_on_stripe')
            ->assertJsonPath('warning', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertStringContainsString("only Burlington Masjid can refund it", $response->json('message'));
        $this->assertStringContainsString($row->stripe_checkout_session_id, $response->json('message'));
        $this->assertSame(FormResponse::STATUS_CANCELLED, $row->fresh()->status);
    }

    #[Test]
    public function cancelling_an_unreachable_or_already_paid_linked_registration_commits_and_says_whose_refund_it_is(): void
    {
        self::$unreachable = true;
        $stuck = $this->pinnedRow();

        $response = $this->putJson($this->url("/{$stuck->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('card_page', 'unreachable')
            ->assertJsonPath('warning', true);
        $this->assertStringContainsString('Burlington Masjid', $response->json('message'));
        $this->assertSame(FormResponse::STATUS_CANCELLED, $stuck->fresh()->status);

        self::$unreachable = false;
        $paid = $this->pinnedRow([
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_linked_paid',
        ]);

        $response = $this->putJson($this->url("/{$paid->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('card_page', 'paid_on_stripe');
        $this->assertStringContainsString('only Burlington Masjid can refund it (payment pi_linked_paid)', $response->json('message'));
    }

    #[Test]
    public function the_row_says_whose_account_charged_it_and_what_the_holder_did_never_the_account(): void
    {
        $row = $this->pinnedRow([
            'stripe_payment_intent_id' => 'pi_linked_flagged',
            'charge_flag' => FormResponse::CHARGE_FLAG_REFUNDED,
            'charge_flagged_at' => now(),
        ]);

        $response = $this->putJson($this->url("/{$row->id}"), ['admin_notes' => 'Refund asked of Burlington.'])
            ->assertOk()
            ->assertJsonPath('data.charged_through.id', $this->holder->id)
            ->assertJsonPath('data.charged_through.name', 'Burlington Masjid')
            ->assertJsonPath('data.stripe_payment_intent_id', 'pi_linked_flagged')
            ->assertJsonPath('data.charge_flag', 'refunded')
            ->assertJsonPath('data.page_unreachable', false);

        $this->assertNotNull($response->json('data.charge_flagged_at'));
        $this->assertStringNotContainsString('acct_', $response->getContent());
        $this->assertStringNotContainsString((string) $row->charge_ref, $response->getContent());

        // The holder offboarded: nothing can ask about the unpaid page any more.
        $this->holder->delete();
        $this->putJson($this->url("/{$row->id}"), ['admin_notes' => 'Burlington left.'])
            ->assertOk()
            ->assertJsonPath('data.charged_through.name', 'Burlington Masjid')
            ->assertJsonPath('data.page_unreachable', true);

        // A row of an organisation on its own account: charged through nobody.
        $own = $this->makeOrg('Own Account Masjid', ['stripe_account_id' => 'acct_1OwnDoor', 'stripe_charges_enabled' => true]);
        $ownForm = $this->makeForm($own);
        $ownRow = $this->pinnedRow([
            'charge_account_id' => null, 'charge_masjid_id' => null, 'charge_ref' => null, 'charge_expires_at' => null,
        ], $ownForm);

        $this->putJson("/api/admin/masjids/{$own->id}/forms/{$ownForm->id}/responses/{$ownRow->id}", ['admin_notes' => 'Fine.'])
            ->assertOk()
            ->assertJsonPath('data.charged_through', null)
            ->assertJsonPath('data.charge_flag', null)
            ->assertJsonPath('data.page_unreachable', false);
    }

    // ------------------------------------------------------------------- helpers

    private function assertStillUnpaid(FormResponse $row): void
    {
        $fresh = $row->fresh();

        $this->assertSame(FormResponse::METHOD_ONLINE, $fresh->payment_method);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $fresh->payment_status);
        $this->assertNull($fresh->paid_at);
        $this->assertNull($fresh->marked_paid_by_user_id);
    }

    private function assertHolderSwitchedOff(): void
    {
        $holder = $this->holder->fresh();

        $this->assertFalse((bool) $holder->stripe_charges_enabled);
        $this->assertFalse((bool) $holder->stripe_payouts_enabled);
        $this->assertNotNull($holder->stripe_deauthorized_at);
        $this->assertSame(self::HOLDER_ACCOUNT, $holder->stripe_account_id);
    }

    /** Refreshed first: the copy from setUp already says "on", so saving it unrefreshed would write nothing. */
    private function reconnectHolder(): void
    {
        $this->holder->refresh()->forceFill([
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
            'stripe_deauthorized_at' => null,
        ])->save();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->biss->id}/forms/{$this->form->id}/responses{$suffix}";
    }

    private function pinnedRow(array $money = [], ?Form $form = null): FormResponse
    {
        $form ??= $this->form;

        $row = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['fullName' => 'Amal Yusuf', 'email' => 'amal@example.com'],
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
            'idempotency_key' => 'form_response_' . Str::uuid(),
            'stripe_checkout_session_id' => 'cs_door_' . Str::random(10),
            'charge_account_id' => self::HOLDER_ACCOUNT,
            'charge_masjid_id' => $this->holder->id,
            'charge_ref' => 'fcr_' . bin2hex(random_bytes(16)),
            'charge_expires_at' => now()->addMinutes(20),
        ], $money))->save();

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
                'payment' => ['online' => true, 'staffCodes' => true],
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
                    throw new \LogicException('The admin screen never opens a payment page.');
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    FormLinkedHandSettlementTest::$asked[] = ['seam' => 'retrieve', 'account' => $connectedAccountId, 'session' => $sessionId];

                    if (FormLinkedHandSettlementTest::$failure === 'authentication') {
                        throw \Stripe\Exception\AuthenticationException::factory('Invalid API Key provided.', 401);
                    }

                    if (FormLinkedHandSettlementTest::$unreachable) {
                        throw \Stripe\Exception\PermissionException::factory('The provided key does not have access to account.', 403);
                    }

                    $status = FormLinkedHandSettlementTest::$pages[$sessionId] ?? 'expired';

                    return ['status' => $status, 'url' => $status === 'open' ? "https://checkout.stripe.test/pay/{$sessionId}" : null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    FormLinkedHandSettlementTest::$asked[] = ['seam' => 'expire', 'account' => $connectedAccountId, 'session' => $sessionId];

                    if (FormLinkedHandSettlementTest::$failure === 'account_invalid_on_expire') {
                        throw \Stripe\Exception\InvalidRequestException::factory('No such account.', 400, null, null, null, 'account_invalid');
                    }

                    if (FormLinkedHandSettlementTest::$unreachable) {
                        throw \Stripe\Exception\PermissionException::factory('The provided key does not have access to account.', 403);
                    }

                    FormLinkedHandSettlementTest::$pages[$sessionId] = 'expired';
                }
            };
        });
    }
}
