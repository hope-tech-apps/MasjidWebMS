<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The money leg on a form response, at the model: every way a row becomes paid,
 * settled and collected, and every way it must refuse to. Also the Form helpers
 * those rules read.
 *
 * The festival brief's blockers, as they land here:
 *
 *  - never free by accident: a $0 settlement throws, a payment switch on a form
 *    with no price does nothing, and a fee form's $0 row is not settled;
 *  - money rows are re-triaged with a name against them (stampStatusChange);
 *  - double payment at the gate: a staff code never converts an online row, and
 *    a card payment landing on a cash row is recorded but never flips it;
 *  - the Wix fallback: NULL payment columns on a fee form are NOT settled, and
 *    "Mark paid (external)" is what settles them.
 *
 * Each move re-reads the row under a lock and is stamped by the first press only;
 * the "second press" cases below are what that buys.
 */
class FormResponseSettlementTest extends TestCase
{
    use RefreshDatabase;

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

        $this->masjid = $this->makeMasjid();
        $this->form = $this->makeForm($this->masjid);
    }

    // ---------------------------------------------------------------- helpers

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    /** The festival's shape by default: $15 per attendee, card and codes on. */
    private function makeForm(Masjid $masjid, ?array $settings = null): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => [
                'sections' => [
                    ['id' => 'contact', 'title' => 'You', 'fields' => [
                        ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                    ]],
                    ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'fields' => [
                        ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ]],
                ],
            ],
            'settings' => $settings ?? [
                'identity' => ['name' => 'fullName'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true, 'staffCodes' => true, 'allowFeeCoverage' => true],
            ],
            'is_active' => true,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    /** A row as the submit writes it, before any settlement: two attendees, a $30.00 snapshot, no money leg. */
    private function freshRow(array $money = [], ?Form $form = null, array $attributes = []): FormResponse
    {
        $form ??= $this->form;

        $row = new FormResponse(array_merge([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['fullName' => 'Amal Yusuf', 'attendees' => [['attendeeName' => 'Amal'], ['attendeeName' => 'Zaid']]],
            'respondent_name' => 'Amal Yusuf',
            'entry_count' => 2,
            'amount_due' => 30,
            'status' => 'new',
            'submitted_at' => now(),
        ], $attributes));

        $row->forceFill(array_merge(['amount_due_minor' => 3000, 'currency' => 'usd'], $money))->save();

        return $row->fresh();
    }

    /** An online row waiting on the webhook. */
    private function onlineUnpaidRow(array $money = []): FormResponse
    {
        return $this->freshRow(array_merge([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'total_minor' => 3000,
            'idempotency_key' => 'form_response_' . uniqid(),
            'stripe_checkout_session_id' => 'cs_test_' . uniqid(),
        ], $money));
    }

    /** No money leg and no cents snapshot: every row before the festival, and the Wix fallback's. */
    private function noMoneyLegRow(?Form $form = null, array $attributes = []): FormResponse
    {
        return $this->freshRow(['amount_due_minor' => null, 'currency' => null], $form, $attributes);
    }

    // ------------------------------------------------------------ Form helpers

    #[Test]
    public function a_payment_switch_does_nothing_on_a_form_with_no_price(): void
    {
        $on = ['online' => true, 'staffCodes' => true, 'allowFeeCoverage' => true];

        $free = $this->makeForm($this->masjid, ['payment' => $on]);
        $zero = $this->makeForm($this->masjid, ['fee' => ['amount' => 0], 'payment' => $on]);

        foreach ([$free, $zero] as $form) {
            $this->assertFalse($form->chargesFee());
            $this->assertFalse($form->takesOnlinePayment(), 'with nothing to charge there is nothing to send to Stripe');
            $this->assertFalse($form->takesStaffCodes(), 'cash for $0 is not a settlement');
            $this->assertFalse($form->allowsFeeCoverage());
        }

        // A price in ANY tier, whatever the date, is a form that charges.
        $tiered = $this->makeForm($this->masjid, [
            'fee' => ['tiers' => [['amount' => 0, 'until' => '2020-01-01'], ['amount' => 20]]],
            'payment' => $on,
        ]);

        $this->assertTrue($tiered->chargesFee());
        $this->assertTrue($tiered->takesOnlinePayment());
        $this->assertTrue($tiered->takesStaffCodes());
        $this->assertTrue($tiered->allowsFeeCoverage());
    }

    #[Test]
    public function payment_switches_read_the_way_the_form_door_coerces_them(): void
    {
        foreach ([true, 1, '1', 'true', 'on'] as $on) {
            $form = $this->makeForm($this->masjid, ['fee' => ['amount' => 15], 'payment' => ['online' => $on]]);
            $this->assertTrue($form->takesOnlinePayment(), var_export($on, true));
        }

        foreach ([false, 0, '0', 'false', '', null, 'maybe', ['nested']] as $off) {
            $form = $this->makeForm($this->masjid, ['fee' => ['amount' => 15], 'payment' => ['online' => $off]]);
            $this->assertFalse($form->takesOnlinePayment(), var_export($off, true));
        }

        $this->assertFalse(
            $this->makeForm($this->masjid, ['fee' => ['amount' => 15], 'payment' => 'yes'])->takesOnlinePayment(),
            'payment must be a map of switches'
        );
    }

    #[Test]
    public function fee_cover_is_offered_only_alongside_card_payment(): void
    {
        $cashOnly = $this->makeForm($this->masjid, [
            'fee' => ['amount' => 15],
            'payment' => ['staffCodes' => true, 'allowFeeCoverage' => true],
        ]);

        $this->assertTrue($cashOnly->takesStaffCodes());
        $this->assertFalse($cashOnly->allowsFeeCoverage(), 'cash has no card fee to cover');
    }

    #[Test]
    public function the_gate_ignores_the_closing_date_but_not_inactive_or_full(): void
    {
        $closed = $this->makeForm($this->masjid);
        $closed->update(['closes_at' => now()->subHour()]);

        $this->assertFalse($closed->acceptsSubmissions(), 'online registration has closed');
        $this->assertTrue($closed->acceptsStaffEntry(), 'walk-ups arrive after it closes');

        $inactive = $this->makeForm($this->masjid);
        $inactive->update(['is_active' => false]);
        $this->assertFalse($inactive->acceptsStaffEntry());

        $full = $this->makeForm($this->masjid);
        $full->update(['capacity' => 1]);
        $this->freshRow([], $full); // the created hook counts it

        $this->assertFalse($full->fresh()->acceptsStaffEntry());
    }

    #[Test]
    public function only_a_chat_whatsapp_com_invite_is_ever_handed_out(): void
    {
        $url = fn ($value) => $this->makeForm($this->masjid, ['fee' => ['amount' => 15], 'whatsappUrl' => $value])->whatsappUrl();

        $this->assertSame('https://chat.whatsapp.com/AbCdEf0123456789XyZ', $url('https://chat.whatsapp.com/AbCdEf0123456789XyZ'));

        foreach ([
            'http://chat.whatsapp.com/AbCdEf0123456789XyZ',
            'https://chat.whatsapp.com.evil.test/AbCdEf0123456789XyZ',
            'https://evil.test/https://chat.whatsapp.com/AbCdEf0123456789XyZ',
            'https://chat.whatsapp.com/AbCdEf0123456789XyZ/../../x',
            "https://chat.whatsapp.com/AbCdEf0123456789XyZ\n",
            'javascript:alert(1)//https://chat.whatsapp.com/AbCdEf0123456789XyZ',
            'https://chat.whatsapp.com/short',
            null,
            42,
        ] as $bad) {
            $this->assertNull($url($bad), var_export($bad, true));
        }
    }

    // --------------------------------------------------------------- the webhook

    #[Test]
    public function the_webhook_marks_an_online_row_paid_exactly_once(): void
    {
        $row = $this->onlineUnpaidRow();

        $this->assertTrue($row->markPaid('pi_first'));
        $this->assertTrue($row->isPaid());
        $this->assertSame('pi_first', $row->stripe_payment_intent_id);
        $paidAt = $row->paid_at->toDateTimeString();

        $this->travel(5)->minutes();

        // payment_intent.succeeded after checkout.session.completed: no second
        // transition, so no second receipt, and paid_at keeps when money landed.
        $this->assertFalse($row->markPaid('pi_second'));

        $stored = $row->fresh();
        $this->assertSame($paidAt, $stored->paid_at->toDateTimeString());
        $this->assertSame('pi_first', $stored->stripe_payment_intent_id);
        $this->assertTrue($stored->isSettled());
    }

    #[Test]
    public function a_card_payment_never_flips_a_row_that_is_not_waiting_for_one(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());
        $cash = $this->freshRow();
        $cash->settleCash($code);

        $this->assertFalse($cash->markPaid('pi_stray'));

        $stored = $cash->fresh();
        $this->assertSame(FormResponse::METHOD_CASH, $stored->payment_method);
        $this->assertSame($code->id, $stored->staff_code_id);
        $this->assertSame('pi_stray', $stored->stripe_payment_intent_id, 'recorded, so the organisation can find it and refund it');

        $legacy = $this->noMoneyLegRow();
        $this->assertFalse($legacy->markPaid('pi_stray'));
        $this->assertNull($legacy->fresh()->payment_status);
    }

    // ------------------------------------------------------------- staff codes

    #[Test]
    public function a_staff_code_settles_the_row_as_cash_its_holder_owes(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());
        $row = $this->freshRow();

        $this->assertTrue($row->settleCash($code));

        $this->assertSame(FormResponse::METHOD_CASH, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
        $this->assertNotNull($row->paid_at);
        $this->assertSame($code->id, $row->staff_code_id);
        $this->assertSame(3000, $row->amount_due_minor);
        $this->assertSame(0, $row->fee_covered_minor);
        $this->assertSame(3000, $row->total_minor, 'the holder hands in the list price');
        $this->assertSame('30.00', $row->fresh()->amount_due, 'the display amount is never overwritten');
        $this->assertNull($row->marked_paid_by_user_id, 'a code holder is staff_code_id, not a login');
        $this->assertTrue($row->isSettled());

        $this->assertSame(1, $code->use_count, 'the caller\'s copy of the code is brought up to date');
        $this->assertSame(1, $code->fresh()->use_count);
        $this->assertNotNull($code->fresh()->last_used_at);

        // A replay of the same entry: the holder never owes twice.
        $this->assertFalse($row->settleCash($code));
        $this->assertSame(1, $code->fresh()->use_count);
    }

    #[Test]
    public function a_revoked_or_expired_code_settles_nothing(): void
    {
        [$revoked] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());
        $revoked->revoke();

        [$expired] = FormStaffCode::issue($this->form, 'Sara Ahmed', now()->subMinute());

        // Looked up while live, revoked a moment later on another screen: the
        // settlement re-reads the code under its lock and sees the revocation.
        [$raced, $plain] = FormStaffCode::issue($this->form, 'Yusuf Omar', now()->addDay());
        $lookedUp = FormStaffCode::findUsable($this->masjid->id, $this->form->id, $plain);
        $this->assertNotNull($lookedUp);
        $raced->revoke();

        foreach ([$revoked, $expired, $lookedUp] as $code) {
            $row = $this->freshRow();

            $this->assertFalse($row->settleCash($code));
            $this->assertNull($row->fresh()->payment_status);
            $this->assertNull($row->fresh()->staff_code_id);
            $this->assertSame(0, $code->fresh()->use_count);
        }
    }

    #[Test]
    public function cash_for_nothing_is_refused_loudly(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());

        // A fee form whose total came to $0: an empty attendee list sent past the
        // renderer. It must never become a free "paid" row.
        $row = $this->freshRow(['amount_due_minor' => 0], null, [
            'amount_due' => 0,
            'data' => ['fullName' => 'Amal Yusuf', 'attendees' => []],
        ]);

        try {
            $row->settleCash($code);
            $this->fail('A $0 row was settled as cash.');
        } catch (LogicException) {
            // Loud, not a quiet no-op.
        }

        $this->assertNull($row->fresh()->payment_status);
        $this->assertSame(0, $code->fresh()->use_count);
        $this->assertFalse($row->fresh()->isSettled(), 'and a $0 row on a fee form is not settled either');
    }

    #[Test]
    public function a_staff_code_never_converts_an_online_row(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());
        $row = $this->onlineUnpaidRow();

        $this->assertFalse($row->settleCash($code), 'its card page may still be open; an admin closes it first (settleCashBy)');

        $stored = $row->fresh();
        $this->assertSame(FormResponse::METHOD_ONLINE, $stored->payment_method);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $stored->payment_status);
        $this->assertSame(0, $code->fresh()->use_count);
    }

    // ----------------------------------------------------------- by an admin

    #[Test]
    public function an_admin_taking_cash_settles_an_unpaid_card_row_at_the_list_price(): void
    {
        $admin = $this->makeAdmin();
        $colleague = $this->makeAdmin();
        $row = $this->onlineUnpaidRow(['fee_covered_minor' => 117, 'total_minor' => 3117]);

        $this->assertTrue($row->settleCashBy($admin));

        $this->assertSame(FormResponse::METHOD_CASH, $row->payment_method);
        $this->assertTrue($row->isPaid());
        $this->assertSame(0, $row->fee_covered_minor, 'cash pays no card fee');
        $this->assertSame(3000, $row->total_minor);
        $this->assertSame($admin->id, $row->marked_paid_by_user_id);
        $this->assertNull($row->staff_code_id);

        // The abandoned card page completing afterwards is recorded, never flips it back.
        $this->assertFalse($row->markPaid('pi_late'));
        $this->assertSame(FormResponse::METHOD_CASH, $row->fresh()->payment_method);

        // A colleague's press changes nothing and never rewrites who took it.
        $this->assertFalse($row->settleCashBy($colleague));
        $this->assertSame($admin->id, $row->fresh()->marked_paid_by_user_id);
    }

    #[Test]
    public function a_wix_payment_is_marked_external_from_the_legacy_amount(): void
    {
        $admin = $this->makeAdmin();
        $colleague = $this->makeAdmin();

        // The fallback row: a fee form, no money leg, and no cents snapshot —
        // only the decimal amount the legacy path stored.
        $row = $this->noMoneyLegRow(null, ['amount_due' => 19.99]);

        $this->assertFalse($row->isSettled(), 'NULL payment columns on a fee form are NOT free');

        $this->assertTrue($row->markExternalPaid($admin));

        $this->assertSame(FormResponse::METHOD_EXTERNAL, $row->payment_method);
        $this->assertTrue($row->isPaid());
        $this->assertSame(1999, $row->amount_due_minor);
        $this->assertSame(1999, $row->total_minor);
        $this->assertSame('usd', $row->currency);
        $this->assertSame($admin->id, $row->marked_paid_by_user_id);
        $this->assertTrue($row->isSettled());

        $this->assertFalse($row->markExternalPaid($colleague));
        $this->assertSame($admin->id, $row->fresh()->marked_paid_by_user_id);
    }

    #[Test]
    public function what_a_row_owes_is_read_from_the_snapshot_or_the_decimal_never_a_float(): void
    {
        // 19.99 * 100 as a float is 1998.9999…, which (int) makes 1998.
        foreach (['19.99' => 1999, '0.10' => 10, '1234.5' => 123450, '30' => 3000, '0' => 0] as $amount => $minor) {
            $this->assertSame($minor, $this->noMoneyLegRow(null, ['amount_due' => $amount])->owedMinor(), (string) $amount);
        }

        $this->assertSame(4500, $this->freshRow(['amount_due_minor' => 4500])->owedMinor(), 'the cents snapshot wins');
        $this->assertNull($this->noMoneyLegRow(null, ['amount_due' => null])->owedMinor(), 'a form that charged nothing');
    }

    // ------------------------------------------------------ settled, collected

    #[Test]
    public function settled_means_paid_or_nothing_was_ever_owed(): void
    {
        $free = $this->makeForm($this->masjid, ['identity' => ['name' => 'fullName']]);

        $this->assertTrue($this->noMoneyLegRow($free, ['amount_due' => null])->isSettled(), 'a free form: nothing was owed');
        $this->assertFalse($this->noMoneyLegRow()->isSettled(), 'a fee form with no payment recorded (the Wix fallback)');
        $this->assertFalse($this->onlineUnpaidRow()->isSettled());

        $paid = $this->onlineUnpaidRow();
        $paid->markPaid('pi_1');
        $this->assertTrue($paid->isSettled());

        // A $0 fee asks for nothing.
        $zero = $this->makeForm($this->masjid, ['fee' => ['amount' => 0]]);
        $this->assertTrue($this->noMoneyLegRow($zero, ['amount_due' => 0])->isSettled());

        // Removing the price later does not wave through a row that owed money.
        $owing = $this->noMoneyLegRow();
        $this->form->update(['settings' => ['identity' => ['name' => 'fullName']]]);
        $this->assertFalse($owing->fresh()->isSettled());
    }

    #[Test]
    public function bracelets_are_recorded_by_the_first_press_only(): void
    {
        $first = $this->makeAdmin();
        $second = $this->makeAdmin();

        $row = $this->onlineUnpaidRow();
        $row->markPaid('pi_1');

        $this->assertTrue($row->markCollected($first));
        $this->assertTrue($row->isCollected());
        $this->assertSame($first->id, $row->collected_by_user_id);

        $this->assertFalse($row->markCollected($second), 'the next table pressing too');
        $this->assertSame($first->id, $row->fresh()->collected_by_user_id);

        $this->assertTrue($row->uncollect());
        $this->assertNull($row->collected_at);
        $this->assertNull($row->collected_by_user_id);
        $this->assertFalse($row->uncollect(), 'nothing left to undo');

        $this->assertTrue($row->markCollected($second));
        $this->assertSame($second->id, $row->fresh()->collected_by_user_id);
    }

    #[Test]
    public function an_unsettled_row_is_never_marked_collected(): void
    {
        $admin = $this->makeAdmin();

        foreach ([$this->onlineUnpaidRow(), $this->noMoneyLegRow()] as $row) {
            try {
                $row->markCollected($admin);
                $this->fail('An unpaid registration was handed its bracelets.');
            } catch (LogicException) {
                // The backstop behind the controller's 'Not paid yet'.
            }

            $this->assertNull($row->fresh()->collected_at);
        }
    }

    #[Test]
    public function re_triaging_a_money_row_records_who_and_when(): void
    {
        $admin = $this->makeAdmin();
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());
        $cash = $this->freshRow();
        $cash->settleCash($code);

        $cash->fill(['status' => FormResponse::STATUS_CANCELLED]);
        $this->assertTrue($cash->stampStatusChange($admin));
        $cash->save();

        $stored = $cash->fresh();
        $this->assertSame('cancelled', $stored->status);
        $this->assertSame($admin->id, $stored->status_changed_by_user_id);
        $this->assertNotNull($stored->status_changed_at);
        $this->assertSame(FormResponse::PAYMENT_PAID, $stored->payment_status, 'cancelling is triage; the cash was still taken');

        // Saving the same status again is not a change.
        $stored->fill(['status' => 'cancelled', 'admin_notes' => 'refunded at the gate']);
        $this->assertFalse($stored->stampStatusChange($this->makeAdmin()));

        // A row with no money leg behaves exactly as it always has.
        $legacy = $this->noMoneyLegRow();
        $legacy->fill(['status' => 'confirmed']);
        $this->assertFalse($legacy->stampStatusChange($admin));
        $legacy->save();
        $this->assertNull($legacy->fresh()->status_changed_by_user_id);
    }

    // -------------------------------------------------------- replay and uuid

    #[Test]
    public function the_replay_digest_ignores_key_order_but_not_the_answers(): void
    {
        $answers = ['fullName' => 'Amal Yusuf', 'attendees' => [['attendeeName' => 'Amal', 'age' => 9], ['attendeeName' => 'Zaid']]];
        $reordered = ['attendees' => [['age' => 9, 'attendeeName' => 'Amal'], ['attendeeName' => 'Zaid']], 'fullName' => 'Amal Yusuf'];
        $fewer = ['fullName' => 'Amal Yusuf', 'attendees' => [['attendeeName' => 'Amal', 'age' => 9]]];
        $swapped = ['fullName' => 'Amal Yusuf', 'attendees' => [['attendeeName' => 'Zaid'], ['attendeeName' => 'Amal', 'age' => 9]]];

        $hash = FormResponse::payloadHash($answers);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, FormResponse::payloadHash($reordered));
        $this->assertNotSame($hash, FormResponse::payloadHash($fewer), 'one attendee fewer is a different registration');
        $this->assertNotSame($hash, FormResponse::payloadHash($swapped), 'the rows are a list; their order is part of the answer');
        $this->assertNotSame(hash('sha256', json_encode($answers)), $hash, 'keyed, because it is derived from personal data');

        $row = $this->freshRow(['client_submission_key' => 'render-1', 'client_payload_hash' => $hash]);

        $this->assertTrue($row->matchesPayload($reordered));
        $this->assertFalse($row->matchesPayload($fewer));
        $this->assertFalse($this->freshRow()->matchesPayload($answers), 'a row with no digest matches nothing');
        $this->assertArrayNotHasKey('client_payload_hash', $row->toArray());
    }

    #[Test]
    public function a_uuid_resolves_only_inside_its_own_masjid(): void
    {
        $row = $this->onlineUnpaidRow();
        $other = $this->makeMasjid();

        $this->assertSame($row->id, FormResponse::findByUuidForMasjid($row->uuid, $this->masjid->id)?->id);
        $this->assertNull(FormResponse::findByUuidForMasjid($row->uuid, $other->id));
        $this->assertNull(FormResponse::findByUuidForMasjid('not-a-uuid', $this->masjid->id));
    }
}
