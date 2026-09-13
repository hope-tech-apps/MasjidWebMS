<?php

namespace Tests\Feature;

use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Donation;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Services\Stripe\FormChargeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The webhook for a registration charged through another organisation's account
 * (DECISIONS.md 2026-09-15, D6, D7, D10): BISS Sunday School's card payments land on
 * Burlington Masjid's Connect account, whose own Stripe users can read and write metadata.
 *
 * What is pinned:
 *
 *  - a linked session or payment intent settles the BISS row once, emailed as BISS, with
 *    the payment line naming Burlington, and books no donation;
 *  - every mismatch records NOTHING and never flips the row: another account (the holder's
 *    NEW account included), a session the app did not record, a wrong amount, a wrong
 *    currency, a made-up reference, a session carrying another row's reference (refused
 *    as that row's session), a payment intent carrying another row's reference with this
 *    row's amount (refused as that row's amount; with that row's own amount and account it
 *    IS that row's payment, because the reference is the routing key);
 *  - a session Adaptive Pricing localised is matched on Stripe's currency_conversion
 *    totals, and a redelivered payment intent already recorded changes nothing;
 *  - a pinned row reached by its uuid never takes the unpinned path, even on an account
 *    whose organisation owns the row, and on an account nobody holds any more it is still
 *    decided by the pin and the amount;
 *  - the payment is still recorded after an unlink, and after the holder is offboarded;
 *  - account.application.deauthorized switches the holder's card payments off, so BISS's
 *    card route fails closed, and an account.updated Stripe created before it never turns
 *    them back on;
 *  - a refund or dispute of the payment RECORDED on a paid row flags it, with the amount
 *    refunded so far, and never changes its payment status; anything else, whatever
 *    reference it carries, flags nothing.
 *
 * Every event is signed and posted through the real route.
 */
class FormLinkedWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECT_SECRET = 'whsec_linked_connect';

    private const HOLDER_ACCOUNT = 'acct_1BurlingtonLinked';

    private const OTHER_ACCOUNT = 'acct_1SomeoneElse';

    private const REF = 'fcr_0123456789abcdef0123456789abcdef';

    private const SESSION = 'cs_linked_1';

    private const INTENT = 'pi_linked_1';

    private const PAYER = 'amal@example.com';

    private const OFFICE = 'biss-office@example.org';

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
            'services.stripe.webhook_secret' => 'whsec_linked_platform',
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
        ]);

        Mail::fake();
        Log::spy();

        $this->holder = $this->makeOrg('Burlington Masjid', ['stripe_account_id' => self::HOLDER_ACCOUNT, 'stripe_charges_enabled' => true, 'stripe_payouts_enabled' => true]);
        $this->biss = $this->makeOrg('Burlington Islamic Sunday School');
        DB::table('masjids')->where('id', $this->biss->id)->update(['parent_id' => $this->holder->id, 'forms_card_via_masjid_id' => $this->holder->id]);

        $this->form = $this->makeForm($this->biss);
    }

    // ------------------------------------------------------------ settling

    #[Test]
    public function a_linked_session_settles_the_bisss_row_once_emailed_as_biss_and_books_no_donation(): void
    {
        $row = $this->pinnedRow();

        $this->postWebhook($this->completed($row))->assertOk();
        $this->postWebhook($this->succeeded($row))->assertOk();

        $row->refresh();
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
        $this->assertSame(self::INTENT, $row->stripe_payment_intent_id);
        $this->assertSame(self::SESSION, $row->stripe_checkout_session_id);
        $this->assertSame(self::HOLDER_ACCOUNT, $row->charge_account_id, 'pins are never cleared');
        $this->assertSame(0, Donation::withoutGlobalScopes()->count(), 'a form charge never books a donation');

        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Mail::assertQueued(FormResponseSubmitted::class, 1);
        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->hasTo(self::PAYER)
            && $mail->masjidName === 'Burlington Islamic Sunday School'
            && $mail->paymentLine === 'Paid $103.20 by card (processed by Burlington Masjid)');
        Mail::assertQueued(FormResponseSubmitted::class, fn (FormResponseSubmitted $mail) => $mail->hasTo(self::OFFICE)
            && $mail->paymentLine === 'Paid $103.20 by card (processed by Burlington Masjid)');

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function the_payment_intent_alone_settles_it_when_it_lands_first(): void
    {
        $row = $this->pinnedRow();

        $this->postWebhook($this->succeeded($row))->assertOk();
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);

        $this->postWebhook($this->completed($row))->assertOk();
        Mail::assertQueued(FormSubmissionReceipt::class, 1);
    }

    // ------------------------------------------------------------ mismatches

    #[Test]
    public function an_event_on_any_account_but_the_pin_records_nothing(): void
    {
        $this->makeOrg('Someone Else', ['stripe_account_id' => self::OTHER_ACCOUNT, 'stripe_charges_enabled' => true]);
        $row = $this->pinnedRow();

        $this->postWebhook($this->completed($row, ['account' => self::OTHER_ACCOUNT]))->assertOk();
        $this->postWebhook($this->succeeded($row, ['account' => 'acct_1Nobody']))->assertOk();
        $this->postWebhook($this->succeeded($row, ['account' => null]))->assertOk();

        $this->assertNothingRecorded($row);
        $this->assertRefused('account', 2);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'linked form-payment event arrived without a connected account'))
            ->once();
    }

    #[Test]
    public function a_session_the_app_did_not_record_is_never_adopted_and_settles_nothing(): void
    {
        // A page someone opened in the holder's dashboard, carrying a real BISS reference.
        $row = $this->pinnedRow();
        $this->postWebhook($this->completed($row, ['id' => 'cs_made_in_the_dashboard']))->assertOk();

        $this->assertNothingRecorded($row);
        $this->assertSame(self::SESSION, $row->fresh()->stripe_checkout_session_id);

        // A row whose page id was never saved adopts nothing either.
        $bare = $this->pinnedRow(['stripe_checkout_session_id' => null, 'charge_ref' => 'fcr_' . str_repeat('b', 32)]);
        $this->postWebhook($this->completed($bare, ['id' => 'cs_foreign']))->assertOk();

        $this->assertNull($bare->fresh()->stripe_checkout_session_id, 'a pinned row never adopts a session id');
        $this->assertNothingRecorded($bare);
        $this->assertRefused('session', 2);
    }

    #[Test]
    public function a_wrong_amount_or_currency_records_nothing_on_either_event(): void
    {
        $row = $this->pinnedRow();

        $this->postWebhook($this->completed($row, ['amount' => 100]))->assertOk();
        $this->postWebhook($this->succeeded($row, ['amount' => 100]))->assertOk();
        $this->postWebhook($this->succeeded($row, ['amount' => 10320 + 1]))->assertOk();
        $this->postWebhook($this->completed($row, ['currency' => 'cad']))->assertOk();
        $this->postWebhook($this->succeeded($row, ['currency' => 'cad']))->assertOk();
        $this->postWebhook($this->succeeded($row, ['currency' => null]))->assertOk();

        $this->assertNothingRecorded($row);
        $this->assertRefused('amount', 3);
        $this->assertRefused('currency', 3);
    }

    #[Test]
    public function a_made_up_reference_or_another_rows_reference_records_nothing(): void
    {
        $row = $this->pinnedRow();
        $sibling = $this->pinnedRow([
            'charge_ref' => 'fcr_' . str_repeat('c', 32),
            'stripe_checkout_session_id' => 'cs_linked_2',
            'fee_covered_minor' => 0,
            'total_minor' => 10000,
        ]);

        $this->postWebhook($this->succeeded($row, ['ref' => 'fcr_' . str_repeat('f', 32)]))->assertOk();

        // The sibling's reference, the sibling's amount, but this row's session: refused as the sibling's session.
        $this->postWebhook($this->completed($sibling, ['id' => self::SESSION]))->assertOk();

        $this->assertNothingRecorded($row);
        $this->assertNothingRecorded($sibling);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'charge reference no registration carries')
                && ($context['account'] ?? null) === self::HOLDER_ACCOUNT)
            ->once();
        $this->assertRefused('session', 1);
    }

    #[Test]
    public function a_pinned_row_reached_by_its_uuid_never_takes_the_unpinned_path(): void
    {
        // BISS was unlinked and onboarded its own account afterwards: the unpinned path
        // would accept an event on BISS's own account for BISS's own row.
        DB::table('masjids')->where('id', $this->biss->id)->update([
            'forms_card_via_masjid_id' => null,
            'stripe_account_id' => 'acct_1BissOwn',
            'stripe_charges_enabled' => true,
        ]);
        $row = $this->pinnedRow();

        $uuidOnly = ['ref' => null, 'uuid' => true];
        $this->postWebhook($this->succeeded($row, $uuidOnly + ['account' => 'acct_1BissOwn']))->assertOk();
        $this->postWebhook($this->completed($row, $uuidOnly + ['account' => 'acct_1BissOwn']))->assertOk();

        // And on the holder's account the uuid names a row the holder does not own.
        $this->postWebhook($this->succeeded($row, $uuidOnly))->assertOk();

        $this->assertNothingRecorded($row);
        $this->assertRefused('account', 2);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'does not belong to the organisation')
                && ($context['account'] ?? null) === self::HOLDER_ACCOUNT)
            ->once();
    }

    // ------------------------------------------------------ after the link changes

    #[Test]
    public function a_payment_on_a_pinned_page_is_still_recorded_after_an_unlink_and_after_the_holder_is_offboarded(): void
    {
        $row = $this->pinnedRow();
        DB::table('masjids')->where('id', $this->biss->id)->update(['forms_card_via_masjid_id' => null]);

        $this->postWebhook($this->completed($row))->assertOk();
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);

        $other = $this->pinnedRow(['charge_ref' => 'fcr_' . str_repeat('d', 32), 'stripe_checkout_session_id' => 'cs_linked_3']);
        $this->holder->delete();

        $this->postWebhook($this->succeeded($other, ['id' => 'pi_linked_3']))->assertOk();
        $this->assertSame(FormResponse::PAYMENT_PAID, $other->fresh()->payment_status);

        // Emailed as BISS both times: BISS is live.
        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->masjidName === 'Burlington Islamic Sunday School');
        Mail::assertQueued(FormSubmissionReceipt::class, 2);
    }

    // ------------------------------------------------------------ disconnect (D7)

    #[Test]
    public function a_holder_disconnecting_the_platform_switches_its_card_payments_off_and_bisss_fails_closed(): void
    {
        $bystander = $this->makeOrg('Bystander', ['stripe_account_id' => self::OTHER_ACCOUNT, 'stripe_charges_enabled' => true, 'stripe_payouts_enabled' => true]);
        $this->assertNotNull(FormChargeAccount::for($this->biss->fresh()));

        $this->postWebhook([
            'id' => 'evt_' . Str::random(24),
            'type' => 'account.application.deauthorized',
            'account' => self::HOLDER_ACCOUNT,
            'data' => ['object' => ['id' => 'ca_platform', 'object' => 'application']],
        ])->assertOk();

        $holder = $this->holder->fresh();
        $this->assertFalse((bool) $holder->stripe_charges_enabled);
        $this->assertFalse((bool) $holder->stripe_payouts_enabled);
        $this->assertSame(self::HOLDER_ACCOUNT, $holder->stripe_account_id, 'the account id stays: pinned pages still name it');
        $this->assertNotNull($holder->stripe_deauthorized_at, 'the disconnection is recorded');
        $this->assertNull(FormChargeAccount::for($this->biss->fresh()), 'BISS card payment fails closed');
        $this->assertFalse($holder->canAcceptDonations());

        $this->assertTrue((bool) $bystander->fresh()->stripe_charges_enabled, 'nobody else is touched');
        $this->assertTrue((bool) $bystander->fresh()->stripe_payouts_enabled);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'disconnected the platform')
                && ($context['account'] ?? null) === self::HOLDER_ACCOUNT
                && ($context['masjid_ids'] ?? null) === [$this->holder->id])
            ->once();
    }

    // ---------------------------------------------------------- refunds and disputes (D10)

    #[Test]
    public function an_account_update_created_before_the_disconnect_never_turns_card_payments_back_on_and_a_later_one_does(): void
    {
        $bystander = $this->makeOrg('Bystander', ['stripe_account_id' => self::OTHER_ACCOUNT, 'stripe_charges_enabled' => false]);
        $disconnectedAt = time() - 600;

        $this->postWebhook([
            'id' => 'evt_' . Str::random(24),
            'type' => 'account.application.deauthorized',
            'created' => $disconnectedAt,
            'account' => self::HOLDER_ACCOUNT,
            'data' => ['object' => ['id' => 'ca_platform', 'object' => 'application']],
        ])->assertOk();

        // Queued before the disconnect and delivered after it; and one from the same second.
        $this->postWebhook($this->accountUpdated(self::HOLDER_ACCOUNT, $disconnectedAt - 300, true))->assertOk();
        $this->postWebhook($this->accountUpdated(self::HOLDER_ACCOUNT, $disconnectedAt, true))->assertOk();

        $holder = $this->holder->fresh();
        $this->assertFalse((bool) $holder->stripe_charges_enabled);
        $this->assertFalse((bool) $holder->stripe_payouts_enabled);
        $this->assertNotNull($holder->stripe_deauthorized_at);
        $this->assertNull(FormChargeAccount::for($this->biss->fresh()), 'BISS card payment stays off');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'arrived late')
                && ($context['account'] ?? null) === self::HOLDER_ACCOUNT)
            ->twice();

        // Connected again: an update Stripe created after the disconnection.
        $this->postWebhook($this->accountUpdated(self::HOLDER_ACCOUNT, $disconnectedAt + 300, true))->assertOk();

        $holder = $this->holder->fresh();
        $this->assertTrue((bool) $holder->stripe_charges_enabled);
        $this->assertNull($holder->stripe_deauthorized_at);
        $this->assertNotNull(FormChargeAccount::for($this->biss->fresh()));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'follow Stripe again'))
            ->once();

        // From then on it follows Stripe as before, like every account never disconnected.
        $this->postWebhook($this->accountUpdated(self::HOLDER_ACCOUNT, $disconnectedAt - 900, false))->assertOk();
        $this->assertFalse((bool) $this->holder->fresh()->stripe_charges_enabled);

        $this->postWebhook($this->accountUpdated(self::OTHER_ACCOUNT, $disconnectedAt - 900, true))->assertOk();
        $this->assertTrue((bool) $bystander->fresh()->stripe_charges_enabled);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'arrived late'))
            ->twice();
    }

    #[Test]
    public function a_refund_or_dispute_of_the_recorded_payment_flags_the_row_with_the_amount_refunded_and_never_changes_its_payment_status(): void
    {
        $row = $this->pinnedRow();
        $this->postWebhook($this->succeeded($row))->assertOk();

        // The card fee back: a PARTIAL refund.
        $this->postWebhook($this->chargeEvent('charge.refunded', ['payment_intent' => self::INTENT, 'amount_refunded' => 320, 'refunded' => false]))->assertOk();

        $fresh = $row->fresh();
        $this->assertSame(FormResponse::CHARGE_FLAG_REFUNDED, $fresh->charge_flag);
        $this->assertSame(320, $fresh->charge_refunded_minor, 'a partial refund says how much');
        $this->assertNotNull($fresh->charge_flagged_at);
        $this->assertSame(FormResponse::PAYMENT_PAID, $fresh->payment_status, 'a flag never changes payment_status');

        // Then the rest: the full refund raises it, and a redelivered partial one never lowers it.
        $this->postWebhook($this->chargeEvent('charge.refunded', ['payment_intent' => self::INTENT, 'amount_refunded' => 10320, 'refunded' => true]))->assertOk();
        $this->assertSame(10320, $row->fresh()->charge_refunded_minor);
        $this->postWebhook($this->chargeEvent('charge.refunded', ['payment_intent' => self::INTENT, 'amount_refunded' => 320, 'refunded' => false]))->assertOk();
        $this->assertSame(10320, $row->fresh()->charge_refunded_minor);

        // A dispute outranks a refund, and a later refund never overwrites it.
        $this->postWebhook($this->chargeEvent('charge.dispute.created', ['payment_intent' => self::INTENT, 'charge' => 'ch_linked_1']))->assertOk();
        $this->assertSame(FormResponse::CHARGE_FLAG_DISPUTED, $row->fresh()->charge_flag);
        $this->postWebhook($this->chargeEvent('charge.refunded', ['payment_intent' => self::INTENT, 'amount_refunded' => 10320, 'refunded' => true]))->assertOk();
        $this->assertSame(FormResponse::CHARGE_FLAG_DISPUTED, $row->fresh()->charge_flag);
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'was refunded there') && ($context['refunded_minor'] ?? null) === 320)
            ->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'was refunded there') && ($context['refunded_minor'] ?? null) === 10320)
            ->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'was disputed')
                && ($context['form_response_id'] ?? null) === $row->id)
            ->once();
    }

    #[Test]
    public function a_refund_or_dispute_that_is_not_the_recorded_payment_flags_nothing_whatever_reference_it_carries(): void
    {
        // An unpaid row, and charges made in the holder's dashboard carrying its copied reference.
        $unpaid = $this->pinnedRow();

        $this->postWebhook($this->chargeEvent('charge.refunded', [
            'payment_intent' => 'pi_made_in_the_dashboard',
            'metadata' => ['form_charge_ref' => $unpaid->charge_ref],
            'amount_refunded' => 100,
            'refunded' => true,
        ]))->assertOk();
        $this->postWebhook($this->chargeEvent('charge.dispute.created', [
            'payment_intent' => ['id' => 'pi_made_in_the_dashboard', 'metadata' => ['form_charge_ref' => $unpaid->charge_ref]],
        ]))->assertOk();

        $fresh = $unpaid->fresh();
        $this->assertNull($fresh->charge_flag);
        $this->assertNull($fresh->charge_refunded_minor);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $fresh->payment_status);

        // A paid row, and a payment intent that is not the one recorded on it.
        $paid = $this->pinnedRow([
            'charge_ref' => 'fcr_' . str_repeat('9', 32),
            'stripe_checkout_session_id' => 'cs_linked_9',
            'payment_status' => FormResponse::PAYMENT_PAID,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_A',
        ]);

        $this->postWebhook($this->chargeEvent('charge.refunded', [
            'payment_intent' => ['id' => 'pi_B', 'metadata' => ['form_charge_ref' => $paid->charge_ref]],
            'amount_refunded' => 10320,
            'refunded' => true,
        ]))->assertOk();

        $this->assertNull($paid->fresh()->charge_flag);
        $this->assertNull($paid->fresh()->charge_refunded_minor);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'not the payment recorded on it'))
            ->times(3);
        Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, 'was refunded there') || str_contains($message, 'was disputed'));
    }

    #[Test]
    public function a_payment_intent_carrying_another_rows_reference_is_held_to_that_rows_amount(): void
    {
        $row = $this->pinnedRow();
        $sibling = $this->pinnedRow([
            'charge_ref' => 'fcr_' . str_repeat('c', 32),
            'stripe_checkout_session_id' => 'cs_linked_2',
            'fee_covered_minor' => 0,
            'total_minor' => 10000,
        ]);

        // This row's amount, the sibling's reference: routed to the sibling, whose total it is not.
        $this->postWebhook($this->succeeded($row, ['ref' => $sibling->charge_ref]))->assertOk();

        $this->assertNothingRecorded($row);
        $this->assertNothingRecorded($sibling);
        $this->assertRefused('amount', 1);

        // With the sibling's own amount on the pin it is the sibling's payment: the reference routes.
        $this->postWebhook($this->succeeded($sibling, ['id' => 'pi_sibling']))->assertOk();
        $this->assertSame(FormResponse::PAYMENT_PAID, $sibling->fresh()->payment_status);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->fresh()->payment_status);
    }

    #[Test]
    public function a_session_localised_by_adaptive_pricing_settles_on_its_source_total_and_its_redelivered_payment_intent_changes_nothing(): void
    {
        $row = $this->pinnedRow();
        $localised = ['currency' => 'cad', 'amount' => 14000];

        $this->postWebhook($this->completed($row, $localised + ['extra' => ['currency_conversion' => [
            'amount_subtotal' => 10320,
            'amount_total' => 10320,
            'fx_rate' => '1.3566',
            'source_currency' => 'usd',
        ]]]))->assertOk();
        $this->postWebhook($this->succeeded($row, $localised))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);
        $this->assertSame(self::INTENT, $row->fresh()->stripe_payment_intent_id);
        Mail::assertQueued(FormSubmissionReceipt::class, 1);
        Log::shouldNotHaveReceived('warning');

        // The conversion block is still held to the row's total.
        $wrong = $this->pinnedRow(['charge_ref' => 'fcr_' . str_repeat('5', 32), 'stripe_checkout_session_id' => 'cs_linked_5']);
        $this->postWebhook($this->completed($wrong, $localised + ['payment_intent' => 'pi_linked_5', 'extra' => ['currency_conversion' => [
            'amount_total' => 100,
            'source_currency' => 'usd',
        ]]]))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_UNPAID, $wrong->fresh()->payment_status);
        $this->assertRefused('amount', 1);

        // A localised payment intent landing BEFORE its session is refused; the session settles the row.
        $early = $this->pinnedRow(['charge_ref' => 'fcr_' . str_repeat('6', 32), 'stripe_checkout_session_id' => 'cs_linked_6']);
        $this->postWebhook($this->succeeded($early, $localised + ['id' => 'pi_linked_6']))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_UNPAID, $early->fresh()->payment_status);
        $this->assertRefused('currency', 1);

        $this->postWebhook($this->completed($early, $localised + ['payment_intent' => 'pi_linked_6', 'extra' => ['currency_conversion' => [
            'amount_total' => 10320,
            'source_currency' => 'usd',
        ]]]))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_PAID, $early->fresh()->payment_status);
        $this->assertSame('pi_linked_6', $early->fresh()->stripe_payment_intent_id);
    }

    #[Test]
    public function a_payment_is_matched_to_the_account_its_page_was_pinned_to_not_the_one_the_holder_holds_now(): void
    {
        $row = $this->pinnedRow();
        $uuidRow = $this->pinnedRow(['charge_ref' => 'fcr_' . str_repeat('8', 32), 'stripe_checkout_session_id' => 'cs_linked_8']);

        // Burlington re-onboarded: its pages from before still live on the old account.
        $this->holder->forceFill(['stripe_account_id' => 'acct_1BurlingtonNewHook'])->save();

        $this->postWebhook($this->completed($row, ['account' => 'acct_1BurlingtonNewHook']))->assertOk();

        // A uuid-only payment intent on the old account, which no organisation holds any more:
        // decided by the pin, and still held to the amount.
        $this->postWebhook($this->succeeded($uuidRow, ['ref' => null, 'uuid' => true, 'id' => 'pi_linked_8', 'amount' => 100]))->assertOk();

        $this->assertNothingRecorded($row);
        $this->assertNothingRecorded($uuidRow);
        $this->assertRefused('account', 1);
        $this->assertRefused('amount', 1);

        $this->postWebhook($this->completed($row))->assertOk();
        $this->postWebhook($this->succeeded($uuidRow, ['ref' => null, 'uuid' => true, 'id' => 'pi_linked_8']))->assertOk();

        $this->assertSame(FormResponse::PAYMENT_PAID, $row->fresh()->payment_status);
        $this->assertSame(FormResponse::PAYMENT_PAID, $uuidRow->fresh()->payment_status);
        $this->assertSame('pi_linked_8', $uuidRow->fresh()->stripe_payment_intent_id);
        Mail::assertQueued(FormSubmissionReceipt::class, 2);
    }

    #[Test]
    public function a_refund_on_another_account_or_of_a_charge_that_is_not_a_pinned_rows_flags_nothing(): void
    {
        $row = $this->pinnedRow();
        $this->postWebhook($this->succeeded($row))->assertOk();

        $this->postWebhook($this->chargeEvent('charge.refunded', ['payment_intent' => self::INTENT], self::OTHER_ACCOUNT))->assertOk();
        $this->assertNull($row->fresh()->charge_flag);

        // An unpinned row paid on its own organisation's account: its admins refund it themselves.
        $own = $this->makeOrg('Own Account Masjid', ['stripe_account_id' => 'acct_1OwnAccount', 'stripe_charges_enabled' => true]);
        $unpinned = $this->pinnedRow([
            'charge_account_id' => null, 'charge_masjid_id' => null, 'charge_ref' => null, 'charge_expires_at' => null,
            'stripe_payment_intent_id' => 'pi_own_1', 'stripe_checkout_session_id' => 'cs_own_1',
        ], $this->makeForm($own));
        $this->postWebhook($this->chargeEvent('charge.refunded', ['payment_intent' => 'pi_own_1'], 'acct_1OwnAccount'))->assertOk();
        $this->assertNull($unpinned->fresh()->charge_flag);

        // A donation's refund: nothing named, nothing said.
        $this->postWebhook($this->chargeEvent('charge.refunded', ['payment_intent' => 'pi_some_donation'], self::HOLDER_ACCOUNT))->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'arrived on an account its charge was not taken on'))
            ->once();
        Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, 'was refunded there'));
    }

    // ------------------------------------------------------------------- helpers

    private function assertNothingRecorded(FormResponse $row): void
    {
        $fresh = $row->fresh();

        $this->assertSame(FormResponse::PAYMENT_UNPAID, $fresh->payment_status);
        $this->assertNull($fresh->paid_at);
        $this->assertNull($fresh->stripe_payment_intent_id);
        Mail::assertNothingOutgoing();
    }

    private function assertRefused(string $reason, int $times): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'did not match the page')
                && ($context['reason'] ?? null) === $reason)
            ->times($times);
    }

    /** A BISS card row whose page was opened on Burlington's account: $100 plus the required $3.20 card fee. */
    private function pinnedRow(array $money = [], ?Form $form = null): FormResponse
    {
        $form ??= $this->form;

        $row = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['fullName' => 'Amal Yusuf', 'email' => self::PAYER],
            'respondent_name' => 'Amal Yusuf',
            'respondent_email' => self::PAYER,
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
            'fee_covered_minor' => 320,
            'total_minor' => 10320,
            'idempotency_key' => 'form_response_' . Str::uuid(),
            'stripe_checkout_session_id' => self::SESSION,
            'charge_account_id' => self::HOLDER_ACCOUNT,
            'charge_masjid_id' => $this->holder->id,
            'charge_ref' => self::REF,
            'charge_expires_at' => now()->addMinutes(31),
        ], $money))->save();

        return $row->fresh();
    }

    /** @param  array{id?:string,account?:?string,amount?:int,currency?:?string,ref?:?string,uuid?:bool,payment_intent?:string}  $o */
    private function completed(FormResponse $row, array $o = []): array
    {
        return $this->event('checkout.session.completed', $o, array_merge([
            'id' => $o['id'] ?? $row->stripe_checkout_session_id ?? self::SESSION,
            'object' => 'checkout.session',
            'mode' => 'payment',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => $o['amount'] ?? (int) $row->total_minor,
            'currency' => array_key_exists('currency', $o) ? $o['currency'] : 'usd',
            'payment_intent' => $o['payment_intent'] ?? self::INTENT,
            'metadata' => $this->metadata($row, $o),
        ], $o['extra'] ?? []));
    }

    private function accountUpdated(string $account, int $created, bool $enabled): array
    {
        return [
            'id' => 'evt_' . Str::random(24),
            'type' => 'account.updated',
            'created' => $created,
            'account' => $account,
            'data' => ['object' => [
                'id' => $account,
                'object' => 'account',
                'charges_enabled' => $enabled,
                'payouts_enabled' => $enabled,
            ]],
        ];
    }

    private function succeeded(FormResponse $row, array $o = []): array
    {
        return $this->event('payment_intent.succeeded', $o, [
            'id' => $o['id'] ?? self::INTENT,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => $o['amount'] ?? (int) $row->total_minor,
            'amount_received' => $o['amount'] ?? (int) $row->total_minor,
            'currency' => array_key_exists('currency', $o) ? $o['currency'] : 'usd',
            'metadata' => $this->metadata($row, $o),
        ]);
    }

    /** What a linked page carries: the reference and the form, never the uuid (unless a test adds it). */
    private function metadata(FormResponse $row, array $o): array
    {
        $metadata = ['form_id' => (string) $row->form_id];
        $ref = array_key_exists('ref', $o) ? $o['ref'] : $row->charge_ref;

        if ($ref !== null) {
            $metadata['form_charge_ref'] = $ref;
        }

        if (! empty($o['uuid'])) {
            $metadata['form_response_uuid'] = $row->uuid;
            $metadata['masjid_id'] = (string) $row->masjid_id;
        }

        return $metadata;
    }

    private function chargeEvent(string $type, array $object, string $account = self::HOLDER_ACCOUNT): array
    {
        return [
            'id' => 'evt_' . Str::random(24),
            'type' => $type,
            'account' => $account,
            'data' => ['object' => array_merge([
                'id' => $type === 'charge.refunded' ? 'ch_linked_1' : 'dp_linked_1',
                'object' => $type === 'charge.refunded' ? 'charge' : 'dispute',
                'amount' => 10320,
                'currency' => 'usd',
            ], $object)],
        ];
    }

    private function event(string $type, array $o, array $object): array
    {
        return [
            'id' => 'evt_' . Str::random(24),
            'type' => $type,
            'account' => array_key_exists('account', $o) ? $o['account'] : self::HOLDER_ACCOUNT,
            'data' => ['object' => $object],
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

    private function makeForm(Masjid $masjid): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'enrol-' . uniqid(),
            'name' => 'Registration 2026',
            'schema' => ['sections' => [['id' => 'contact', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ]]]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'notifyEmails' => [self::OFFICE],
                'fee' => ['amount' => 100, 'currency' => 'USD'],
                'payment' => ['online' => true, 'requireFeeCoverage' => true],
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
}
