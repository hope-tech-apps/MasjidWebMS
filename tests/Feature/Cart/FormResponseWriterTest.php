<?php

namespace Tests\Feature\Cart;

use App\Models\Form;
use App\Models\FormResponse;
use App\Services\Forms\FormResponseWriter;
use App\Support\FormPayment;
use App\Support\FormReservations;
use App\Support\FormSchema;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesRamadanGivingForms;
use Tests\TestCase;

/**
 * The row write the universal cart shares with the public submit door
 * (App\Services\Forms\FormResponseWriter).
 *
 * The cart calls it AFTER the shopper has paid, so what these pin is chiefly what it
 * does NOT do: it re-checks no gate (a payment that lands after a close, or at capacity,
 * is still recorded), opens no Stripe page, sends nothing, and leaves the row UNPAID for
 * FormResponse::markPaid() to settle. That the door writes the same row it always did is
 * pinned by the existing FormSubmissionTest, FormPaymentCheckoutTest, FormStaffCodeTest
 * and FormDateReservationTest, which this change leaves untouched.
 */
class FormResponseWriterTest extends TestCase
{
    use BuildsBaskets;
    use MakesRamadanGivingForms;
    use RefreshDatabase;

    private const KEY = 'cart:item:7';

    /** A ticket form whose identity is read from the first attendee. */
    private function festivalForm(array $overrides = []): Form
    {
        return $this->ticketForm($this->org(), array_merge([
            'settings' => [
                'identity' => ['name' => 'tickets.0.attendeeName'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
                'payment' => ['online' => true, 'officePayment' => true],
            ],
        ], $overrides));
    }

    /**
     * What the cart does around the write: lock the form, clean the answers, quote them,
     * write under one transaction. The write is the only thing under test.
     */
    private function writeAs(
        Form $form,
        array $answers,
        string $leg = FormResponseWriter::LEG_ONLINE,
        ?string $key = self::KEY,
        ?string $reserveOn = null,
        array $origin = [],
    ): FormResponse {
        return DB::transaction(function () use ($form, $answers, $leg, $key, $reserveOn, $origin): FormResponse {
            $locked = Form::whereKey($form->id)->lockForUpdate()->firstOrFail();
            $schema = FormSchema::for($locked);
            $clean = $schema->only($answers);
            $quote = FormPayment::quote($locked, $clean, false, $leg === FormResponseWriter::LEG_ONLINE);

            return (new FormResponseWriter)->write(
                $locked,
                $schema,
                $clean,
                $leg,
                $quote,
                $origin,
                $key,
                ['data' => $clean],
                $reserveOn,
            );
        });
    }

    #[Test]
    public function a_staff_cash_row_without_audit_fields_keeps_the_quoted_decimal_despite_a_stale_schema(): void
    {
        $form = $this->festivalForm();
        $schema = FormSchema::for($form);
        $clean = $schema->only($this->twoTickets());
        $this->assertSame(30.0, $schema->amountDue($clean));

        $settings = $form->settings;
        $settings['fee']['amount'] = 17.35;
        Form::whereKey($form->id)->update(['settings' => json_encode($settings)]);

        $row = DB::transaction(function () use ($form, $schema, $clean): FormResponse {
            $locked = Form::whereKey($form->id)->lockForUpdate()->firstOrFail();
            $quote = FormPayment::quote($locked, $clean, false, false);
            $this->assertSame(3470, $quote['amount_due_minor']);

            return (new FormResponseWriter)->write($locked, $schema, $clean, FormResponseWriter::LEG_STAFF, $quote);
        })->fresh();

        $this->assertSame(3470, $row->amount_due_minor);
        $this->assertNull($row->staff_payment_method);
        $this->assertSame('34.70', $row->amount_due);
    }

    #[Test]
    public function it_writes_an_unpaid_card_row_from_the_price_snapshot(): void
    {
        $form = $this->festivalForm();

        $row = $this->writeAs($form, $this->twoTickets());

        $row = $row->fresh();
        $this->assertSame(FormResponse::METHOD_ONLINE, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->payment_status);
        $this->assertNull($row->paid_at);
        $this->assertSame(3000, $row->amount_due_minor);
        $this->assertSame(0, $row->fee_covered_minor);
        $this->assertSame(3000, $row->total_minor);
        $this->assertSame(1500, $row->unit_price_minor);
        $this->assertSame(2, $row->price_quantity);
        $this->assertSame(2, $row->entry_count);
        $this->assertSame('new', $row->status);
        $this->assertSame('A', $row->respondent_name);
        $this->assertSame($form->masjid_id, $row->masjid_id);
        $this->assertSame('B', $row->data['tickets'][1]['attendeeName']);
        $this->assertNotEmpty($row->uuid);
        $this->assertSame(1, $form->fresh()->response_count);
    }

    #[Test]
    public function it_stores_the_replay_key_and_a_hash_a_replay_can_be_compared_with(): void
    {
        $form = $this->festivalForm();

        $row = $this->writeAs($form, $this->twoTickets())->fresh();

        $this->assertSame(self::KEY, $row->client_submission_key);
        $this->assertTrue($row->matchesPayload(['data' => $this->twoTickets()]));
        $this->assertFalse($row->matchesPayload(['data' => ['tickets' => [['attendeeName' => 'A']]]]));
    }

    #[Test]
    public function it_never_re_checks_a_gate_so_a_late_payment_is_still_recorded(): void
    {
        $closed = $this->festivalForm(['closes_at' => now()->subDay()]);
        $full = $this->festivalForm(['capacity' => 1]);
        Form::whereKey($full->id)->update(['response_count' => 1]);
        $off = $this->festivalForm(['is_active' => false]);

        foreach ([$closed, $full, $off] as $form) {
            $this->assertFalse($form->fresh()->acceptsSubmissions(), 'premise: the door would refuse this form');

            $row = $this->writeAs($form->fresh(), $this->twoTickets());

            $this->assertTrue($row->exists);
            $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->fresh()->payment_status);
        }

        // The counter still moves with the row, under the lock: one past its capacity.
        $this->assertSame(2, $full->fresh()->response_count);
    }

    #[Test]
    public function it_opens_no_stripe_page_and_sends_nothing_while_the_row_is_unpaid(): void
    {
        Mail::fake();
        Notification::fake();
        $form = $this->festivalForm();

        $row = $this->writeAs($form, $this->twoTickets())->fresh();

        $this->assertNull($row->stripe_checkout_session_id);
        $this->assertNull($row->stripe_payment_intent_id);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_replayed_key_meets_the_unique_index_and_earlier_finds_the_first_row(): void
    {
        $form = $this->festivalForm();
        $first = $this->writeAs($form, $this->twoTickets());

        $found = (new FormResponseWriter)->earlier((int) $form->id, self::KEY);
        $this->assertSame($first->id, $found?->id);
        $this->assertNull((new FormResponseWriter)->earlier((int) $form->id, 'cart:item:8'));

        try {
            $this->writeAs($form, $this->twoTickets());
            $this->fail('A second write under the same key must be refused by the unique index.');
        } catch (UniqueConstraintViolationException) {
            // The backstop a replayed webhook meets when it skips earlier().
        }

        $this->assertSame(1, FormResponse::where('form_id', $form->id)->count());
        $this->assertSame(1, $form->fresh()->response_count);
    }

    #[Test]
    public function the_same_key_on_another_form_is_a_different_row(): void
    {
        $a = $this->writeAs($this->festivalForm(), $this->twoTickets());
        $b = $this->writeAs($this->festivalForm(), $this->twoTickets());

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function the_row_it_writes_is_the_one_mark_paid_settles_once(): void
    {
        $form = $this->festivalForm();
        $row = $this->writeAs($form, $this->twoTickets());

        $this->assertTrue($row->markPaid('pi_cart_1'), 'the unpaid -> paid transition is the caller\'s signal to notify');
        $paid = $row->fresh();
        $this->assertSame(FormResponse::PAYMENT_PAID, $paid->payment_status);
        $this->assertSame('pi_cart_1', $paid->stripe_payment_intent_id);
        $this->assertNotNull($paid->paid_at);
        $this->assertNull($paid->paid_via);

        // A redelivered event settles nothing twice, so the caller sends nothing twice.
        $this->assertFalse($paid->markPaid('pi_cart_1'));
    }

    #[Test]
    public function the_audit_columns_are_empty_for_a_caller_with_no_request(): void
    {
        $row = $this->writeAs($this->festivalForm(), $this->twoTickets())->fresh();

        $this->assertNull($row->device_id);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->user_agent);

        $with = $this->writeAs($this->festivalForm(), $this->twoTickets(), origin: [
            'device_id' => 'dev-1', 'ip_address' => '203.0.113.9', 'user_agent' => 'Door/1.0',
        ])->fresh();

        $this->assertSame('dev-1', $with->device_id);
        $this->assertSame('203.0.113.9', $with->ip_address);
        $this->assertSame('Door/1.0', $with->user_agent);
    }

    #[Test]
    public function an_unkeyed_write_stores_no_key_and_no_hash(): void
    {
        $row = $this->writeAs($this->festivalForm(), $this->twoTickets(), key: null)->fresh();

        $this->assertNull($row->client_submission_key);
        $this->assertNull($row->client_payload_hash);
    }

    #[Test]
    public function the_office_leg_owes_the_list_price_with_no_card_fee(): void
    {
        $row = $this->writeAs($this->festivalForm(), $this->twoTickets(), FormResponseWriter::LEG_OFFICE)->fresh();

        $this->assertSame(FormResponse::METHOD_OFFICE, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->payment_status);
        $this->assertSame(3000, $row->amount_due_minor);
        $this->assertSame(0, $row->fee_covered_minor);
        $this->assertSame(3000, $row->total_minor);
    }

    #[Test]
    public function the_staff_leg_keeps_the_snapshot_but_leaves_settling_to_the_door(): void
    {
        $row = $this->writeAs($this->festivalForm(), $this->twoTickets(), FormResponseWriter::LEG_STAFF)->fresh();

        $this->assertNull($row->payment_method);
        $this->assertNull($row->payment_status);
        $this->assertSame(3000, $row->amount_due_minor);
        $this->assertSame(1500, $row->unit_price_minor);
        $this->assertSame(2, $row->price_quantity);
        $this->assertNull($row->total_minor);
    }

    #[Test]
    public function a_money_leg_without_a_quote_is_refused_rather_than_written_unpriced(): void
    {
        $form = $this->festivalForm();

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($form) {
            $locked = Form::whereKey($form->id)->lockForUpdate()->firstOrFail();
            $schema = FormSchema::for($locked);

            (new FormResponseWriter)->write($locked, $schema, $schema->only($this->twoTickets()), FormResponseWriter::LEG_ONLINE, null);
        });
    }

    #[Test]
    public function a_reserved_date_is_held_until_the_card_page_would_lapse_and_never_for_the_office(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-31 17:00:00', 'UTC'));

        try {
            $form = $this->makeIftarForm($this->org(), ['2027-02-10', '2027-02-11']);
            $answers = fn (string $date) => [
                'fullName' => 'Jane Giver', 'email' => 'jane@example.test',
                'sponsorship' => 'quarter', 'iftar_date' => $date,
            ];

            $card = $this->writeAs($form, $answers('2027-02-10'), reserveOn: '2027-02-10');
            $office = $this->writeAs($form, $answers('2027-02-11'), FormResponseWriter::LEG_OFFICE, 'cart:item:9', '2027-02-11');

            $held = FormReservations::of($card);
            $this->assertTrue($held->isHolding());
            $this->assertTrue(FormReservations::cardHoldUntil(now())->equalTo($held->held_until));

            $this->assertTrue(FormReservations::of($office)->isHolding());
            $this->assertNull(FormReservations::of($office)->held_until);
        } finally {
            Carbon::setTestNow();
        }
    }
}
