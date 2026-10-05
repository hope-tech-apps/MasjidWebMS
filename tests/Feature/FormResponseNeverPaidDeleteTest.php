<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormDateReservation;
use App\Models\FormResponse;
use App\Models\FormResponseAttachment;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\FormAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\Support\MakesRamadanGivingForms;
use Tests\TestCase;

/**
 * Deleting a registration from the Form Responses screen (DECISIONS.md 2026-10-05): an
 * organisation's office reported that people start a registration, never pay, and the
 * office could not remove what they left behind.
 *
 * The rule: a registration is deleted only when it never held money AND can no longer
 * take any. One test per kind of row:
 *
 *  - no money leg: deleted, as it always was;
 *  - imported from another system: refused, whatever its payment state;
 *  - a payment on record (card, cash or elsewhere; any status; a refund or dispute
 *    flagged), and every state nothing writes (an unpaid row carrying any column only a
 *    payment writes): refused in the words it always was;
 *  - never paid and NOT cancelled, card or office: "cancel it first", and Stripe is not
 *    asked;
 *  - never paid and cancelled, no card page on record (every office row): deleted without
 *    a Stripe client being built, and no figure of the cash totals moves;
 *  - never paid and cancelled, a card page on record: Stripe is asked under the lock, and
 *    only 'expired' deletes (an open page is closed first). Paid at Stripe, Stripe down
 *    or unreadable, a refused close, no account on record to ask, an account Stripe no
 *    longer lets the platform check: each keeps the row and says so.
 *
 * And what a delete does: one warning line by ids alone, the uploaded files and the
 * reserved date removed, the form's counter and its place given back, a 404 for a row
 * that went away while the request waited, and nothing left for the payer's page or a
 * late webhook to find.
 *
 * And what it never does: decide on the row as it read before the lock (a registration
 * restored or paid meanwhile is kept), or reach another organisation's registration, or
 * one of another form, whatever ids the URL carries.
 *
 * Stripe is the checkout service with its three seams stubbed by subclassing; nothing
 * here reaches Stripe. Every name, address and id is invented.
 */
class FormResponseNeverPaidDeleteTest extends TestCase
{
    use MakesRamadanGivingForms;
    use RefreshDatabase;

    private const NAME = 'Test Registrant';

    private const EMAIL = 'registrant@example.test';

    private const PHONE = '+15550100';

    private const ACCOUNT = 'acct_test_delete_org';

    private const HOLDER_ACCOUNT = 'acct_test_delete_holder';

    private const CONNECT_SECRET = 'whsec_test_delete_connect';

    private const PAID_SENTENCE = 'A registration with a payment is never deleted. Cancel it instead, and add a note.';

    private const CANCEL_FIRST = 'This registration has not been paid, but it still can be. Cancel it first; a cancelled registration that was never paid can then be deleted.';

    private const DELETED_LOG = 'A form registration is being deleted from the admin screen.';

    private const PAID_ON_STRIPE = 'This registration was paid by card, so it was not deleted. If it still shows as unpaid later, check this payment in Stripe. Refund it in Stripe if it should not stand.';

    private const PAID_ON_STRIPE_LOG = 'was not deleted: Stripe says its card payment page was paid';

    /** @var array<string,string> Stripe's view of each page: 'open' | 'complete' | 'expired' */
    public static array $pages = [];

    /** @var array<int,string> pages the service closed */
    public static array $expired = [];

    /** @var array<int,array{seam: string, account: string, session: string}> every question Stripe was asked */
    public static array $asked = [];

    /**
     * null, or how Stripe misbehaves: 'paid' (the payer won the race with the close),
     * 'still-open' (the close is refused and the page stays open), 'down', 'garbled',
     * 'missing' (the page is not on the account asked), 'authentication' (the platform's
     * own key is wrong), 'unreachable' (the account no longer lets the platform act).
     */
    public static ?string $stripe = null;

    private Masjid $org;

    private Form $form;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteAndStubStripe();
        Mail::fake();
        Storage::fake($this->disk());

        self::$pages = [];
        self::$expired = [];
        self::$asked = [];
        self::$stripe = null;
        $this->stubStripe();

        $this->org = $this->makeOrg(self::ACCOUNT);
        $this->form = $this->makeEventForm($this->org);

        $this->admin = $this->makeSuperAdmin();
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------- rows that were always deletable

    #[Test]
    public function a_registration_with_no_money_leg_is_deleted_as_it_always_was(): void
    {
        $this->neverBuildsAStripeClient();

        // A Wix-fallback row on a form that charges, and a row on a form that charges nothing.
        $fallback = $this->row(['amount_due_minor' => null, 'currency' => null]);
        $freeForm = $this->makeEventForm($this->org, ['fee' => null, 'payment' => null]);
        $free = $this->row(['amount_due_minor' => null, 'currency' => null], ['amount_due' => null], $freeForm);

        $this->assertFalse($fallback->hasMoneyLeg());
        $this->assertSame(1, $this->form->fresh()->response_count);

        // Whatever its status: it never needed a cancel first.
        $this->deleteJson($this->url("/{$fallback->id}"))->assertOk()->assertJsonPath('message', 'Response deleted successfully');
        $this->deleteJson($this->url("/{$free->id}", $freeForm))->assertOk();

        $this->assertDatabaseMissing('form_responses', ['id' => $fallback->id]);
        $this->assertDatabaseMissing('form_responses', ['id' => $free->id]);
        $this->assertSame(0, $this->form->fresh()->response_count);
    }

    #[Test]
    public function an_imported_registration_is_refused_whatever_its_payment_state(): void
    {
        $this->neverBuildsAStripeClient();

        $plain = $this->row(['amount_due_minor' => null, 'currency' => null, 'external_ref' => 'test-external-ref-0101']);
        $neverPaid = $this->officeRow(['external_ref' => 'test-external-ref-0102'], ['status' => 'cancelled']);
        $cardNeverPaid = $this->cardRow(['external_ref' => 'test-external-ref-0103', 'stripe_checkout_session_id' => null], ['status' => 'cancelled']);

        foreach ([$plain, $neverPaid, $cardNeverPaid] as $row) {
            $this->assertTrue($row->isExternal());

            $this->deleteJson($this->url("/{$row->id}"))
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonPath('message', 'Imported from the school website. Cancel it instead.');

            $this->assertDatabaseHas('form_responses', ['id' => $row->id]);
        }

        // One a payment was recorded on reads as every paid registration does.
        $paid = $this->paidCardRow(['external_ref' => 'test-external-ref-0104']);
        $this->deleteJson($this->url("/{$paid->id}"))->assertStatus(422)->assertJsonPath('message', self::PAID_SENTENCE);

        $this->assertSame(4, $this->form->fresh()->response_count);
    }

    // ------------------------------------------------------ a payment on record: never

    #[Test]
    public function a_registration_with_a_payment_on_record_is_never_deleted_whatever_its_status(): void
    {
        $this->neverBuildsAStripeClient();

        $cash = $this->row();
        $this->assertTrue($cash->settleCashBy($this->admin));

        $external = $this->row();
        $this->assertTrue($external->markExternalPaid($this->admin, FormResponse::PAID_VIA_CHECK));

        $office = $this->officeRow();
        $this->assertTrue($office->markExternalPaid($this->admin, FormResponse::PAID_VIA_ZELLE));

        $rows = [
            'paid by card' => $this->paidCardRow(),
            'paid by card, then cancelled' => $this->paidCardRow([], ['status' => 'cancelled']),
            'paid in cash' => $cash,
            'paid elsewhere' => $external,
            'paid at the office' => $office,
            'paid by card and refunded by the holder' => $this->paidCardRow(['charge_flag' => FormResponse::CHARGE_FLAG_REFUNDED, 'charge_flagged_at' => now()], ['status' => 'cancelled']),
            'paid by card and disputed' => $this->paidCardRow(['charge_flag' => FormResponse::CHARGE_FLAG_DISPUTED, 'charge_flagged_at' => now()], ['status' => 'cancelled']),
        ];

        // As each stands, and again once every one of them is cancelled: cancelling a paid
        // registration never makes it deletable.
        foreach (['as it stands', 'cancelled'] as $pass) {
            foreach ($rows as $kind => $row) {
                if ($pass === 'cancelled') {
                    $row->forceFill(['status' => 'cancelled'])->save();
                }

                $this->deleteJson($this->url("/{$row->id}"))
                    ->assertStatus(422)
                    ->assertJsonPath('message', self::PAID_SENTENCE);

                $this->assertNotNull(FormResponse::find($row->id), "{$kind}, {$pass}: still there");
            }
        }

        $this->assertSame(count($rows), $this->form->fresh()->response_count);
        $this->assertSame([], self::$asked);
    }

    #[Test]
    public function a_state_nothing_writes_is_refused_as_a_payment_rather_than_deleted(): void
    {
        $this->neverBuildsAStripeClient();

        [$code] = FormStaffCode::issue($this->form, 'Test Holder', now()->addDay());

        // Each is cancelled and reads "unpaid" somewhere, and each carries one thing an
        // unpaid registration never has. Written by hand: the application writes none of them.
        $rows = [
            'an unpaid card row carrying a payment intent' => $this->cardRow(['stripe_payment_intent_id' => 'pi_test_stray_0001']),
            'an unpaid card row with a time it was paid at' => $this->cardRow(['paid_at' => now()]),
            'an unpaid card row its holder flagged refunded' => $this->cardRow(['charge_flag' => FormResponse::CHARGE_FLAG_REFUNDED]),
            'an unpaid office row carrying a payment intent' => $this->officeRow(['stripe_payment_intent_id' => 'pi_test_stray_0002']),
            'cash never marked paid' => $this->row(['payment_method' => FormResponse::METHOD_CASH, 'payment_status' => FormResponse::PAYMENT_UNPAID]),
            'a payment elsewhere never marked paid' => $this->row(['payment_method' => FormResponse::METHOD_EXTERNAL, 'payment_status' => FormResponse::PAYMENT_UNPAID]),
            'a card row with no payment status' => $this->row(['payment_method' => FormResponse::METHOD_ONLINE, 'payment_status' => null]),
            'a method this version does not know' => $this->row(['payment_method' => 'voucher', 'payment_status' => FormResponse::PAYMENT_UNPAID]),
            // The other columns only a payment writes, each alone on a row that reads unpaid.
            'an unpaid card row saying how its payment came' => $this->cardRow(['paid_via' => FormResponse::PAID_VIA_CASH]),
            'an unpaid card row an admin is named on as having marked paid' => $this->cardRow(['marked_paid_by_user_id' => $this->admin->id]),
            'an unpaid office row a staff code is named on as having taken cash for' => $this->officeRow(['staff_code_id' => $code->id]),
            'an unpaid office row that was checked in' => $this->officeRow(['collected_at' => now(), 'collected_by_user_id' => $this->admin->id]),
            'an unpaid card row with a time its charge was flagged at' => $this->cardRow(['charge_flagged_at' => now()]),
            'an unpaid card row with an amount refunded' => $this->cardRow(['charge_refunded_minor' => 500]),
        ];

        foreach ($rows as $kind => $row) {
            $row->forceFill(['status' => 'cancelled'])->save();
            $this->assertFalse($row->fresh()->neverRecordedAPayment(), $kind);

            $this->deleteJson($this->url("/{$row->id}"))
                ->assertStatus(422)
                ->assertJsonPath('message', self::PAID_SENTENCE);

            $this->assertNotNull(FormResponse::find($row->id), "{$kind}: still there");
        }

        $this->assertSame(count($rows), $this->form->fresh()->response_count);
        $this->assertSame([], self::$asked);

        // A refund of nothing is no refund: zero reads as the column left empty.
        $this->assertTrue($this->officeRow(['charge_refunded_minor' => 0])->neverRecordedAPayment());
    }

    #[Test]
    public function an_unpaid_office_registration_carrying_a_card_page_is_asked_about_at_stripe_and_kept_when_that_page_was_paid(): void
    {
        // Another state nothing writes (a family paying the office is never sent to a card
        // page), and one the allowlist lets through: Stripe is asked about ANY page on the
        // row, whatever its payment method, and here the page was paid.
        $row = $this->officeRow(['stripe_checkout_session_id' => 'cs_test_delete_office_page'], ['status' => 'cancelled']);
        self::$pages['cs_test_delete_office_page'] = 'complete';

        $this->assertTrue($row->neverRecordedAPayment());

        $this->deleteJson($this->url("/{$row->id}"))
            ->assertStatus(422)
            ->assertJsonPath('message', self::PAID_ON_STRIPE);

        $this->assertDatabaseHas('form_responses', ['id' => $row->id, 'status' => 'cancelled']);
        $this->assertSame([['seam' => 'retrieve', 'account' => self::ACCOUNT, 'session' => 'cs_test_delete_office_page']], self::$asked);
        $this->assertSame(1, $this->form->fresh()->response_count);
    }

    // ------------------------------------------------ never paid, not cancelled: cancel first

    #[Test]
    public function an_unpaid_card_registration_that_is_not_cancelled_is_told_to_cancel_it_first_and_stripe_is_not_asked(): void
    {
        $this->neverBuildsAStripeClient();
        Log::spy();

        foreach (['new', 'confirmed', 'waitlisted'] as $status) {
            $row = $this->cardRow([], ['status' => $status]);
            self::$pages[$row->stripe_checkout_session_id] = 'open';

            $this->deleteJson($this->url("/{$row->id}"))
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonPath('message', self::CANCEL_FIRST);

            $this->assertDatabaseHas('form_responses', ['id' => $row->id, 'status' => $status]);
            $this->assertSame('open', self::$pages[$row->stripe_checkout_session_id], 'its page is left as it was: the registrant can still pay');
        }

        $this->assertSame(3, $this->form->fresh()->response_count);
        $this->assertSame([], self::$asked);
        $this->assertSame([], self::$expired);
        Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, self::DELETED_LOG));
    }

    #[Test]
    public function an_office_registration_is_cancelled_first_and_deleting_it_then_moves_no_figure_of_the_cash_totals(): void
    {
        $this->neverBuildsAStripeClient();

        // Two families paying the office: one is the abandoned one, one still owes.
        $abandoned = $this->officeRow();
        $owing = $this->officeRow();
        $paidCard = $this->paidCardRow();

        $before = $this->cashTotals();
        $this->assertSame(['submissions' => 2, 'people' => 4, 'owed_minor' => 6000], $before['owed_office']);

        // Not cancelled: it still counts in "owed to the office", and is not deleted.
        $this->deleteJson($this->url("/{$abandoned->id}"))
            ->assertStatus(422)
            ->assertJsonPath('message', self::CANCEL_FIRST);
        $this->assertDatabaseHas('form_responses', ['id' => $abandoned->id]);
        $this->assertSame($before, $this->cashTotals());

        // The cancel is what takes it out of the figure, stamped with who and when.
        $this->putJson($this->url("/{$abandoned->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status_changed_by.id', $this->admin->id);

        $cancelled = $this->cashTotals();
        $this->assertSame(['submissions' => 1, 'people' => 2, 'owed_minor' => 3000], $cancelled['owed_office']);

        // The delete moves nothing: every figure reads as it did after the cancel.
        $this->deleteJson($this->url("/{$abandoned->id}"))->assertOk();

        $this->assertDatabaseMissing('form_responses', ['id' => $abandoned->id]);
        $this->assertSame($cancelled, $this->cashTotals());
        $this->assertSame(3120, $this->cashTotals()['other_paid']['online']['total_minor']);
        $this->assertDatabaseHas('form_responses', ['id' => $owing->id]);
        $this->assertDatabaseHas('form_responses', ['id' => $paidCard->id]);
        $this->assertSame(2, $this->form->fresh()->response_count);
        $this->assertSame([], self::$asked, 'an office registration has no card page to ask about');
    }

    // --------------------------------------------- never paid and cancelled: the card page

    #[Test]
    public function a_cancelled_card_registration_with_no_page_on_record_is_deleted_without_a_stripe_client_being_built(): void
    {
        $this->neverBuildsAStripeClient();

        $row = $this->cardRow(['stripe_checkout_session_id' => null], ['status' => 'cancelled']);
        $kept = $this->cardRow();

        $this->deleteJson($this->url("/{$row->id}"))->assertOk();

        $this->assertDatabaseMissing('form_responses', ['id' => $row->id]);
        $this->assertDatabaseHas('form_responses', ['id' => $kept->id]);
        $this->assertSame(1, $this->form->fresh()->response_count);
    }

    #[Test]
    public function a_cancelled_card_registration_is_deleted_once_stripe_says_its_page_has_expired(): void
    {
        // Abandoned long ago: Stripe already reports the page expired, and it is only read.
        $stale = $this->cardRow([], ['status' => 'cancelled']);
        self::$pages[$stale->stripe_checkout_session_id] = 'expired';

        $this->deleteJson($this->url("/{$stale->id}"))->assertOk();

        $this->assertDatabaseMissing('form_responses', ['id' => $stale->id]);
        $this->assertSame([['seam' => 'retrieve', 'account' => self::ACCOUNT, 'session' => $stale->stripe_checkout_session_id]], self::$asked);
        $this->assertSame([], self::$expired);

        // Still open (the cancel's close had failed): this request closes it, then deletes.
        self::$asked = [];
        $open = $this->cardRow([], ['status' => 'cancelled']);
        self::$pages[$open->stripe_checkout_session_id] = 'open';

        $this->deleteJson($this->url("/{$open->id}"))->assertOk();

        $this->assertDatabaseMissing('form_responses', ['id' => $open->id]);
        $this->assertSame(['retrieve', 'expire'], array_column(self::$asked, 'seam'), 'the page is closed before the row goes');
        $this->assertSame([$open->stripe_checkout_session_id], self::$expired);
        $this->assertSame(0, $this->form->fresh()->response_count);
    }

    #[Test]
    public function a_cancelled_card_registration_stripe_says_was_paid_is_kept_and_its_payment_still_has_a_row_to_land_on(): void
    {
        config(['services.stripe.connect_webhook_secret' => self::CONNECT_SECRET, 'services.stripe.webhook_secret' => 'whsec_test_delete_platform']);
        Log::spy();

        // The sentence promises nothing about the row: a webhook that refused this
        // payment's event is not sent it again, so it says where to look instead.
        $this->assertStringNotContainsString('will show as paid', self::PAID_ON_STRIPE);

        // The page is complete at Stripe while the row still reads unpaid: the webhook is
        // late, or was refused. Unpaid on the row is not proof that no money moved.
        $paid = $this->cardRow([], ['status' => 'cancelled']);
        $file = $this->withFile($paid);
        self::$pages[$paid->stripe_checkout_session_id] = 'complete';

        // The payer finishes the page a moment before this request's close lands.
        $raced = $this->cardRow([], ['status' => 'cancelled']);
        self::$pages[$raced->stripe_checkout_session_id] = 'open';

        $this->deleteJson($this->url("/{$paid->id}"))->assertStatus(422)->assertJsonPath('message', self::PAID_ON_STRIPE);

        self::$stripe = 'paid';
        $this->deleteJson($this->url("/{$raced->id}"))->assertStatus(422)->assertJsonPath('message', self::PAID_ON_STRIPE);
        self::$stripe = null;

        foreach ([$paid, $raced] as $row) {
            $kept = $row->fresh();
            $this->assertNotNull($kept, 'the registration is still there');
            $this->assertSame(FormResponse::PAYMENT_UNPAID, $kept->payment_status, 'only the webhook marks it paid');
            $this->assertTrue($kept->isCancelled());

            // Money at Stripe that the row does not record: each time the delete finds it,
            // it leaves one line for whoever reconciles it, by ids alone.
            Log::shouldHaveReceived('warning')
                ->withArgs(function (string $message, array $context = []) use ($row): bool {
                    if (! str_contains($message, self::PAID_ON_STRIPE_LOG) || ($context['form_response_id'] ?? null) !== $row->id) {
                        return false;
                    }

                    $this->assertSame([
                        'masjid_id' => $this->org->id,
                        'form_id' => $this->form->id,
                        'form_response_id' => $row->id,
                        'form_response_uuid' => $row->uuid,
                        'checkout_session_id' => $row->stripe_checkout_session_id,
                        'charge_masjid_id' => null,
                    ], $context);

                    foreach ([self::NAME, self::EMAIL, self::PHONE, 'Guest One', 'test-waiver.pdf'] as $personal) {
                        $this->assertStringNotContainsString($personal, $message . json_encode($context));
                    }

                    return true;
                })
                ->once();
        }

        $this->assertSame(2, $this->form->fresh()->response_count);
        $this->assertSame([], self::$expired);
        $this->assertNotNull(FormResponseAttachment::find($file->id));
        Storage::disk($this->disk())->assertExists($file->path);
        Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, self::DELETED_LOG));

        // The signed webhook arrives afterwards and finds the row it names.
        $this->postWebhook($this->completed($paid, 'pi_test_delete_late'))->assertOk();

        $landed = $paid->fresh();
        $this->assertSame(FormResponse::PAYMENT_PAID, $landed->payment_status);
        $this->assertSame('pi_test_delete_late', $landed->stripe_payment_intent_id);
        $this->assertNotNull($landed->paid_at);

        // And now it is a registration with a payment: never deleted.
        $this->deleteJson($this->url("/{$paid->id}"))->assertStatus(422)->assertJsonPath('message', self::PAID_SENTENCE);
    }

    #[Test]
    public function when_stripe_is_down_or_cannot_be_read_the_registration_and_its_files_are_kept(): void
    {
        Log::spy();

        $row = $this->cardRow([], ['status' => 'cancelled']);
        $file = $this->withFile($row);
        self::$pages[$row->stripe_checkout_session_id] = 'open';

        $unconfirmed = 'Stripe did not confirm that this registration\'s card payment page is closed, so nothing was deleted. Try again in a moment.';

        // No connection, a reply the SDK could not read, a page that is not on the account
        // asked, the platform's own key refused: none of them is an answer about the page.
        foreach (['down', 'garbled', 'missing', 'authentication'] as $failure) {
            self::$stripe = $failure;

            $this->deleteJson($this->url("/{$row->id}"))
                ->assertStatus(503)
                ->assertJsonPath('status', 'failed')
                ->assertJsonPath('message', $unconfirmed);

            $this->assertStillThereWithItsFile($row, $file, $failure);
        }

        // A status Stripe does not give a page is not an answer either.
        self::$stripe = null;
        self::$pages[$row->stripe_checkout_session_id] = 'paused';
        $this->deleteJson($this->url("/{$row->id}"))->assertStatus(503)->assertJsonPath('message', $unconfirmed);
        $this->assertStillThereWithItsFile($row, $file, 'an unknown status');

        // The close is refused and Stripe still says the page is open.
        self::$pages[$row->stripe_checkout_session_id] = 'open';
        self::$stripe = 'still-open';
        $this->deleteJson($this->url("/{$row->id}"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration\'s card payment page could not be closed, so it can still take a payment. It was not deleted. Try again in a moment.');
        $this->assertStillThereWithItsFile($row, $file, 'still-open');

        $this->assertSame([], self::$expired);
        $this->assertSame(1, $this->form->fresh()->response_count);
        Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, self::DELETED_LOG));

        // Each refusal left its own line, by ids and never Stripe's message.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'was not deleted: Stripe did not confirm')
                && ($context['form_response_id'] ?? null) === $row->id
                && ! str_contains((string) json_encode($context), 'Could not connect'))
            ->times(5);

        // Stripe answers again: the page is closed, and only now do the row and its file go.
        self::$stripe = null;
        $this->deleteJson($this->url("/{$row->id}"))->assertOk();

        $this->assertNull(FormResponse::find($row->id));
        $this->assertNull(FormResponseAttachment::find($file->id));
        Storage::disk($this->disk())->assertMissing($file->path);
        $this->assertSame([$row->stripe_checkout_session_id], self::$expired);
    }

    #[Test]
    public function a_card_page_with_no_stripe_account_on_record_to_ask_about_it_keeps_the_registration(): void
    {
        $row = $this->cardRow([], ['status' => 'cancelled']);
        self::$pages[$row->stripe_checkout_session_id] = 'open';

        // The organisation has since disconnected: nobody can be asked about its page.
        $this->org->forceFill(['stripe_account_id' => null])->save();

        $this->deleteJson($this->url("/{$row->id}"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration has a card payment page, and there is no Stripe account on record to check it on, so we cannot tell whether it was paid. It was not deleted and stays cancelled.');

        $this->assertDatabaseHas('form_responses', ['id' => $row->id, 'status' => 'cancelled']);
        $this->assertSame([], self::$asked, 'Stripe was not asked');
        $this->assertSame(1, $this->form->fresh()->response_count);
    }

    #[Test]
    public function a_page_on_an_account_stripe_no_longer_lets_us_check_keeps_the_registration_and_switches_that_holder_off(): void
    {
        // A program whose card pages are opened on another organisation's account.
        $holder = $this->makeOrg(self::HOLDER_ACCOUNT);
        $holder->forceFill(['name' => 'Holder Organisation', 'stripe_payouts_enabled' => true])->save();
        $program = $this->makeOrg('acct_test_delete_program');
        $program->forceFill(['stripe_account_id' => null, 'stripe_charges_enabled' => false])->save();
        $form = $this->makeEventForm($program);

        $row = $this->cardRow([
            'charge_account_id' => self::HOLDER_ACCOUNT,
            'charge_masjid_id' => $holder->id,
            'charge_ref' => 'fcr_' . bin2hex(random_bytes(16)),
            'charge_expires_at' => now()->subDay(),
        ], ['status' => 'cancelled'], $form);

        self::$stripe = 'unreachable';

        $response = $this->deleteJson($this->url("/{$row->id}", $form))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stripe no longer lets us check this registration\'s card payment page on Holder Organisation\'s Stripe account, so we cannot tell whether it was paid. It was not deleted and stays cancelled.');

        $this->assertStringNotContainsString('acct_', $response->getContent());
        $this->assertDatabaseHas('form_responses', ['id' => $row->id, 'status' => 'cancelled']);
        $this->assertSame([self::HOLDER_ACCOUNT], array_values(array_unique(array_column(self::$asked, 'account'))), 'asked on the pin');

        // The refusal was RETURNED from the delete's transaction, not thrown out of it: the
        // fail-closed switch closeOpenSession() made inside that transaction committed.
        $holder->refresh();
        $this->assertFalse((bool) $holder->stripe_charges_enabled);
        $this->assertFalse((bool) $holder->stripe_payouts_enabled);
        $this->assertNotNull($holder->stripe_deauthorized_at);

        // A wrong PLATFORM key is not "unreachable": nothing is deleted, and nobody is blamed.
        $holder->forceFill(['stripe_charges_enabled' => true, 'stripe_payouts_enabled' => true, 'stripe_deauthorized_at' => null])->save();
        self::$stripe = 'authentication';

        $this->deleteJson($this->url("/{$row->id}", $form))->assertStatus(503);

        $this->assertDatabaseHas('form_responses', ['id' => $row->id]);
        $this->assertTrue((bool) $holder->fresh()->stripe_charges_enabled);

        // Reachable again and the page long expired: the pinned row is deleted like any other.
        self::$stripe = null;
        $this->deleteJson($this->url("/{$row->id}", $form))->assertOk();
        $this->assertDatabaseMissing('form_responses', ['id' => $row->id]);
    }

    // ------------------------------------------------------------- what a delete does

    #[Test]
    public function a_delete_leaves_one_warning_line_by_ids_alone(): void
    {
        Log::spy();

        // Cancelled by a colleague, through the screen's own PUT, which stamps who and when.
        // That stamp goes with the row, so the line is where it survives.
        Carbon::setTestNow(Carbon::parse('2027-03-01 15:00:00', 'UTC'));
        $colleague = $this->makeSuperAdmin();
        $row = $this->cardRow(['charge_ref' => 'fcr_test_delete_0001']);
        $session = $row->stripe_checkout_session_id;

        Sanctum::actingAs($colleague);
        $this->putJson($this->url("/{$row->id}"), ['status' => 'cancelled'])->assertOk();

        Carbon::setTestNow(Carbon::parse('2027-03-02 09:30:00', 'UTC'));
        Sanctum::actingAs($this->admin);
        $this->deleteJson($this->url("/{$row->id}"))->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($row, $session, $colleague): bool {
                if (! str_contains($message, self::DELETED_LOG)) {
                    return false;
                }

                // Written before the delete, so it says what is happening, never that it
                // happened: a delete that fails after it leaves the registration in place.
                $this->assertStringNotContainsString('was deleted', $message);

                $this->assertSame([
                    'masjid_id' => $this->org->id,
                    'form_id' => $this->form->id,
                    'form_response_id' => $row->id,
                    'form_response_uuid' => $row->uuid,
                    'payment_method' => FormResponse::METHOD_ONLINE,
                    'checkout_session_id' => $session,
                    'charge_masjid_id' => null,
                    'charge_ref' => 'fcr_test_delete_0001',
                    'amount_due_minor' => 3000,
                    'total_minor' => 3120,
                    'status_changed_by_user_id' => $colleague->id,
                    'status_changed_at' => '2027-03-01T15:00:00+00:00',
                    'user_id' => $this->admin->id,
                ], $context);

                // Ids, amounts and a time: never who registered or what they answered.
                foreach ([self::NAME, self::EMAIL, self::PHONE, 'Guest One', 'test-waiver.pdf'] as $personal) {
                    $this->assertStringNotContainsString($personal, $message . json_encode($context));
                }

                return true;
            })
            ->once();

        // A refused delete writes no such line.
        $refused = $this->cardRow();
        $this->deleteJson($this->url("/{$refused->id}"))->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, self::DELETED_LOG))
            ->once();

        // A registration with no money leg leaves the same line, with nothing to say of a payment.
        $plain = $this->row(['amount_due_minor' => null, 'currency' => null]);
        $this->deleteJson($this->url("/{$plain->id}"))->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, self::DELETED_LOG)
                && ($context['form_response_id'] ?? null) === $plain->id
                && $context['payment_method'] === null
                && $context['checkout_session_id'] === null
                && $context['user_id'] === $this->admin->id)
            ->once();
    }

    #[Test]
    public function a_delete_removes_the_uploaded_files_and_gives_the_place_back(): void
    {
        $this->form->forceFill(['capacity' => 2])->save();

        $abandoned = $this->cardRow([], ['status' => 'cancelled']);
        $file = $this->withFile($abandoned);
        $this->paidCardRow();

        $this->assertSame(2, $this->form->fresh()->response_count);
        $this->assertTrue($this->form->fresh()->isAtCapacity(), 'a cancelled registration still holds its place');
        Storage::disk($this->disk())->assertExists($file->path);

        $this->deleteJson($this->url("/{$abandoned->id}"))->assertOk();

        $this->assertNull(FormResponse::find($abandoned->id));
        $this->assertNull(FormResponseAttachment::find($file->id));
        Storage::disk($this->disk())->assertMissing($file->path);
        $this->assertSame(1, $this->form->fresh()->response_count);
        $this->assertFalse($this->form->fresh()->isAtCapacity());
    }

    #[Test]
    public function on_a_form_that_reserves_dates_the_form_is_locked_first_and_the_reservation_goes_with_the_registration(): void
    {
        // Noon on the organisation's clock, ten days before the first listed evening.
        Carbon::setTestNow(Carbon::parse('2027-01-31 17:00:00', 'UTC'));

        $form = $this->makeIftarForm($this->org, ['2027-02-10', '2027-02-11'], office: true);
        $board = "/api/admin/masjids/{$this->org->id}/forms/{$form->id}/responses/reservations";
        $state = fn (): string => collect($this->getJson($board)->assertOk()->json('data.dates'))->firstWhere('date', '2027-02-10')['state'];

        // One sponsor chooses the office, one the card; both reserve an evening, neither pays.
        $this->submitTo($form, ['sponsorship' => 'half', 'iftar_date' => '2027-02-10'], ['pay_with' => 'office'])->assertOk();
        $office = FormResponse::where('form_id', $form->id)->sole();
        $this->submitTo($form, ['sponsorship' => 'full', 'iftar_date' => '2027-02-11'])->assertOk();
        $card = FormResponse::where('form_id', $form->id)->whereKeyNot($office->id)->sole();
        $this->assertNotNull($card->stripe_checkout_session_id);

        foreach ([$office, $card] as $row) {
            $this->deleteJson($this->url("/{$row->id}", $form))->assertStatus(422)->assertJsonPath('message', self::CANCEL_FIRST);
            $this->putJson($this->url("/{$row->id}", $form), ['status' => 'cancelled'])->assertOk();
        }

        $this->assertSame('cancelled', $state());
        $this->assertSame(2, FormDateReservation::withoutMasjidScope()->where('form_id', $form->id)->count());

        // The delete's locking reads, in the order they are issued inside its transaction.
        $locks = $this->lockingReadsOf(fn () => $this->deleteJson($this->url("/{$office->id}", $form))->assertOk());
        $this->assertSame(['forms', 'form_responses'], $locks, 'the form row first, as update() and the public submit lock it');

        $this->deleteJson($this->url("/{$card->id}", $form))->assertOk();

        $this->assertSame(0, FormResponse::where('form_id', $form->id)->count());
        $this->assertSame(0, FormDateReservation::withoutMasjidScope()->where('form_id', $form->id)->count(), 'each reservation went with its registration');
        $this->assertSame('open', $state());
        $this->assertSame(0, $form->fresh()->response_count);

        // The evening is anyone's again.
        $this->submitTo($form, ['sponsorship' => 'full', 'iftar_date' => '2027-02-10'], ['pay_with' => 'office'])->assertOk();
        $this->assertSame('held', $state());

        // A form that reserves nothing takes no form lock: its delete is as it was.
        $plain = $this->cardRow(['stripe_checkout_session_id' => null], ['status' => 'cancelled']);
        $this->assertSame(['form_responses'], $this->lockingReadsOf(fn () => $this->deleteJson($this->url("/{$plain->id}"))->assertOk()));
    }

    #[Test]
    public function a_registration_that_went_away_while_the_request_waited_for_its_lock_answers_404_not_500(): void
    {
        $row = $this->cardRow(['stripe_checkout_session_id' => null], ['status' => 'cancelled']);

        // A colleague's delete commits between this request finding the row and locking it.
        FormResponse::retrieved(function (FormResponse $found) use ($row) {
            static $done = false;

            if (! $done && $found->id === $row->id) {
                $done = true;
                DB::table('form_responses')->where('id', $row->id)->delete();
            }
        });

        $this->deleteJson($this->url("/{$row->id}"))
            ->assertStatus(404)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'This registration has already been deleted.');

        // And a second press on a registration already deleted is a 404 too.
        $this->deleteJson($this->url("/{$row->id}"))->assertStatus(404);
    }

    // ------------------------------------------- decided on the locked row, never the first read

    #[Test]
    public function a_registration_restored_while_the_delete_waited_for_its_lock_is_told_to_cancel_it_first_and_stripe_is_not_asked(): void
    {
        // Cancelled when the request found it, its card page still open at Stripe.
        $row = $this->cardRow([], ['status' => 'cancelled']);
        $session = $row->stripe_checkout_session_id;
        self::$pages[$session] = 'open';

        // A colleague restores it before the delete takes the row's lock: it can be paid again.
        $this->changesAfterTheFirstRead($row, ['status' => 'new']);

        $this->deleteJson($this->url("/{$row->id}"))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', self::CANCEL_FIRST);

        $this->assertDatabaseHas('form_responses', ['id' => $row->id, 'status' => 'new']);
        $this->assertSame([], self::$asked, 'Stripe is not asked about a registration that can still be paid');
        $this->assertSame('open', self::$pages[$session], 'and its page is left open for the registrant');
        $this->assertSame(1, $this->form->fresh()->response_count);
    }

    #[Test]
    public function a_registration_paid_while_the_delete_waited_for_its_lock_is_kept_with_its_payment(): void
    {
        $this->neverBuildsAStripeClient();

        // A family that chose the office: cancelled and unpaid when the request found it, so
        // the row as first read would be deleted without a question to anyone.
        $row = $this->officeRow([], ['status' => 'cancelled']);

        // A colleague restores it and records the family's payment before the delete takes
        // the row's lock.
        $this->changesAfterTheFirstRead($row, [
            'status' => 'confirmed',
            'payment_method' => FormResponse::METHOD_EXTERNAL,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_via' => FormResponse::PAID_VIA_ZELLE,
            'paid_at' => now(),
            'marked_paid_by_user_id' => $this->admin->id,
        ]);

        $this->deleteJson($this->url("/{$row->id}"))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', self::PAID_SENTENCE);

        $kept = FormResponse::find($row->id);
        $this->assertNotNull($kept, 'the registration and the payment recorded on it are still there');
        $this->assertTrue($kept->isPaid());
        $this->assertSame(FormResponse::METHOD_EXTERNAL, $kept->payment_method);
        $this->assertSame(1, $this->form->fresh()->response_count);
    }

    // ------------------------------------------------------------------ another organisation's

    #[Test]
    public function a_delete_never_reaches_another_organisations_registration_or_one_of_another_form(): void
    {
        // This organisation's own admin, not the platform's.
        Sanctum::actingAs($this->makeAdminFor($this->org));

        // Another organisation's registration, of the one kind a delete removes: cancelled,
        // never paid, its card page expired, with a file.
        $other = $this->makeOrg('acct_test_delete_other');
        $theirForm = $this->makeEventForm($other);
        $theirs = $this->cardRow([], ['status' => 'cancelled'], $theirForm);
        $theirFile = $this->withFile($theirs);

        // And one of this organisation's own, on its first form; it has a second form too.
        $ours = $this->cardRow([], ['status' => 'cancelled']);
        $ourFile = $this->withFile($ours);
        $secondForm = $this->makeEventForm($this->org);

        $admin = fn (Masjid $org, Form $form, FormResponse $row): string => "/api/admin/masjids/{$org->id}/forms/{$form->id}/responses/{$row->id}";

        // Theirs under this organisation's form; under this organisation's id with their
        // form; under their own URL; and this organisation's own under another of its forms.
        $this->deleteJson($admin($this->org, $this->form, $theirs))->assertStatus(404);
        $this->deleteJson($admin($this->org, $theirForm, $theirs))->assertStatus(404);
        $this->deleteJson($admin($other, $theirForm, $theirs))->assertStatus(403);
        $this->deleteJson($admin($this->org, $secondForm, $ours))->assertStatus(404);

        foreach ([[$theirs, $theirFile], [$ours, $ourFile]] as [$row, $file]) {
            $this->assertDatabaseHas('form_responses', ['id' => $row->id, 'status' => 'cancelled']);
            $this->assertNotNull(FormResponseAttachment::find($file->id));
            Storage::disk($this->disk())->assertExists($file->path);
        }

        $this->assertSame(1, $theirForm->fresh()->response_count);
        $this->assertSame(1, $this->form->fresh()->response_count);
        $this->assertSame(0, $secondForm->fresh()->response_count);
        $this->assertSame([], self::$asked, 'Stripe was never asked, on either organisation\'s account');

        // Its own registration, under its own form, is the one that goes, asked about on its
        // own account.
        $this->deleteJson($admin($this->org, $this->form, $ours))->assertOk();

        $this->assertDatabaseMissing('form_responses', ['id' => $ours->id]);
        $this->assertDatabaseHas('form_responses', ['id' => $theirs->id]);
        $this->assertSame([self::ACCOUNT], array_column(self::$asked, 'account'));
        $this->assertSame(1, $theirForm->fresh()->response_count);
    }

    #[Test]
    public function after_a_delete_the_payers_page_and_a_late_webhook_find_nothing(): void
    {
        config(['services.stripe.connect_webhook_secret' => self::CONNECT_SECRET, 'services.stripe.webhook_secret' => 'whsec_test_delete_platform']);

        $row = $this->cardRow([], ['status' => 'cancelled']);
        $headers = ['masjid-id' => (string) $this->org->id, 'Origin' => self::ORIGIN];

        $this->getJson("/api/v1/form-responses/{$row->uuid}", $headers)->assertOk();

        $this->deleteJson($this->url("/{$row->id}"))->assertOk();

        $this->getJson("/api/v1/form-responses/{$row->uuid}", $headers)->assertStatus(404);
        $this->postJson("/api/v1/form-responses/{$row->uuid}/checkout", ['return_path' => self::PATH], $headers)->assertStatus(404);

        // A payment that arrives for it anyway is answered 200, records nothing, and is
        // logged with the uuid the delete's own line carries.
        Log::spy();
        $this->postWebhook($this->completed($row, 'pi_test_delete_orphan'))->assertOk();

        $this->assertSame(0, FormResponse::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'NOTHING was recorded')
                && ($context['form_response_uuid'] ?? null) === $row->uuid)
            ->once();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A colleague's change that commits between the request finding the row and locking it:
     * written straight to the table the first time the row is read, which in a DELETE is
     * resolveResponse()'s read, before the transaction opens. What the delete then decides
     * on is what it reads under the lock, or these tests fail.
     *
     * @param  array<string,mixed>  $columns
     */
    private function changesAfterTheFirstRead(FormResponse $row, array $columns): void
    {
        $done = false;

        FormResponse::retrieved(function (FormResponse $found) use ($row, $columns, &$done) {
            if (! $done && $found->id === $row->id) {
                $done = true;
                DB::table('form_responses')->where('id', $row->id)->update($columns);
            }
        });
    }

    private function assertStillThereWithItsFile(FormResponse $row, FormResponseAttachment $file, string $when): void
    {
        $this->assertNotNull(FormResponse::find($row->id), "{$when}: the registration is still there");
        $this->assertNotNull(FormResponseAttachment::find($file->id), "{$when}: and its attachment row");
        Storage::disk($this->disk())->assertExists($file->path);
    }

    /**
     * The tables read by primary key inside the transaction $delete opens, in order. On
     * SQLite a locking read prints no lock clause, so what is pinned here is that the
     * statements are issued and in which order; tests/Mysql/FormResponseDeleteLockMysqlTest
     * pins that they are FOR UPDATE on the engine production runs.
     *
     * @return array<int,string>
     */
    private function lockingReadsOf(callable $delete): array
    {
        $seen = [];
        $listening = true;

        DB::listen(function ($query) use (&$seen, &$listening): void {
            if ($listening && preg_match('/^select \* from "(forms|form_responses)" where "\1"\."id" = \?/', $query->sql, $table) === 1) {
                $seen[] = $table[1];
            }
        });

        $delete();
        $listening = false;

        return $seen;
    }

    /** @return array<string,mixed> */
    private function cashTotals(): array
    {
        return $this->getJson($this->url('/cash-totals'))->assertOk()->json('data');
    }

    /** Any delete that reaches for Stripe fails loudly: the service cannot even be built. */
    private function neverBuildsAStripeClient(): void
    {
        $this->app->bind(FormResponseCheckoutService::class, function () {
            throw new \LogicException('This delete must not build a Stripe client.');
        });
    }

    private function disk(): string
    {
        return (string) config('forms.attachments.disk', 'local');
    }

    private function url(string $suffix = '', ?Form $form = null): string
    {
        $form ??= $this->form;

        return "/api/admin/masjids/{$form->masjid_id}/forms/{$form->id}/responses{$suffix}";
    }

    private function withFile(FormResponse $row): FormResponseAttachment
    {
        FormAttachments::store($row, [
            'waiver' => UploadedFile::fake()->create('test-waiver.pdf', 20, 'application/pdf'),
        ]);

        return $row->attachments()->sole();
    }

    /** A registration as the submit writes it, $15 for each of two attendees, with the money leg given. */
    private function row(array $money = [], array $attributes = [], ?Form $form = null): FormResponse
    {
        $form ??= $this->form;

        $row = new FormResponse(array_merge([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => [
                'fullName' => self::NAME,
                'email' => self::EMAIL,
                'waiver' => 'test-waiver.pdf',
                'attendees' => [['attendeeName' => 'Guest One'], ['attendeeName' => 'Guest Two']],
            ],
            'respondent_name' => self::NAME,
            'respondent_email' => self::EMAIL,
            'respondent_phone' => self::PHONE,
            'entry_count' => 2,
            'amount_due' => 30,
            'status' => 'new',
            'submitted_at' => now(),
        ], $attributes));

        $row->forceFill(array_merge(['amount_due_minor' => 3000, 'currency' => 'usd'], $money))->save();

        return $row->fresh();
    }

    /** A card registration waiting on Stripe, with a $1.20 card-fee cover and a page on record. */
    private function cardRow(array $money = [], array $attributes = [], ?Form $form = null): FormResponse
    {
        return $this->row(array_merge([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'fee_covered_minor' => 120,
            'total_minor' => 3120,
            'idempotency_key' => 'form_response_' . Str::uuid(),
            'stripe_checkout_session_id' => 'cs_test_delete_' . Str::random(12),
        ], $money), $attributes, $form);
    }

    /** A family that chose to pay the office and has not: no card fee, no page. */
    private function officeRow(array $money = [], array $attributes = []): FormResponse
    {
        return $this->row(array_merge([
            'payment_method' => FormResponse::METHOD_OFFICE,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'fee_covered_minor' => 0,
            'total_minor' => 3000,
        ], $money), $attributes);
    }

    private function paidCardRow(array $money = [], array $attributes = []): FormResponse
    {
        return $this->cardRow(array_merge([
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_test_delete_' . Str::random(12),
        ], $money), $attributes);
    }

    /**
     * $15 per attendee, by card or at the office, with one file question. $settings
     * replaces whole blocks (null removes one: a form that charges nothing).
     *
     * @param  array<string,mixed>  $settings
     */
    private function makeEventForm(Masjid $org, array $settings = []): Form
    {
        return Form::create([
            'masjid_id' => $org->id,
            'slug' => 'dinner-' . uniqid(),
            'name' => 'Community Dinner',
            'schema' => ['sections' => [
                ['id' => 'contact', 'title' => 'You', 'fields' => [
                    ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                    ['name' => 'waiver', 'label' => 'Signed waiver', 'type' => 'file', 'required' => false],
                ]],
                ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'maxEntries' => 10, 'fields' => [
                    ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => array_filter(array_replace([
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true, 'officePayment' => true, 'officeInstructions' => 'Pay at the front desk.'],
            ], $settings), fn ($block) => $block !== null),
            'is_active' => true,
        ]);
    }

    /** checkout.session.completed for the row's page, as FormPaymentWebhookTest builds it. */
    private function completed(FormResponse $row, string $paymentIntent): array
    {
        return [
            'id' => 'evt_' . Str::random(24),
            'type' => 'checkout.session.completed',
            'account' => self::ACCOUNT,
            'data' => ['object' => [
                'id' => (string) $row->stripe_checkout_session_id,
                'object' => 'checkout.session',
                'mode' => 'payment',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => (int) $row->total_minor,
                'payment_intent' => $paymentIntent,
                'client_reference_id' => $row->uuid,
                'metadata' => [
                    'form_response_uuid' => $row->uuid,
                    'masjid_id' => (string) $row->masjid_id,
                    'form_id' => (string) $row->form_id,
                ],
            ]],
        ];
    }

    /** Signed exactly as Stripe signs, with the Connect endpoint's secret: these are direct charges. */
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

    /**
     * Stripe, as far as the service can tell: each page's status is what the test put in
     * self::$pages (expired when it said nothing), every question is recorded with the
     * account it was asked on, and self::$stripe says how Stripe misbehaves. Replaces the
     * recording stub MakesRamadanGivingForms binds; pages opened by a public submit are
     * cs_test_delete_page_1, _2… and open.
     */
    private function stubStripe(): void
    {
        $this->app->bind(FormResponseCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    $id = 'cs_test_delete_page_' . (count(FormResponseNeverPaidDeleteTest::$pages) + 1);
                    FormResponseNeverPaidDeleteTest::$pages[$id] = 'open';

                    return ['id' => $id, 'url' => "https://checkout.stripe.test/pay/{$id}", 'payment_intent' => null];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    FormResponseNeverPaidDeleteTest::$asked[] = ['seam' => 'retrieve', 'account' => $connectedAccountId, 'session' => $sessionId];

                    switch (FormResponseNeverPaidDeleteTest::$stripe) {
                        case 'down':
                            throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                        case 'garbled':
                            throw new \Stripe\Exception\UnexpectedValueException('Could not read the reply from Stripe.');
                        case 'missing':
                            throw \Stripe\Exception\InvalidRequestException::factory('No such checkout.session.', 404, null, null, null, 'resource_missing');
                        case 'authentication':
                            throw \Stripe\Exception\AuthenticationException::factory('Invalid API Key provided.', 401);
                        case 'unreachable':
                            throw \Stripe\Exception\PermissionException::factory('The provided key does not have access to account.', 403);
                    }

                    $status = FormResponseNeverPaidDeleteTest::$pages[$sessionId] ?? 'expired';

                    return ['status' => $status, 'url' => $status === 'open' ? "https://checkout.stripe.test/pay/{$sessionId}" : null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    FormResponseNeverPaidDeleteTest::$asked[] = ['seam' => 'expire', 'account' => $connectedAccountId, 'session' => $sessionId];

                    switch (FormResponseNeverPaidDeleteTest::$stripe) {
                        case 'paid':
                            FormResponseNeverPaidDeleteTest::$pages[$sessionId] = 'complete';

                            throw \Stripe\Exception\InvalidRequestException::factory('This Checkout Session is not in an expirable state.');
                        case 'still-open':
                            throw \Stripe\Exception\InvalidRequestException::factory('Refused.');
                    }

                    FormResponseNeverPaidDeleteTest::$expired[] = $sessionId;
                    FormResponseNeverPaidDeleteTest::$pages[$sessionId] = 'expired';
                }
            };
        });
    }
}
