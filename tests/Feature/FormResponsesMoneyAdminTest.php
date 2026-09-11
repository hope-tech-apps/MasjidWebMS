<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\Forms\IndexFormResponsesRequest;
use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\FormResponseCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The Form Responses screen at a paying event (DECISIONS.md 2026-09-11): the bracelet
 * table's actions, the cash totals, and the payment columns on the list, the CSV and the
 * roster.
 *
 * The festival brief's blockers, as they land on this screen:
 *
 *  - never free by accident: "Mark collected" refuses a registration that has not paid
 *    on a form that charges — a Wix-fallback row with no money leg at all included;
 *  - money rows are never deleted or silently shrunk: DELETE refuses them, re-triaging
 *    one is stamped with who and when, and a cancelled row's cash moves to its own
 *    column without lowering what was taken;
 *  - double payment at the gate: "Take cash" closes the open card page first, under the
 *    row lock, refuses when Stripe says the payer has just paid, and records nothing
 *    when Stripe cannot say the page is closed;
 *  - the Wix fallback: "Mark paid (external)" puts a Wix payer in the paid set the door
 *    filters on, and the receipt it sends is where the group link travels;
 *  - a cancelled card registration is never left payable: cancelling closes its open
 *    page under the row lock, and says so plainly when Stripe could not;
 *  - a fee form that never took payment (the camp) exports exactly what it always did.
 *
 * Stripe is the checkout service with its three seams stubbed by subclassing; nothing
 * here reaches Stripe. Every new route is a 404 for another masjid's form or response, a
 * 403 for another masjid's admin, and a 401 signed out.
 */
class FormResponsesMoneyAdminTest extends TestCase
{
    use RefreshDatabase;

    private const INVITE = 'AbCdEfGhIj0123456789';

    /** @var array<string,string> Stripe's view of each page: 'open' | 'complete' | 'expired' */
    public static array $pages = [];

    /** @var array<int,string> pages the service closed */
    public static array $expired = [];

    /** null, or how Stripe misbehaves: 'paid' (the payer won the race), 'still-open', 'down', 'garbled' */
    public static ?string $stripe = null;

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private Form $form;

    private Form $otherForm;

    private User $admin;

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
        self::$expired = [];
        self::$stripe = null;
        $this->stubStripe();

        $this->masjid = $this->makeMasjid('acct_test_mec');
        $this->otherMasjid = $this->makeMasjid('acct_test_other');
        $this->form = $this->makeForm($this->masjid);
        $this->otherForm = $this->makeForm($this->otherMasjid);

        $this->admin = $this->makeSuperAdmin();
        Sanctum::actingAs($this->admin);
    }

    // ------------------------------------------------------------ collected

    #[Test]
    public function bracelets_are_stamped_by_the_first_press_and_undo_clears_them(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $row = $this->cashRow($code);

        $this->postJson($this->url("/{$row->id}/collect"))
            ->assertOk()
            ->assertJsonPath('message', 'Marked collected.')
            ->assertJsonPath('data.collected_by.id', $this->admin->id);

        $stamped = $row->fresh()->collected_at;
        $this->assertNotNull($stamped);

        // A colleague at the next table, a few minutes later.
        $this->travel(5)->minutes();
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson($this->url("/{$row->id}/collect"))
            ->assertOk()
            ->assertJsonPath('message', 'Already marked collected.')
            ->assertJsonPath('data.collected_by.id', $this->admin->id);

        $this->assertTrue($stamped->equalTo($row->fresh()->collected_at), 'the first press is the one recorded');
        $this->assertSame($this->admin->id, $row->fresh()->collected_by_user_id);

        $this->deleteJson($this->url("/{$row->id}/collect"))
            ->assertOk()
            ->assertJsonPath('data.collected_at', null)
            ->assertJsonPath('data.collected_by', null);

        $this->assertNull($row->fresh()->collected_at);
        $this->assertNull($row->fresh()->collected_by_user_id);
    }

    #[Test]
    public function a_registration_that_has_not_paid_is_never_marked_collected(): void
    {
        $card = $this->onlineUnpaid();
        $wix = $this->wixRow(); // the fallback: no money leg, on a form that charges
        $free = $this->freeRow();

        foreach ([$card, $wix] as $row) {
            $this->postJson($this->url("/{$row->id}/collect"))
                ->assertStatus(422)
                ->assertJsonPath('message', 'Not paid yet.');

            $this->assertNull($row->fresh()->collected_at);
        }

        // A form that charges nothing: settled by definition.
        $this->postJson($this->url("/{$free->id}/collect", $free->form))->assertOk();
        $this->assertNotNull($free->fresh()->collected_at);
    }

    #[Test]
    public function a_cancelled_registration_gets_no_bracelets_even_when_paid(): void
    {
        // The refund runbook cancels a refunded card payer; they still match payment=paid.
        $refunded = $this->onlinePaid(['status' => FormResponse::STATUS_CANCELLED]);

        $this->postJson($this->url("/{$refunded->id}/collect"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration is cancelled. Re-open it before handing anything out.');

        $this->assertNull($refunded->fresh()->collected_at);
    }

    // ------------------------------------------------------------ take cash

    #[Test]
    public function take_cash_closes_the_open_card_page_then_settles_as_cash_by_the_operator(): void
    {
        $row = $this->onlineUnpaid();
        self::$pages[$row->stripe_checkout_session_id] = 'open';

        $this->postJson($this->url("/{$row->id}/take-cash"))
            ->assertOk()
            ->assertJsonPath('message', 'Cash recorded.')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.settled', true)
            ->assertJsonPath('data.card_page_opened', false)
            ->assertJsonPath('data.marked_paid_by.id', $this->admin->id);

        $this->assertSame([$row->stripe_checkout_session_id], self::$expired, 'the card page is closed before the cash is recorded');

        $row->refresh();
        $this->assertSame(FormResponse::METHOD_CASH, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
        $this->assertNotNull($row->paid_at);
        $this->assertSame($this->admin->id, $row->marked_paid_by_user_id);
        $this->assertNull($row->staff_code_id);

        // Cash pays the list price: the card-fee cover chosen for the page no longer applies.
        $this->assertSame(3000, $row->total_minor);
        $this->assertSame(0, $row->fee_covered_minor);

        // The page stays on the row: a card payment that still lands is recorded against
        // it and never flips the row back.
        $this->assertNotNull($row->stripe_checkout_session_id);

        // Nobody had been told about a card registration waiting on Stripe: both emails go now.
        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->paymentLine === 'Paid in cash'
            && $mail->whatsappUrl === 'https://chat.whatsapp.com/' . self::INVITE);
        Mail::assertQueued(FormResponseSubmitted::class, 1);
    }

    #[Test]
    public function take_cash_is_refused_when_the_payer_has_just_paid_by_card(): void
    {
        // The close is refused because the payer finished a moment before it landed.
        $raced = $this->onlineUnpaid();
        self::$pages[$raced->stripe_checkout_session_id] = 'open';
        self::$stripe = 'paid';

        $this->postJson($this->url("/{$raced->id}/take-cash"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration has just been paid by card, and Stripe is confirming it. Do not take a second payment.');

        $this->assertStillWaitingOnStripe($raced);

        // A page Stripe already reports complete is never closed at all.
        self::$stripe = null;
        $paid = $this->onlineUnpaid();
        self::$pages[$paid->stripe_checkout_session_id] = 'complete';

        $this->postJson($this->url("/{$paid->id}/mark-paid-external"))->assertStatus(422);

        $this->assertStillWaitingOnStripe($paid);
        $this->assertSame([], self::$expired);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function nothing_is_recorded_when_stripe_cannot_confirm_the_page_is_closed(): void
    {
        $row = $this->onlineUnpaid();
        self::$pages[$row->stripe_checkout_session_id] = 'open';

        self::$stripe = 'still-open';

        $this->postJson($this->url("/{$row->id}/take-cash"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'That payment page could not be closed. Try again in a moment.');

        $this->assertStillWaitingOnStripe($row);

        self::$stripe = 'down';

        $this->postJson($this->url("/{$row->id}/mark-paid-external"))->assertStatus(503);

        $this->assertStillWaitingOnStripe($row);

        // A reply the SDK could not read is no more an answer than no reply at all.
        self::$stripe = 'garbled';

        $this->postJson($this->url("/{$row->id}/take-cash"))
            ->assertStatus(503)
            ->assertJsonPath('status', 'failed');

        $this->assertStillWaitingOnStripe($row);
        $this->assertSame([], self::$expired);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function take_cash_refuses_a_paid_a_cancelled_or_a_free_registration(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $card = $this->onlinePaid();
        $cash = $this->cashRow($code);
        $cancelled = $this->onlineUnpaid([], ['status' => FormResponse::STATUS_CANCELLED]);
        $free = $this->freeRow();

        $this->postJson($this->url("/{$card->id}/take-cash"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration has already been paid by card. Do not take a second payment.');

        $this->postJson($this->url("/{$cash->id}/take-cash"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cash has already been recorded for this registration.');

        $this->postJson($this->url("/{$cancelled->id}/take-cash"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration is cancelled. Re-open it before recording a payment.');

        $this->postJson($this->url("/{$free->id}/take-cash", $free->form))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration has nothing to pay.');

        $this->assertSame(FormResponse::METHOD_ONLINE, $card->fresh()->payment_method);
        $this->assertNull($cash->fresh()->marked_paid_by_user_id);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $cancelled->fresh()->payment_status);
        $this->assertNull($free->fresh()->payment_status);
        $this->assertSame([], self::$expired, 'no card page is touched by a refusal');
        Mail::assertNothingQueued();
    }

    // -------------------------------------------------------- the Wix fallback

    #[Test]
    public function a_wix_payer_marked_paid_joins_the_paid_set_and_gets_the_receipt_with_the_group_link(): void
    {
        // The fallback: card payment switched off; codes carry on.
        $this->form->update(['settings' => array_replace($this->form->settings, ['payment' => ['staffCodes' => true]])]);
        $wix = $this->wixRow();

        $this->getJson($this->url('?payment=paid'))->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson($this->url('?payment=unpaid'))->assertOk()
            ->assertJsonPath('data.data.0.id', $wix->id)
            ->assertJsonPath('data.data.0.payment_state', 'unpaid')
            ->assertJsonPath('data.data.0.settled', false);

        // Form-encoded, the way the SPA posts.
        $this->post($this->url("/{$wix->id}/mark-paid-external"), [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Marked paid.')
            ->assertJsonPath('data.payment_method', 'external')
            ->assertJsonPath('data.payment_state', 'paid')
            ->assertJsonPath('data.marked_paid_by.id', $this->admin->id);

        $wix->refresh();
        $this->assertSame(FormResponse::PAYMENT_PAID, $wix->payment_status);
        $this->assertSame(3000, $wix->total_minor, 'from the legacy decimal, never a float');
        $this->assertSame($this->admin->id, $wix->marked_paid_by_user_id);

        // At the door now: paid, not collected.
        $this->getJson($this->url('?payment=paid&collected=no'))->assertOk()->assertJsonPath('data.data.0.id', $wix->id);

        // The receipt carries the group link; the coordinators heard about this person at submit.
        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->hasTo('amal@example.com')
            && $mail->paymentLine === 'Paid (recorded by staff)'
            && $mail->whatsappUrl === 'https://chat.whatsapp.com/' . self::INVITE);
        Mail::assertNotQueued(FormResponseSubmitted::class);

        // A colleague's second press changes nothing and sends nothing.
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson($this->url("/{$wix->id}/mark-paid-external"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration is already marked paid.');

        $this->assertSame($this->admin->id, $wix->fresh()->marked_paid_by_user_id);
        Mail::assertQueued(FormSubmissionReceipt::class, 1);
    }

    // ------------------------------------------------------- never shrunk

    #[Test]
    public function a_payment_recorded_at_the_door_names_the_price_the_registration_was_made_at(): void
    {
        // Early bird until 10 October, Standard until the 16th, then the day-of price, on
        // MEC's clock.
        $this->masjid->forceFill(['timezone' => 'America/New_York'])->save();
        $form = $this->makeForm($this->masjid, [
            'identity' => ['name' => 'fullName', 'email' => 'email'],
            'notifyEmails' => ['festival-office@mec.test'],
            'fee' => ['currency' => 'USD', 'perEntryOfSection' => 'attendees', 'tiers' => [
                ['label' => 'Early bird', 'amount' => 20, 'until' => '2026-10-10'],
                ['label' => 'Standard', 'amount' => 25, 'until' => '2026-10-16'],
                ['label' => 'Day of', 'amount' => 30],
            ]],
            'payment' => ['online' => true, 'staffCodes' => true],
            'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE,
        ]);

        // Both registered ten minutes before the early-bird price ended: two at $20.
        $this->travelTo(Carbon::parse('2026-10-10 23:50', 'America/New_York'));
        $wix = $this->row(['amount_due_minor' => null, 'currency' => null], ['amount_due' => 40], $form);
        $card = $this->onlineUnpaid(['amount_due_minor' => 4000, 'fee_covered_minor' => 0, 'total_minor' => 4000], ['amount_due' => 40], $form);

        // Festival morning at the door, with the day-of price in force.
        $this->travelTo(Carbon::parse('2026-10-17 10:00', 'America/New_York'));
        $this->assertSame('Day of', $form->fresh()->feeRule()['currentTier']['label']);

        $this->postJson($this->url("/{$wix->id}/mark-paid-external", $form))->assertOk();
        $this->postJson($this->url("/{$card->id}/take-cash", $form))->assertOk();

        foreach ([$wix, $card] as $row) {
            Mail::assertQueued(FormSubmissionReceipt::class, fn ($mail) => $mail->responseId === $row->id
                && $mail->amountLine === '$40.00'
                && $mail->tierLabel === 'Early bird');
        }

        // The coordinators hear of the card registration only now, at the same price.
        Mail::assertQueued(FormResponseSubmitted::class, fn ($mail) => $mail->responseId === $card->id
            && $mail->tierLabel === 'Early bird');
    }

    #[Test]
    public function a_registration_with_a_payment_is_never_deleted_only_cancelled(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $cash = $this->cashRow($code);
        $card = $this->onlineUnpaid();
        $plain = $this->wixRow();

        $counted = $this->form->fresh()->response_count;

        foreach ([$cash, $card] as $row) {
            $this->deleteJson($this->url("/{$row->id}"))
                ->assertStatus(422)
                ->assertJsonPath('message', 'A registration with a payment is never deleted. Cancel it instead, and add a note.');

            $this->assertDatabaseHas('form_responses', ['id' => $row->id]);
        }

        $this->assertSame($counted, $this->form->fresh()->response_count);

        // A row with no money leg is deleted as it always was.
        $this->deleteJson($this->url("/{$plain->id}"))->assertOk();
        $this->assertDatabaseMissing('form_responses', ['id' => $plain->id]);
        $this->assertSame($counted - 1, $this->form->fresh()->response_count);
    }

    #[Test]
    public function cancelling_a_cash_row_is_stamped_and_moves_its_cash_to_its_own_column(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $row = $this->cashRow($code);
        $plain = $this->wixRow();

        $before = $this->getJson($this->url('/cash-totals'))->assertOk()->json('data');
        $this->assertSame(3000, $before['holders'][0]['cash_minor']);
        $this->assertSame(3000, $before['totals']['taken_minor']);

        $this->putJson($this->url("/{$row->id}"), ['status' => 'cancelled', 'admin_notes' => 'Volunteer, comped'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.status_changed_by.id', $this->admin->id);

        $row->refresh();
        $this->assertSame($this->admin->id, $row->status_changed_by_user_id);
        $this->assertNotNull($row->status_changed_at);

        $after = $this->getJson($this->url('/cash-totals'))->assertOk()->json('data');
        $holder = $after['holders'][0];

        $this->assertSame('Najd Haddad', $holder['holder_name']);
        $this->assertSame(0, $holder['cash_minor']);
        $this->assertSame(0, $holder['submissions']);
        $this->assertSame(1, $holder['cancelled_submissions']);
        $this->assertSame(3000, $holder['cancelled_cash_minor']);
        $this->assertSame(1, $holder['use_count']);
        $this->assertSame($before['totals']['taken_minor'], $after['totals']['taken_minor'], 'cancelling never lowers what was taken');
        $this->assertSame(3000, $after['totals']['cancelled_cash_minor']);

        // A row with no money leg is re-triaged as it always was, with no stamp.
        $this->putJson($this->url("/{$plain->id}"), ['status' => 'confirmed'])->assertOk();
        $this->assertNull($plain->fresh()->status_changed_by_user_id);
    }

    #[Test]
    public function cancelling_an_unpaid_card_registration_closes_its_payment_page(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $cash = $this->cashRow($code);

        // Declined at home, then paid cash at the gate: the card registration is the duplicate.
        $card = $this->onlineUnpaid();
        $page = $card->stripe_checkout_session_id;
        self::$pages[$page] = 'open';

        $this->putJson($this->url("/{$card->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.status_changed_by.id', $this->admin->id)
            ->assertJsonPath('message', 'Cancelled, and its card payment page is closed.')
            ->assertJsonPath('warning', false);

        $this->assertSame([$page], self::$expired, 'the page the payer still holds is closed');
        $this->assertStillWaitingOnStripe($card);

        // The payer paid a moment before the close landed: the cancellation stands, and the
        // admin is told to refund, not that the page is closed.
        $raced = $this->onlineUnpaid();
        self::$pages[$raced->stripe_checkout_session_id] = 'open';
        self::$stripe = 'paid';

        $this->putJson($this->url("/{$raced->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('warning', true)
            ->assertJsonPath('message', 'This registration had just been paid by card, so it will show as paid once Stripe confirms it. Refund it in Stripe if it should not stand.');

        $this->assertStillWaitingOnStripe($raced); // only the webhook marks it paid

        // Stripe will not close it, or cannot be reached: the cancellation still stands, the
        // admin is told the page may still take a payment, and cancelling again retries.
        foreach (['still-open', 'down'] as $failure) {
            $stuck = $this->onlineUnpaid();
            self::$pages[$stuck->stripe_checkout_session_id] = 'open';
            self::$stripe = $failure;

            $this->putJson($this->url("/{$stuck->id}"), ['status' => 'cancelled'])
                ->assertOk()
                ->assertJsonPath('data.status', 'cancelled')
                ->assertJsonPath('warning', true)
                ->assertJsonPath('message', 'Cancelled, but its card payment page could not be closed, so it may still take a payment. Cancel it again to retry.');

            $this->assertSame(FormResponse::STATUS_CANCELLED, $stuck->fresh()->status, "{$failure}: the cancellation stands");
            $this->assertNotContains($stuck->stripe_checkout_session_id, self::$expired);

            self::$stripe = null;

            $this->putJson($this->url("/{$stuck->id}"), ['status' => 'cancelled'])
                ->assertOk()
                ->assertJsonPath('warning', false)
                ->assertJsonPath('message', 'Cancelled, and its card payment page is closed.');

            $this->assertContains($stuck->stripe_checkout_session_id, self::$expired, "{$failure}: the retry closes it");
        }

        // A note on a cancelled registration, and cancelling one with no card page, ask
        // Stripe nothing and answer as triage always did.
        $closed = count(self::$expired);

        $this->putJson($this->url("/{$card->id}"), ['admin_notes' => 'Duplicate of a cash entry at the gate.'])
            ->assertOk()
            ->assertJsonMissingPath('message');
        $this->putJson($this->url("/{$cash->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonMissingPath('message');

        $this->assertCount($closed, self::$expired);
    }

    #[Test]
    public function cancelling_a_registration_already_paid_by_card_tells_the_admin_to_refund_it(): void
    {
        Log::spy();

        // The family paid at home a moment before an admin, reading a door list loaded
        // minutes earlier, cancelled the registration as the duplicate of a cash entry.
        $paid = $this->onlinePaid();
        self::$pages[$paid->stripe_checkout_session_id] = 'complete';

        $this->putJson($this->url("/{$paid->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('warning', true)
            ->assertJsonPath('card_page', 'paid_on_stripe')
            ->assertJsonPath('message', 'This registration was already paid by card ($31.20). Cancelling does not refund it. Refund it in Stripe if it should not stand.');

        $this->assertSame([], self::$expired, 'a paid page has nothing to close');
        $this->assertSame(FormResponse::PAYMENT_PAID, $paid->fresh()->payment_status, 'cancelling never unpays');

        // Findable in a log that runs at warning, by ids: never the payer.
        $said = fn (string $message, array $context = []) => str_contains($message, 'already paid by card was cancelled')
            && ($context['form_response_id'] ?? null) === $paid->id
            && ($context['payment_intent'] ?? null) === $paid->stripe_payment_intent_id
            && ! str_contains((string) json_encode($context), 'amal@example.com');

        Log::shouldHaveReceived('warning')->withArgs($said)->once();

        // A note on it is not news.
        $this->putJson($this->url("/{$paid->id}"), ['admin_notes' => 'Refund requested in Stripe.'])
            ->assertOk()
            ->assertJsonMissingPath('message')
            ->assertJsonMissingPath('card_page');

        // Saying "cancelled" again is: the card's money is still there to refund, so the
        // answer says so every time. The log line was written once, by the cancel.
        $this->putJson($this->url("/{$paid->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('warning', true)
            ->assertJsonPath('card_page', 'paid_on_stripe')
            ->assertJsonPath('message', 'This registration was already paid by card ($31.20). Cancelling does not refund it. Refund it in Stripe if it should not stand.');

        Log::shouldHaveReceived('warning')->withArgs($said)->once();
    }

    #[Test]
    public function a_card_payment_that_lands_after_a_failed_close_is_flagged_on_the_next_cancel(): void
    {
        // Cancelled as a duplicate while the payer still had the page open, and Stripe
        // would not close it.
        $row = $this->onlineUnpaid();
        self::$pages[$row->stripe_checkout_session_id] = 'open';
        self::$stripe = 'still-open';

        $this->putJson($this->url("/{$row->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('warning', true)
            ->assertJsonPath('card_page', 'unconfirmed');

        // The payer finished the page, and the webhook recorded the payment.
        self::$stripe = null;
        self::$pages[$row->stripe_checkout_session_id] = 'complete';
        $this->assertTrue($row->fresh()->markPaid('pi_late_1'));

        // "Close its card payment page again" is the next thing the admin presses. There is
        // no page left to close; there is a payment to refund, and the answer says so.
        $this->putJson($this->url("/{$row->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('warning', true)
            ->assertJsonPath('card_page', 'paid_on_stripe')
            ->assertJsonPath('message', 'This registration was already paid by card ($31.20). Cancelling does not refund it. Refund it in Stripe if it should not stand.');

        $this->assertSame([], self::$expired, 'a paid page has nothing to close');
    }

    #[Test]
    public function every_cancel_answer_says_what_happened_to_the_card_page(): void
    {
        $cancel = fn (FormResponse $row) => $this->putJson($this->url("/{$row->id}"), ['status' => 'cancelled'])->assertOk();

        // Closed.
        $open = $this->onlineUnpaid();
        self::$pages[$open->stripe_checkout_session_id] = 'open';
        $cancel($open)->assertJsonPath('card_page', 'closed')->assertJsonPath('warning', false);

        // Already expired: nothing left that can be paid, which is closed too.
        $expired = $this->onlineUnpaid();
        self::$pages[$expired->stripe_checkout_session_id] = 'expired';
        $cancel($expired)->assertJsonPath('card_page', 'closed');

        // Paid a moment before the close landed: refund it, never "close it again".
        $raced = $this->onlineUnpaid();
        self::$pages[$raced->stripe_checkout_session_id] = 'open';
        self::$stripe = 'paid';
        $cancel($raced)->assertJsonPath('card_page', 'paid_on_stripe')->assertJsonPath('warning', true);
        $this->assertStillWaitingOnStripe($raced);

        // Stripe refused the close or could not be reached: it may still take a payment.
        foreach (['still-open', 'down', 'garbled'] as $failure) {
            $stuck = $this->onlineUnpaid();
            self::$pages[$stuck->stripe_checkout_session_id] = 'open';
            self::$stripe = $failure;
            $cancel($stuck)->assertJsonPath('card_page', 'unconfirmed')->assertJsonPath('warning', true);
        }

        self::$stripe = null;
        $cancel($stuck)->assertJsonPath('card_page', 'closed');

        // No card page at all: a cash entry, a Wix payer, a card registration whose page
        // never opened. Nothing to say, as before, and the word says why.
        [$code] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $never = $this->onlineUnpaid(['stripe_checkout_session_id' => null]);

        foreach ([$this->cashRow($code), $this->wixRow(), $never] as $row) {
            $cancel($row)->assertJsonPath('card_page', 'none')->assertJsonMissingPath('message');
        }

        // Any other triage says nothing about the card page.
        $this->putJson($this->url("/{$open->id}"), ['admin_notes' => 'Duplicate of a cash entry.'])
            ->assertOk()
            ->assertJsonMissingPath('card_page');
        $this->putJson($this->url("/{$never->id}"), ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonMissingPath('card_page');

        // A page is on the row, but the organisation has no Stripe account on record to
        // ask: nobody knows whether it is still open, and the answer does not call it closed.
        $closedSoFar = self::$expired;
        $unasked = $this->onlineUnpaid();
        self::$pages[$unasked->stripe_checkout_session_id] = 'open';
        $this->masjid->forceFill(['stripe_account_id' => null])->save();

        $cancel($unasked)->assertJsonPath('card_page', 'unchecked')->assertJsonMissingPath('message');
        $this->assertSame($closedSoFar, self::$expired, 'Stripe was not asked');
    }

    // ------------------------------------------------------------ cash totals

    #[Test]
    public function cash_totals_put_each_holders_cash_beside_their_codes_use_count(): void
    {
        [$najd] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        [$amal] = FormStaffCode::issue($this->form, 'Amal Yusuf', now()->addDay());
        [$idle] = FormStaffCode::issue($this->form, 'Zaid Idle', now()->addDay());
        $idle->revoke();

        $this->cashRow($najd, 1);
        $this->cashRow($najd, 2);
        $this->cashRow($amal, 3);
        $this->onlinePaid();
        $atTable = $this->onlineUnpaid(['stripe_checkout_session_id' => null]);

        // Another masjid's cash never lands in these totals.
        [$theirs] = FormStaffCode::issue($this->otherForm, 'Bilal Khan', now()->addDay());
        $this->cashRow($theirs, 4, form: $this->otherForm);

        $this->postJson($this->url("/{$atTable->id}/take-cash"))->assertOk();

        $data = $this->getJson($this->url('/cash-totals'))->assertOk()->json('data');

        // Every code, taken anything or not, by name; then the admin who took cash at the table.
        $this->assertSame(['Amal Yusuf', 'Najd Haddad', 'Zaid Idle', $this->admin->name], array_column($data['holders'], 'holder_name'));

        $holders = collect($data['holders'])->keyBy('holder_name');

        $this->assertSame('code', $holders['Najd Haddad']['kind']);
        $this->assertSame($najd->id, $holders['Najd Haddad']['staff_code_id']);
        $this->assertSame(2, $holders['Najd Haddad']['use_count']);
        $this->assertSame(2, $holders['Najd Haddad']['submissions']);
        $this->assertSame(3, $holders['Najd Haddad']['people']);
        $this->assertSame(4500, $holders['Najd Haddad']['cash_minor']);

        $this->assertSame(1, $holders['Amal Yusuf']['use_count']);
        $this->assertSame(1, $holders['Amal Yusuf']['submissions']);
        $this->assertSame(4500, $holders['Amal Yusuf']['cash_minor']);

        $this->assertTrue($holders['Zaid Idle']['revoked']);
        $this->assertSame(0, $holders['Zaid Idle']['use_count']);
        $this->assertSame(0, $holders['Zaid Idle']['cash_minor']);

        $atTableHolder = $holders[$this->admin->name];
        $this->assertSame('admin', $atTableHolder['kind']);
        $this->assertSame($this->admin->id, $atTableHolder['user_id']);
        $this->assertNull($atTableHolder['staff_code_id']);
        $this->assertNull($atTableHolder['use_count']);
        $this->assertSame(1, $atTableHolder['submissions']);
        $this->assertSame(3000, $atTableHolder['cash_minor']);

        $this->assertSame(4, $data['totals']['submissions']);
        $this->assertSame(8, $data['totals']['people']);
        $this->assertSame(12000, $data['totals']['cash_minor']);
        $this->assertSame(12000, $data['totals']['taken_minor']);

        // For context: the card payment, never counted as anyone's cash.
        $this->assertSame(1, $data['other_paid']['online']['submissions']);
        $this->assertSame(3120, $data['other_paid']['online']['total_minor']);
        $this->assertSame(0, $data['other_paid']['external']['submissions']);
        $this->assertSame('usd', $data['currency']);
    }

    #[Test]
    public function cash_totals_honour_the_lists_filters_and_never_sort_a_grouped_query(): void
    {
        [$najd] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $this->cashRow($najd, 1, ['respondent_name' => 'Bilal Khan', 'submitted_at' => '2026-10-17 15:00:00']);
        $zaynab = $this->cashRow($najd, 2, ['respondent_name' => 'Zaynab Ali', 'submitted_at' => '2026-10-16 15:00:00']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $searched = $this->getJson($this->url('/cash-totals?q=Zaynab&sort=respondent_name&direction=asc'))->assertOk()->json('data');

        $grouped = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains(strtolower($sql), 'group by'));

        DB::disableQueryLog();

        $this->assertNotEmpty($grouped);

        foreach ($grouped as $sql) {
            // MySQL's ONLY_FULL_GROUP_BY rejects a grouped query that sorts by a column it
            // does not group by, and SQLite would never say so.
            $this->assertStringNotContainsString('order by', strtolower($sql));
        }

        $this->assertSame(3000, $searched['totals']['cash_minor']);
        $this->assertSame(1500, $this->getJson($this->url('/cash-totals?from=2026-10-17&to=2026-10-17'))->json('data.totals.cash_minor'));
        $this->assertSame(3000, $this->getJson($this->url('/cash-totals?q=' . urlencode('#' . $zaynab->id)))->json('data.totals.cash_minor'));
    }

    // ------------------------------------------------------------ the list

    #[Test]
    public function the_door_filters_narrow_the_list(): void
    {
        [$najd] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        [$amal] = FormStaffCode::issue($this->form, 'Amal Yusuf', now()->addDay());
        $cashNajd = $this->cashRow($najd);
        $cashAmal = $this->cashRow($amal);
        $card = $this->onlinePaid();
        $unpaid = $this->onlineUnpaid();
        $wix = $this->wixRow();
        $external = $this->row([
            'payment_method' => FormResponse::METHOD_EXTERNAL,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'total_minor' => 3000,
            'marked_paid_by_user_id' => $this->admin->id,
        ]);
        $cashNajd->markCollected($this->admin);

        $free = $this->makeForm($this->masjid, $this->freeSettings());
        $gratis = $this->row(['amount_due_minor' => null, 'currency' => null], ['amount_due' => null], $free);
        // Written while this form still had a price: it owed, whatever the form says now.
        $owedOnce = $this->row(['amount_due_minor' => null, 'currency' => null], ['amount_due' => 10], $free);

        $ids = function (string $query, ?Form $form = null): array {
            return collect($this->getJson($this->url('?' . $query, $form))->assertOk()->json('data.data'))->pluck('id')->all();
        };

        $this->assertEqualsCanonicalizing([$cashNajd->id, $cashAmal->id, $card->id, $external->id], $ids('payment=paid'));
        $this->assertEqualsCanonicalizing([$cashNajd->id, $cashAmal->id, $card->id, $external->id], $ids('payment=settled'));
        $this->assertEqualsCanonicalizing([$unpaid->id, $wix->id], $ids('payment=unpaid'));
        $this->assertEqualsCanonicalizing([$cashNajd->id, $cashAmal->id], $ids('payment=cash'));
        $this->assertEqualsCanonicalizing([$card->id, $unpaid->id], $ids('payment=online'));
        $this->assertEqualsCanonicalizing([$external->id], $ids('payment=external'));
        $this->assertEqualsCanonicalizing([$cashNajd->id], $ids('collected=yes'));
        $this->assertEqualsCanonicalizing([$cashAmal->id, $card->id, $external->id], $ids('payment=paid&collected=no'));
        $this->assertEqualsCanonicalizing([$cashAmal->id], $ids('staff_code_id=' . $amal->id));

        // On a form that charges nothing, settled and unpaid still read the row's own snapshot.
        $this->assertSame([$gratis->id], $ids('payment=settled', $free));
        $this->assertSame([$owedOnce->id], $ids('payment=unpaid', $free));

        // The filter and the row's own `settled` never disagree.
        $settled = $ids('payment=settled');

        foreach ($this->getJson($this->url('?per_page=100'))->json('data.data') as $row) {
            $this->assertSame(in_array($row['id'], $settled, true), $row['settled'], "row {$row['id']}");
        }

        $this->getJson($this->url('?payment=free'))->assertStatus(422);
        $this->getJson($this->url('?collected=maybe'))->assertStatus(422);
        $this->getJson($this->url('?staff_code_id=abc'))->assertStatus(422);
    }

    #[Test]
    public function the_list_shows_whose_cash_it_is_and_who_did_what_never_the_code(): void
    {
        [$code, $plain] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $cash = $this->cashRow($code);
        $cash->markCollected($this->admin);
        $card = $this->onlineUnpaid();

        $list = $this->getJson($this->url())->assertOk();
        $rows = collect($list->json('data.data'))->keyBy('id');

        $this->assertSame(['id' => $code->id, 'holder_name' => 'Najd Haddad', 'code_hint' => $code->code_hint], $rows[$cash->id]['staff_code']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->name], $rows[$cash->id]['collected_by']);
        $this->assertSame('paid', $rows[$cash->id]['payment_state']);
        $this->assertTrue($rows[$cash->id]['settled']);
        $this->assertSame($cash->uuid, $rows[$cash->id]['uuid']);
        $this->assertSame(3000, $rows[$cash->id]['total_minor']);
        $this->assertNotNull($rows[$cash->id]['paid_at']);

        $this->assertTrue($rows[$card->id]['card_page_opened']);
        $this->assertFalse($rows[$card->id]['settled']);
        $this->assertSame('unpaid', $rows[$card->id]['payment_state']);
        $this->assertSame(120, $rows[$card->id]['fee_covered_minor']);
        $this->assertNull($rows[$card->id]['staff_code']);

        foreach ([$plain, FormStaffCode::normalise($plain), $code->code_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $list->getContent());
        }

        $this->assertSame(IndexFormResponsesRequest::PAYMENT_FILTERS, $list->json('meta.payment_filters'));
        $this->assertSame(['yes', 'no'], $list->json('meta.collected_filters'));
        $this->assertTrue($list->json('meta.payment.charges_fee'));
        $this->assertTrue($list->json('meta.payment.staff_codes'));
        $this->assertSame(
            [['id' => $code->id, 'holder_name' => 'Najd Haddad', 'code_hint' => $code->code_hint, 'revoked' => false]],
            $list->json('meta.payment.codes')
        );

        // The detail view carries the same, with the answers.
        $this->getJson($this->url("/{$cash->id}"))->assertOk()
            ->assertJsonPath('data.staff_code.holder_name', 'Najd Haddad')
            ->assertJsonPath('data.data.fullName', 'Amal Yusuf');
    }

    #[Test]
    public function the_list_reads_holders_and_operators_in_a_fixed_number_of_queries(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url())->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        [$first] = FormStaffCode::issue($this->form, 'Holder 0', now()->addDay());
        $this->cashRow($first)->markCollected($this->admin);

        $few = $queries();

        foreach (range(1, 5) as $n) {
            [$code] = FormStaffCode::issue($this->form, "Holder {$n}", now()->addDay());
            $this->cashRow($code)->markCollected($this->makeSuperAdmin());
        }

        $this->assertSame($few, $queries(), 'one query per relation per page, never one per row');
    }

    #[Test]
    public function the_table_finds_a_registration_by_the_number_on_its_receipt(): void
    {
        $target = $this->onlinePaid(['respondent_name' => 'Bilal Khan']);
        $this->onlinePaid(['respondent_name' => 'Zaynab Ali']);

        $this->getJson($this->url('?q=' . urlencode('#' . $target->id)))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target->id);

        $this->getJson($this->url('?q=' . $target->id))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target->id);
    }

    #[Test]
    public function the_csv_carries_the_payment_columns_and_guards_the_holder_name(): void
    {
        [$code] = FormStaffCode::issue($this->form, '=HYPERLINK("http://evil","x")', now()->addDay());
        $cash = $this->cashRow($code);
        $cash->markCollected($this->admin);
        $wix = $this->wixRow();

        $csv = $this->get($this->url('/export'))->assertOk()->streamedContent();

        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", trim($csv)))));

        $this->assertSame([
            'Submitted', 'Status', 'Entries', 'Amount due',
            'Registration no.', 'Payment method', 'Payment status', 'Paid at',
            'Staff code holder', 'Marked paid by', 'Card fee covered', 'Total paid',
            'Collected at', 'Collected by',
        ], array_slice($lines[0], 0, 14));

        $this->assertStringNotContainsString(',=HYPERLINK', $csv);

        $byId = collect(array_slice($lines, 1))->keyBy(fn (array $line) => (int) $line[4]);

        $this->assertSame('cash', $byId[$cash->id][5]);
        $this->assertSame('paid', $byId[$cash->id][6]);
        $this->assertNotSame('', $byId[$cash->id][7]);
        $this->assertSame("'=HYPERLINK(\"http://evil\",\"x\")", $byId[$cash->id][8]);
        $this->assertSame('0.00', $byId[$cash->id][10]);
        $this->assertSame('30.00', $byId[$cash->id][11]);
        $this->assertNotSame('', $byId[$cash->id][12]);
        $this->assertSame($this->admin->name, $byId[$cash->id][13]);

        $this->assertSame('', $byId[$wix->id][5]);
        $this->assertSame('unpaid', $byId[$wix->id][6], 'a Wix-fallback row is unpaid, never blank');
        $this->assertSame('', $byId[$wix->id][11]);
    }

    #[Test]
    public function the_roster_says_who_paid_and_who_has_their_bracelets(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        $cash = $this->cashRow($code, 2);
        $cash->markCollected($this->admin);
        $wix = $this->wixRow();

        $rows = collect($this->getJson($this->url('/roster'))->assertOk()->json('data.data'));
        $people = $rows->where('response_id', $cash->id);

        $this->assertCount(2, $people, 'one row per person');

        foreach ($people as $person) {
            $this->assertSame('paid', $person['payment_status']);
            $this->assertSame('Cash (Najd Haddad)', $person['payment']);
            $this->assertSame('Najd Haddad', $person['holder']);
            $this->assertNotNull($person['collected_at']);
        }

        $this->assertSame('Unpaid', $rows->firstWhere('response_id', $wix->id)['payment']);
        $this->assertSame('unpaid', $rows->firstWhere('response_id', $wix->id)['payment_status']);

        // The door's filters narrow the roster too.
        $this->assertCount(2, $this->getJson($this->url('/roster?payment=paid'))->assertOk()->json('data.data'));

        $csv = $this->get($this->url('/roster/export'))->assertOk()->streamedContent();
        $header = str_getcsv((string) strtok($csv, "\n"));

        $this->assertSame(['Payment', 'Collected'], array_slice($header, -2));
        $this->assertStringContainsString('Cash (Najd Haddad)', $csv);
    }

    #[Test]
    public function the_roster_carries_the_same_payment_block_and_filters_as_the_list(): void
    {
        FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());

        $list = $this->getJson($this->url())->assertOk();

        // Read on its own, as the screen reads it after switching forms on the roster tab.
        $roster = $this->getJson($this->url('/roster'))->assertOk()
            ->assertJsonPath('meta.form.id', $this->form->id)
            ->assertJsonPath('meta.payment.enabled', true)
            ->assertJsonPath('meta.payment.online', true)
            ->assertJsonPath('meta.payment.staff_codes', true)
            ->assertJsonPath('meta.payment.codes.0.holder_name', 'Najd Haddad')
            ->assertJsonPath('meta.payment_filters', IndexFormResponsesRequest::PAYMENT_FILTERS)
            ->assertJsonPath('meta.collected_filters', IndexFormResponsesRequest::COLLECTED_FILTERS);

        $this->assertSame($list->json('meta.payment'), $roster->json('meta.payment'));

        // A form that takes no payment says so on the roster too.
        $free = $this->makeForm($this->masjid, $this->freeSettings());

        $this->getJson($this->url('/roster', $free))->assertOk()
            ->assertJsonPath('meta.form.id', $free->id)
            ->assertJsonPath('meta.payment.enabled', false)
            ->assertJsonPath('meta.payment.codes', []);
    }

    #[Test]
    public function a_fee_form_that_never_took_payment_exports_exactly_what_it_always_did(): void
    {
        // Burlington's camp: a price per camper, families pay elsewhere and are triaged
        // "confirmed", and no payment settings at all.
        $campSettings = [
            'identity' => ['name' => 'fullName', 'email' => 'email'],
            'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
        ];
        $camp = $this->makeForm($this->masjid, $campSettings);
        $legacy = ['amount_due_minor' => null, 'currency' => null];

        $confirmed = $this->row($legacy, ['status' => 'confirmed'], $camp);
        $this->row($legacy, [], $camp, 1);

        $csv = $this->get($this->url('/export', $camp))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", trim($csv)))));

        // The four columns it always had, then the questions: nothing inserted between.
        $this->assertSame(['Submitted', 'Status', 'Entries', 'Amount due', 'Full name', 'Email', 'Name'], $lines[0]);

        $byStatus = collect(array_slice($lines, 1))->keyBy(1);

        $this->assertSame(
            [$confirmed->submitted_at->format('Y-m-d H:i'), 'confirmed', '2', '30.00', 'Amal Yusuf', 'amal@example.com', 'Guest 1 | Guest 2'],
            $byStatus['confirmed']
        );
        $this->assertCount(7, $byStatus['new']);

        $roster = $this->get($this->url('/roster/export', $camp))->assertOk()->streamedContent();

        $this->assertSame(
            ['Name', 'Registered by', 'Registrant email', 'Registrant phone', 'Status', 'Submitted'],
            str_getcsv((string) strtok($roster, "\n"))
        );
        $this->assertStringNotContainsString('Unpaid', $roster);

        // Nor is any family badged "Unpaid" on the screens.
        $list = $this->getJson($this->url('', $camp))->assertOk()->assertJsonPath('meta.payment.enabled', false);

        foreach ($list->json('data.data') as $item) {
            $this->assertNull($item['payment_state']);
            $this->assertNull($item['settled']);
        }

        foreach ($this->getJson($this->url('/roster', $camp))->assertOk()->json('data.data') as $person) {
            $this->assertNull($person['payment_status']);
            $this->assertSame('', $person['payment']);
        }

        // A festival form with both switches off after the day still reconciles from its
        // payment columns: what counts is the payment block, not the switches.
        $after = $this->makeForm($this->masjid, $campSettings + ['payment' => ['online' => false, 'staffCodes' => false]]);
        $header = str_getcsv((string) strtok($this->get($this->url('/export', $after))->assertOk()->streamedContent(), "\n"));

        $this->assertSame('Registration no.', $header[4]);
        $this->getJson($this->url('', $after))->assertOk()->assertJsonPath('meta.payment.enabled', true);
    }

    // --------------------------------------------------------------- tenancy

    #[Test]
    public function every_door_route_is_a_404_across_tenants_a_403_for_another_masjids_admin_and_a_401_signed_out(): void
    {
        // Theirs, written before any request binds a tenant.
        [$theirCode] = FormStaffCode::issue($this->otherForm, 'Bilal Khan', now()->addDay());
        $theirCash = $this->cashRow($theirCode, 2, form: $this->otherForm);
        $theirCard = $this->onlineUnpaid([], [], $this->otherForm);
        self::$pages[$theirCard->stripe_checkout_session_id] = 'open';

        $doors = fn (FormResponse $row) => [
            ['POST', "/{$row->id}/collect"],
            ['DELETE', "/{$row->id}/collect"],
            ['POST', "/{$row->id}/take-cash"],
            ['POST', "/{$row->id}/mark-paid-external"],
        ];

        foreach ([$theirCash, $theirCard] as $theirs) {
            foreach ($doors($theirs) as [$method, $suffix]) {
                // Our masjid in the path, their form.
                $this->json($method, $this->url($suffix, $this->otherForm, $this->masjid))->assertNotFound();

                // Our masjid and our form in the path, their response.
                $this->json($method, $this->url($suffix))->assertNotFound();
            }
        }

        $this->getJson($this->url('/cash-totals', $this->otherForm, $this->masjid))->assertNotFound();

        // Another masjid's admin, on that masjid's own URLs.
        Sanctum::actingAs($this->makeAdminFor($this->masjid));

        foreach ($doors($theirCard) as [$method, $suffix]) {
            $this->json($method, $this->url($suffix, $this->otherForm, $this->otherMasjid))->assertForbidden();
        }

        $this->getJson($this->url('/cash-totals', $this->otherForm, $this->otherMasjid))->assertForbidden();

        // Nobody signed in.
        $this->app['auth']->forgetGuards();

        foreach ($doors($theirCard) as [$method, $suffix]) {
            $this->json($method, $this->url($suffix, $this->otherForm, $this->otherMasjid))->assertUnauthorized();
        }

        $this->getJson($this->url('/cash-totals', $this->otherForm, $this->otherMasjid))->assertUnauthorized();

        // Nothing of theirs moved, and none of their card pages was touched.
        $this->assertNull($theirCash->fresh()->collected_at);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $theirCard->fresh()->payment_status);
        $this->assertNull($theirCard->fresh()->marked_paid_by_user_id);
        $this->assertSame([], self::$expired);
        Mail::assertNothingQueued();
    }

    // ---------------------------------------------------------------- helpers

    private function assertStillWaitingOnStripe(FormResponse $row): void
    {
        $fresh = $row->fresh();

        $this->assertSame(FormResponse::METHOD_ONLINE, $fresh->payment_method);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $fresh->payment_status);
        $this->assertNull($fresh->paid_at);
        $this->assertNull($fresh->marked_paid_by_user_id);
    }

    private function url(string $suffix = '', ?Form $form = null, ?Masjid $masjid = null): string
    {
        $form ??= $this->form;
        $masjid ??= $form->masjid_id === $this->otherMasjid->id ? $this->otherMasjid : $this->masjid;

        return "/api/admin/masjids/{$masjid->id}/forms/{$form->id}/responses{$suffix}";
    }

    /** A registration as the submit writes it — $15 per attendee — with the money leg given. */
    private function row(array $money = [], array $attributes = [], ?Form $form = null, int $attendees = 2): FormResponse
    {
        $form ??= $this->form;

        $row = new FormResponse(array_merge([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => [
                'fullName' => 'Amal Yusuf',
                'email' => 'amal@example.com',
                'attendees' => array_map(fn (int $n) => ['attendeeName' => "Guest {$n}"], range(1, $attendees)),
            ],
            'respondent_name' => 'Amal Yusuf',
            'respondent_email' => 'amal@example.com',
            'entry_count' => $attendees,
            'amount_due' => 15 * $attendees,
            'status' => 'new',
            'submitted_at' => now(),
        ], $attributes));

        $row->forceFill(array_merge(['amount_due_minor' => 1500 * $attendees, 'currency' => 'usd'], $money))->save();

        return $row->fresh();
    }

    /** A walk-up the gate settled as cash on $code. */
    private function cashRow(FormStaffCode $code, int $attendees = 2, array $attributes = [], ?Form $form = null): FormResponse
    {
        $row = $this->row([], $attributes, $form ?? $this->form, $attendees);

        $this->assertTrue($row->settleCash($code), 'the gate settles a walk-up as cash');

        return $row->fresh();
    }

    /** A card registration waiting on Stripe, with a $1.20 card-fee cover and a page opened. */
    private function onlineUnpaid(array $money = [], array $attributes = [], ?Form $form = null): FormResponse
    {
        return $this->row(array_merge([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'fee_covered_minor' => 120,
            'total_minor' => 3120,
            'idempotency_key' => 'form_response_' . uniqid(),
            'stripe_checkout_session_id' => 'cs_test_' . uniqid(),
        ], $money), $attributes, $form);
    }

    private function onlinePaid(array $attributes = []): FormResponse
    {
        return $this->row([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'fee_covered_minor' => 120,
            'total_minor' => 3120,
            'idempotency_key' => 'form_response_' . uniqid(),
            'stripe_checkout_session_id' => 'cs_paid_' . uniqid(),
            'stripe_payment_intent_id' => 'pi_' . uniqid(),
        ], $attributes);
    }

    /** The Wix fallback's row: no money leg and no cents snapshot, on a form that charges. */
    private function wixRow(array $attributes = []): FormResponse
    {
        return $this->row(['amount_due_minor' => null, 'currency' => null], $attributes);
    }

    /** A registration on a form that charges nothing. */
    private function freeRow(): FormResponse
    {
        $free = $this->makeForm($this->masjid, $this->freeSettings());

        return $this->row(['amount_due_minor' => null, 'currency' => null], ['amount_due' => null], $free);
    }

    /** @return array<string,mixed> */
    private function freeSettings(): array
    {
        return [
            'identity' => ['name' => 'fullName', 'email' => 'email'],
            'notifyEmails' => ['festival-office@mec.test'],
        ];
    }

    /** The festival's shape by default: $15 per attendee, card and codes on, a group link. */
    private function makeForm(Masjid $masjid, ?array $settings = null): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => ['sections' => [
                ['id' => 'contact', 'title' => 'You', 'fields' => [
                    ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                ]],
                ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'maxEntries' => 10, 'fields' => [
                    ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => $settings ?? [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'notifyEmails' => ['festival-office@mec.test'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true, 'staffCodes' => true, 'allowFeeCoverage' => true],
                'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE,
                'whatsappLabel' => 'Join the festival group',
            ],
            'is_active' => true,
        ]);
    }

    private function makeMasjid(string $stripeAccount): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        // Where a card page would live: closeOpenSession() reads it off the masjid.
        $masjid->forceFill(['stripe_account_id' => $stripeAccount])->save();

        return $masjid;
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /**
     * Stripe, as far as the service can tell: each page's status is what the test put in
     * self::$pages. Closing one records it, unless self::$stripe says the payer won the
     * race, the close is refused outright, or Stripe cannot be reached at all.
     */
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
                    if (FormResponsesMoneyAdminTest::$stripe === 'down') {
                        throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                    }

                    if (FormResponsesMoneyAdminTest::$stripe === 'garbled') {
                        throw new \Stripe\Exception\UnexpectedValueException('Could not read the reply from Stripe.');
                    }

                    $status = FormResponsesMoneyAdminTest::$pages[$sessionId] ?? 'expired';

                    return ['status' => $status, 'url' => $status === 'open' ? "https://checkout.stripe.test/pay/{$sessionId}" : null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    switch (FormResponsesMoneyAdminTest::$stripe) {
                        case 'paid':
                            FormResponsesMoneyAdminTest::$pages[$sessionId] = 'complete';

                            throw \Stripe\Exception\InvalidRequestException::factory('This Checkout Session is not in an expirable state.');
                        case 'still-open':
                            throw \Stripe\Exception\InvalidRequestException::factory('Refused.');
                    }

                    FormResponsesMoneyAdminTest::$expired[] = $sessionId;
                    FormResponsesMoneyAdminTest::$pages[$sessionId] = 'expired';
                }
            };
        });
    }
}
